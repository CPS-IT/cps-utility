<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Command;

use Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver;
use Cpsit\CpsUtility\Fixture\SqlStatementSplitter;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\ConnectionException as DbalConnectionException;
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
                // A lost/dropped connection (e.g. wait_timeout, DB restart,
                // max_allowed_packet kill, access denied, host unreachable)
                // is fundamentally different from bad fixture SQL: DBAL's
                // handleDriverException() closes the connection on
                // ConnectionLost, which resets the internal transaction
                // nesting level to 0 — calling rollBack() unconditionally at
                // that point would itself throw NoActiveTransaction,
                // escaping this catch block entirely and surfacing a raw
                // stack trace instead of a clean error. Guard the rollback,
                // and treat connection-level failures as a hard
                // Command::FAILURE (matching the pre-flight probe above)
                // rather than silently marking every remaining file as
                // "skipped" and returning Command::SUCCESS.
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                // Doctrine\DBAL\Exception\ConnectionException is what every
                // per-platform ExceptionConverter actually throws for real
                // driver-detected connection failures (e.g. MySQL codes
                // 1044/1045 access denied, 1046/1049 unknown database, 2002
                // can't connect, 2005 unknown host — see
                // Driver/API/MySQL/ExceptionConverter.php). Its subclass
                // Doctrine\DBAL\Exception\ConnectionLost (MySQL 2006/4031,
                // "server has gone away") is covered for free via
                // inheritance. This is deliberately NOT the top-level,
                // legacy Doctrine\DBAL\ConnectionException class, which is
                // an unrelated sibling type used only for Connection-API
                // misuse (NoActiveTransaction, CommitFailedRollbackOnly,
                // SavepointsNotSupported), not real connection failures.
                if ($e instanceof DbalConnectionException) {
                    $this->io->error('Database connection error: ' . $e->getMessage());
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
