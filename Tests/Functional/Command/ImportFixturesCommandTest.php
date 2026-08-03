<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Tests\Functional\Command;

use Cpsit\CpsUtility\Command\ImportFixturesCommand;
use Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver;
use Cpsit\CpsUtility\Fixture\SqlStatementSplitter;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ImportFixturesCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/cps_utility',
    ];

    private string $fixtureBasePath;
    private ApplicationContext $originalContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContext = Environment::getContext();
        $this->fixtureBasePath = sys_get_temp_dir() . '/cps_utility_fixtures_' . uniqid('', true);
        // The functional test framework runs in the "Testing" context, which
        // FixtureDirectoryResolver::CONTEXT_SUBDIRECTORY_MAP maps to "dev".
        GeneralUtility::mkdir_deep($this->fixtureBasePath . '/dev');
    }

    protected function tearDown(): void
    {
        Environment::initialize(
            $this->originalContext,
            Environment::isCli(),
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX'
        );
        GeneralUtility::rmdir($this->fixtureBasePath, true);
        parent::tearDown();
    }

    private function switchContext(string $contextKey): void
    {
        Environment::initialize(
            new ApplicationContext($contextKey),
            Environment::isCli(),
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX'
        );
    }

    private function writeFixtureFile(string $subdirectory, string $filename, string $sql): void
    {
        GeneralUtility::mkdir_deep($this->fixtureBasePath . '/' . $subdirectory);
        file_put_contents($this->fixtureBasePath . '/' . $subdirectory . '/' . $filename, $sql);
    }

    private function getCommandTester(?ConnectionPool $connectionPool = null): CommandTester
    {
        $command = new ImportFixturesCommand(
            new FixtureDirectoryResolver(),
            new SqlStatementSplitter(),
            $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class)
        );

        return new CommandTester($command);
    }

    /**
     * SymfonyStyle's block formatter (used by ->info()/->warning()/etc.) wraps
     * long lines at its internal max line length, which can split an
     * asserted phrase across a line break with injected padding whitespace
     * (this is especially likely here since the fixture base path embeds a
     * long uniqid()). Collapsing all whitespace runs to a single space makes
     * the assertions robust to that wrapping without weakening what they
     * check for.
     */
    private function normalizedDisplay(CommandTester $tester): string
    {
        return (string)preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    public function testHappyPathImportsFixtureFile(): void
    {
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Imported 1 fixture file(s). Skipped 0.', $this->normalizedDisplay($tester));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $row = $connection->select(['username'], 'be_users', ['uid' => 9999])->fetchAssociative();
        self::assertNotFalse($row);
        self::assertSame('fixture_admin', $row['username']);
    }

    public function testMissingDirectoryReturnsSuccessWithInfoMessage(): void
    {
        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath . '/does-not-exist']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('does not exist. Nothing to import.', $this->normalizedDisplay($tester));
    }

    public function testEmptyDirectoryReturnsSuccessWithNothingImported(): void
    {
        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No SQL fixture files found', $this->normalizedDisplay($tester));
    }

    public function testFailingFileIsFullyRolledBackAndOtherFilesStillImport(): void
    {
        $this->writeFixtureFile('dev', '01_good.sql', "INSERT INTO be_users (uid, username) VALUES (9001, 'good_admin');");
        $this->writeFixtureFile(
            'dev',
            '02_bad.sql',
            "INSERT INTO be_users (uid, username) VALUES (9002, 'partial_admin');\n"
            . "INSERT INTO this_table_does_not_exist (foo) VALUES ('bar');"
        );
        $this->writeFixtureFile('dev', '03_good.sql', "INSERT INTO be_users (uid, username) VALUES (9003, 'later_admin');");

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Imported 2 fixture file(s). Skipped 1.', $this->normalizedDisplay($tester));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

        self::assertNotFalse($connection->select(['uid'], 'be_users', ['uid' => 9001])->fetchAssociative());
        // 9002's INSERT was in the same file/transaction as the failing
        // statement, so it must have been rolled back entirely.
        self::assertFalse($connection->select(['uid'], 'be_users', ['uid' => 9002])->fetchAssociative());
        self::assertNotFalse($connection->select(['uid'], 'be_users', ['uid' => 9003])->fetchAssociative());
    }

    public function testConnectionFailurePropagatesAsCommandFailure(): void
    {
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        // Doctrine\DBAL\Exception is an interface (not an instantiable
        // class) in the doctrine/dbal version installed here, so a minimal
        // anonymous class stands in for a genuine driver-level failure.
        $dbalException = new class ('Simulated connection failure', 1234) extends \Exception implements DbalException {
        };

        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->method('executeQuery')->willThrowException($dbalException);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($failingConnection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Database connection error', $this->normalizedDisplay($tester));
    }

    public function testDryRunReportsFileAndStatementCountsWithoutImporting(): void
    {
        $this->writeFixtureFile(
            'dev',
            '01_admin.sql',
            "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');\n"
            . "INSERT INTO be_users (uid, username) VALUES (9998, 'second_admin');"
        );

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('01_admin.sql: 2 statement(s)', $this->normalizedDisplay($tester));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        self::assertFalse($connection->select(['uid'], 'be_users', ['uid' => 9999])->fetchAssociative());
    }

    public function testProductionContextWithoutFlagIsBlocked(): void
    {
        $this->switchContext('Production');
        $this->writeFixtureFile('production', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('disabled in Production environment', $this->normalizedDisplay($tester));

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        self::assertFalse($connection->select(['uid'], 'be_users', ['uid' => 9999])->fetchAssociative());
    }

    public function testProductionContextWithFlagImports(): void
    {
        $this->switchContext('Production');
        $this->writeFixtureFile('production', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath, '--production' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Imported 1 fixture file(s). Skipped 0.', $this->normalizedDisplay($tester));
    }

    public function testProductionStagingContextAutoImportsWithoutFlag(): void
    {
        $this->switchContext('Production/Staging');
        $this->writeFixtureFile('staging', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Imported 1 fixture file(s). Skipped 0.', $this->normalizedDisplay($tester));
    }
}
