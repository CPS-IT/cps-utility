<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Command;

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
    /**
     * Maps TYPO3 application contexts to fixture subdirectory names.
     * Only contexts listed here will trigger a fixture import.
     */
    public const array CONTEXT_SUBDIRECTORY_MAP = [
        'Production' => 'production',
        'Production/Preview' => 'staging',
        'Production/Staging' => 'staging',
        'Development' => 'dev',
        'Development/Local' => 'dev',
        'Testing' => 'dev',
    ];

    private SymfonyStyle $io;

    public function __construct(
        private readonly ConnectionPool $connectionPool
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
                . 'Override with --directory, which accepts absolute paths or EXT: notation.'
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

        if ($contextKey === 'Production') {
            if (!$forceProduction) {
                $this->io->warning('Fixture import is disabled in Production environment. Use --production to override.');
                return Command::SUCCESS;
            }
            $this->io->note('Forcing fixture import in Production environment.');
        }

        $directoryOption = $input->getOption('directory');
        $basePath = $this->resolveBasePath($directoryOption);

        if ($basePath === null) {
            $this->io->error(sprintf(
                'Cannot resolve fixture directory "%s". '
                . 'For EXT: paths use the extension key (underscores, not hyphens) and ensure the extension is loaded.',
                $directoryOption
            ));
            return Command::FAILURE;
        }

        $fixtureDirectory = $this->resolveFixtureDirectory($basePath, $contextKey);

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

        $this->io->info(sprintf('Importing %d fixture file(s) from "%s"', count($fixtureFiles), $fixtureDirectory));

        $importedCount = 0;
        $skippedCount = 0;

        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

            foreach ($fixtureFiles as $filename) {
                $filePath = $fixtureDirectory . '/' . $filename;
                $sql = file_get_contents($filePath);

                if ($sql === false || $sql === '') {
                    $this->io->warning(sprintf('Skipping empty or unreadable file: %s', $filename));
                    $skippedCount++;
                    continue;
                }

                try {
                    foreach ($this->splitStatements($sql) as $statement) {
                        $connection->executeStatement($statement);
                    }
                    $this->io->writeln(sprintf('  Imported: %s', $filename));
                    $importedCount++;
                } catch (\Exception $e) {
                    $this->io->error(sprintf('Failed to import "%s": %s', $filename, $e->getMessage()));
                    $skippedCount++;
                }
            }
        } catch (DbalException $e) {
            $this->io->error('Database connection error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->io->success(sprintf(
            'Imported %d fixture file(s). Skipped %d.',
            $importedCount,
            $skippedCount
        ));

        return Command::SUCCESS;
    }

    /**
     * Splits a SQL file into individual executable statements.
     * Strips single-line comments first so semicolons inside comments
     * do not produce false statement boundaries.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $stripped = (string)preg_replace('/--[^\n]*/m', '', $sql);

        return array_values(array_filter(
            array_map('trim', explode(';', $stripped)),
            static fn(string $s): bool => $s !== ''
        ));
    }

    private function resolveFixtureDirectory(string $basePath, string $contextKey): ?string
    {
        if (!isset(self::CONTEXT_SUBDIRECTORY_MAP[$contextKey])) {
            return null;
        }

        return rtrim($basePath, '/') . '/' . self::CONTEXT_SUBDIRECTORY_MAP[$contextKey];
    }

    private function resolveBasePath(?string $directoryOption): ?string
    {
        if ($directoryOption === null) {
            return Environment::getVarPath() . '/fixtures';
        }

        if (str_starts_with($directoryOption, 'EXT:')) {
            $resolved = GeneralUtility::getFileAbsFileName($directoryOption);
            return $resolved !== '' ? rtrim($resolved, '/') : null;
        }

        if (str_starts_with($directoryOption, '/')) {
            return $directoryOption;
        }

        return Environment::getProjectPath() . '/' . ltrim($directoryOption, '/');
    }
}
