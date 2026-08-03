<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Tests\Functional\Command;

use Cpsit\CpsUtility\Command\ImportFixturesCommand;
use Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver;
use Cpsit\CpsUtility\Fixture\SqlStatementSplitter;
use Doctrine\DBAL\ConnectionException as DbalConnectionException;
use Doctrine\DBAL\Driver\Exception as DbalDriverException;
use Doctrine\DBAL\Exception\ConnectionException as DbalDriverConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost as DbalConnectionLost;
use Doctrine\DBAL\Result;
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

    /**
     * Doctrine\DBAL\Exception\ConnectionException and its subclass
     * Doctrine\DBAL\Exception\ConnectionLost (what real per-platform
     * ExceptionConverters actually throw for driver-detected connection
     * failures) both inherit DriverException's constructor, which requires
     * a wrapped Doctrine\DBAL\Driver\Exception rather than accepting a
     * plain message string directly. This builds a minimal one so the
     * namespaced exceptions can be constructed realistically in tests.
     */
    private function createDriverException(string $message, int $code): DbalDriverException
    {
        return new class ($message, $code) extends \Exception implements DbalDriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
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

        // Doctrine\DBAL\ConnectionException is a concrete class (with a
        // plain string-message constructor inherited from \Exception) in
        // both doctrine/dbal ^3.9 (required by TYPO3 12.4, verified against
        // the actual 3.9.5 source on GitHub) and doctrine/dbal 4.4.4
        // (installed here, required by TYPO3 13.4) — unlike
        // Doctrine\DBAL\Exception, which is an interface in DBAL 4 and
        // therefore not instantiable directly.
        $dbalException = new DbalConnectionException('Simulated connection failure', 1234);

        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->method('executeQuery')->willThrowException($dbalException);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($failingConnection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Database connection error', $this->normalizedDisplay($tester));
    }

    public function testConnectionRefusedDuringImportPropagatesAsCommandFailure(): void
    {
        // Two files: the connection fails while importing the first one
        // (from executeStatement(), i.e. mid-transaction, not from the
        // pre-flight "SELECT 1" probe). This covers the scenario the
        // pre-flight probe alone cannot: a connection that fails *during*
        // the import loop must still propagate as Command::FAILURE and
        // must not be swallowed as a per-file "skipped" result.
        //
        // Throws the actual class MySQL's ExceptionConverter produces for
        // access-denied/unknown-database/can't-connect/unknown-host (codes
        // 1044, 1045, 1046, 1049, 2002, 2005, ... — see
        // Driver/API/MySQL/ExceptionConverter.php): the *namespaced*
        // Doctrine\DBAL\Exception\ConnectionException, not the unrelated
        // top-level Doctrine\DBAL\ConnectionException used elsewhere in
        // this file for API-misuse errors. This is arguably the more
        // common real-world connection failure (vs. a mid-query "gone
        // away"), and is exactly the class the command's discriminator
        // must match.
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");
        $this->writeFixtureFile('dev', '02_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9998, 'second_admin');");

        $dbalException = new DbalDriverConnectionException($this->createDriverException("Access denied for user 'db'@'db'", 1045), null);

        $failingConnection = $this->createMock(Connection::class);
        // The eager pre-flight probe succeeds...
        $failingConnection->method('executeQuery')->willReturn($this->createStub(Result::class));
        // ...but the connection fails once inside the per-file transaction.
        $failingConnection->method('executeStatement')->willThrowException($dbalException);
        $failingConnection->method('isTransactionActive')->willReturn(true);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($failingConnection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Database connection error', $this->normalizedDisplay($tester));
    }

    public function testConnectionLostDuringImportPropagatesAsCommandFailure(): void
    {
        // Same shape as testConnectionRefusedDuringImportPropagatesAsCommandFailure,
        // but for the "server has gone away" / connection-reset case (MySQL
        // codes 2006, 4031), which MySQL's ExceptionConverter maps to
        // Doctrine\DBAL\Exception\ConnectionLost — a subclass of the
        // namespaced ConnectionException checked above. DBAL's
        // handleDriverException() calls $connection->close() specifically
        // for ConnectionLost, which resets the internal transaction nesting
        // level to 0; isTransactionActive() is stubbed to false here to
        // simulate exactly that post-close() state and exercise the
        // guarded-rollback branch (unconditionally calling rollBack() in
        // that state would itself throw NoActiveTransaction).
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");
        $this->writeFixtureFile('dev', '02_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9998, 'second_admin');");

        $dbalException = new DbalConnectionLost($this->createDriverException('MySQL server has gone away', 2006), null);

        $failingConnection = $this->createMock(Connection::class);
        // The eager pre-flight probe succeeds...
        $failingConnection->method('executeQuery')->willReturn($this->createStub(Result::class));
        // ...but the connection is reported lost once inside the per-file
        // transaction, and is no longer active by the time the command's
        // catch block runs — exercising the guarded rollBack() path added
        // for this scenario.
        $failingConnection->method('executeStatement')->willThrowException($dbalException);
        $failingConnection->method('isTransactionActive')->willReturn(false);

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
