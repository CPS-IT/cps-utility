<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Command;

use Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver;
use Cpsit\CpsUtility\Fixture\SqlStatementSplitter;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'cpsit:import-fixtures',
    description: 'Import database fixtures from SQL files depending on current environment',
    aliases: ['cpsit:fixtures']
)]
final class ImportFixturesCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly FixtureDirectoryResolver $directoryResolver,
        private readonly SqlStatementSplitter $statementSplitter,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Import SQL fixture files for the current TYPO3 application context.' . PHP_EOL
                . PHP_EOL
                . 'The command resolves a context-specific subdirectory under the fixture base path:' . PHP_EOL
                . '  Development/Local → {base}/dev/' . PHP_EOL
                . '  Production/Staging → {base}/staging/' . PHP_EOL
                . '  Production         → {base}/production/' . PHP_EOL
                . PHP_EOL
                . 'Default base path: var/fixtures/ (never publicly accessible).' . PHP_EOL
                . 'Override with --directory, which accepts absolute paths or EXT: notation.' . PHP_EOL
                . 'Use --dry-run to list files and statement counts without executing anything.'
            )
            ->addOption(
                'directory',
                'd',
                InputOption::VALUE_REQUIRED,
                'Base directory for fixture files. Supports absolute paths and EXT: notation.',
            )
            ->addOption(
                'production',
                'p',
                InputOption::VALUE_NONE,
                'Allow fixture import in Production environment. Required when context is "Production".'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List fixture files and statement counts without executing anything.'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applicationContext = Environment::getContext();
        $contextKey = $applicationContext->__toString();
        $forceProduction = (bool)$input->getOption('production');
        $dryRun = (bool)$input->getOption('dry-run');

        // Guard: only the exact "Production" context is blocked without
        // --production. "Production/Preview" and "Production/Staging" are
        // intentionally left unguarded so staging/preview environments can
        // auto-seed their fixture set without any deploy-script changes.
        // This is a deliberate, reviewed design decision — see
        // Documentation/ImportFixturesCommand.md, "Production Guard" — not
        // an oversight. Do not widen this check to cover Production/* contexts.
        if ($contextKey === 'Production') {
            if (!$forceProduction) {
                $this->io->warning('Fixture import is disabled in Production environment. Use --production to override.');
                return Command::SUCCESS;
            }
            $this->io->note('Forcing fixture import in Production environment.');
        }

        $directoryOption = $input->getOption('directory');
        $basePath = $this->directoryResolver->resolveBasePath($directoryOption);

        if ($basePath === null) {
            $this->io->error(sprintf(
                'Cannot resolve fixture directory "%s". '
                . 'For EXT: paths use the extension key (underscores, not hyphens) and ensure the extension is loaded. '
                . 'For project-relative paths, ensure the path does not escape the project root.',
                $directoryOption
            ));
            return Command::FAILURE;
        }

        $fixtureDirectory = $this->directoryResolver->resolveFixtureDirectory($basePath, $applicationContext);

        if ($fixtureDirectory === null) {
            $this->io->info(sprintf(
                'No fixture directory configured for context "%s". Nothing to import.',
                $contextKey
            ));
            return Command::SUCCESS;
        }

        if (!is_dir($fixtureDirectory)) {
            $this->io->info(sprintf('Fixture directory "%s" does not exist. Nothing to import.', $fixtureDirectory));
            return Command::SUCCESS;
        }

        $fixtureFiles = GeneralUtility::getFilesInDir($fixtureDirectory, 'sql');

        // GeneralUtility::getFilesInDir() is declared array|string: on a
        // scandir() failure (e.g. the directory passed is_dir() but isn't
        // readable — wrong permissions in a deploy environment) it returns
        // an error string instead of an array. empty() doesn't catch a
        // non-empty string, so without this guard the sort() call below
        // would throw an uncaught TypeError instead of a clean error.
        if (!is_array($fixtureFiles)) {
            $this->io->error(sprintf('Cannot read fixture directory "%s".', $fixtureDirectory));
            return Command::FAILURE;
        }

        if (empty($fixtureFiles)) {
            $this->io->info(sprintf('No SQL fixture files found in "%s".', $fixtureDirectory));
            return Command::SUCCESS;
        }

        // Own the ordering contract explicitly instead of relying on
        // scandir()'s incidental default order (GeneralUtility::getFilesInDir()
        // returns an array keyed by md5 hash; sort() re-indexes numerically
        // and sorts the filenames themselves).
        sort($fixtureFiles);

        if ($dryRun) {
            return $this->executeDryRun($fixtureDirectory, $fixtureFiles);
        }

        return $this->executeImport($fixtureDirectory, $fixtureFiles);
    }

    /**
     * @param list<string> $fixtureFiles
     */
    private function executeDryRun(string $fixtureDirectory, array $fixtureFiles): int
    {
        $this->io->info(sprintf(
            'Dry run: %d fixture file(s) found in "%s". Nothing will be imported.',
            count($fixtureFiles),
            $fixtureDirectory
        ));

        foreach ($fixtureFiles as $filename) {
            $filePath = $fixtureDirectory . '/' . $filename;
            $sql = file_get_contents($filePath);

            if ($sql === false || $sql === '') {
                $this->io->writeln(sprintf('  %s: unreadable or empty, would be skipped', $filename));
                continue;
            }

            $statementCount = count($this->statementSplitter->split($sql));
            $this->io->writeln(sprintf('  %s: %d statement(s)', $filename, $statementCount));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $fixtureFiles
     */
    private function executeImport(string $fixtureDirectory, array $fixtureFiles): int
    {
        $this->io->info(sprintf('Importing %d fixture file(s) from "%s"', count($fixtureFiles), $fixtureDirectory));

        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
            // Force an eager connectivity check here, outside the per-file
            // loop below, so a genuine database outage propagates to
            // Command::FAILURE instead of being swallowed by the per-file
            // catch (which is only meant to catch bad fixture SQL, not a
            // dead connection).
            $connection->executeQuery('SELECT 1');
        } catch (DbalException $e) {
            $this->io->error('Database connection error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $importedCount = 0;
        $skippedCount = 0;

        foreach ($fixtureFiles as $filename) {
            $filePath = $fixtureDirectory . '/' . $filename;
            $sql = file_get_contents($filePath);

            if ($sql === false || $sql === '') {
                $this->io->warning(sprintf('Skipping empty or unreadable file: %s', $filename));
                $skippedCount++;
                continue;
            }

            try {
                $connection->beginTransaction();
                foreach ($this->statementSplitter->split($sql) as $statement) {
                    $connection->executeStatement($statement);
                }
                $connection->commit();
                $this->io->writeln(sprintf('  Imported: %s', $filename));
                $importedCount++;
            } catch (\Throwable $e) {
                // Classifying "is this a dead connection or bad fixture SQL"
                // by exception class turned out to be fundamentally
                // unreliable: DBAL's exception taxonomy differs between
                // major versions (top-level vs namespaced ConnectionException
                // in earlier iterations of this fix), and — more importantly
                // — per-statement privilege errors (MySQL 1142 "command
                // denied", 1143 column access denied) are raised on a
                // perfectly healthy connection but are NOT reliably
                // distinguishable from real connection failures by class
                // alone across drivers/versions. Instead, re-probe the
                // connection directly: if a trivial query still succeeds,
                // the connection is fine and this is a per-file SQL/grant
                // problem (skip the file, keep going); if the re-probe
                // itself fails, the connection is genuinely gone (hard
                // Command::FAILURE). This removes the DBAL-version/taxonomy
                // dependency entirely.
                $connectionAlive = true;
                try {
                    $connection->executeQuery('SELECT 1');
                } catch (\Throwable) {
                    $connectionAlive = false;
                }

                if (!$connectionAlive) {
                    $this->io->error('Database connection lost during import: ' . $e->getMessage());
                    return Command::FAILURE;
                }

                // The connection itself is fine, so roll back the failed
                // file's partial statements — but guard the rollback too:
                // isTransactionActive() only reports false once DBAL has
                // actually closed the connection (which didn't happen here,
                // since the re-probe above just proved it's alive), so this
                // guard is mostly defensive, and a failing rollback is
                // reported as a clean Command::FAILURE instead of escaping
                // this catch block as an unhandled exception.
                try {
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                    }
                } catch (\Throwable $rollbackError) {
                    $this->io->error(sprintf('Rollback failed for "%s": %s', $filename, $rollbackError->getMessage()));
                    return Command::FAILURE;
                }

                $this->io->error(sprintf('Failed to import "%s": %s', $filename, $e->getMessage()));
                $skippedCount++;
            }
        }

        $this->io->success(sprintf(
            'Imported %d fixture file(s). Skipped %d.',
            $importedCount,
            $skippedCount
        ));

        return Command::SUCCESS;
    }
}
