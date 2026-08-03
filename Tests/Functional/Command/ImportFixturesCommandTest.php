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

    public function testUnreadableFixtureDirectoryReturnsCommandFailure(): void
    {
        // GeneralUtility::getFilesInDir() is declared array|string: on a
        // scandir() failure it returns an error string instead of an
        // array, which would previously reach an unguarded sort() call and
        // throw an uncaught TypeError. A directory that passes is_dir()
        // but has no read/execute permission for the current process
        // reproduces this for real (verified directly against this
        // environment's PHP/filesystem before writing this test — chmod
        // 000 on a directory leaves is_dir() true but makes scandir() fail
        // with "Permission denied"). That underlying scandir() call is
        // TYPO3 core code (GeneralUtility::getFilesInDir(), not ours) and
        // is not error-suppressed there, so this test is expected to
        // surface two benign PHP warnings ("Failed to open directory:
        // Permission denied" / "errno 0: Success") alongside a passing
        // result — that is core faithfully reporting the exact failure
        // this test deliberately induces, not a defect.
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            self::markTestSkipped('Running as root bypasses filesystem permission checks, so this scenario cannot be reproduced.');
        }

        $unreadableDirectory = $this->fixtureBasePath . '/dev';

        try {
            chmod($unreadableDirectory, 0000);

            $tester = $this->getCommandTester();
            $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

            self::assertSame(Command::FAILURE, $exitCode);
            self::assertStringContainsString('Cannot read fixture directory', $this->normalizedDisplay($tester));
        } finally {
            // Restore permissions before tearDown()'s recursive rmdir(),
            // which would otherwise be unable to remove/traverse this
            // directory.
            chmod($unreadableDirectory, 0755);
        }
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
        // The connection-liveness re-probe (added because DBAL's exception
        // taxonomy for classifying "dead connection vs. per-statement SQL
        // error" proved unreliable across DBAL major versions — see
        // testPrivilegeErrorDuringImportIsSkippedNotTreatedAsConnectionFailure()
        // below for the case that motivated dropping class-based
        // discrimination entirely) means this mock must make *every*
        // executeQuery() call fail, not just the first one: a genuinely
        // dead connection would fail the pre-flight probe AND any
        // subsequent re-probe identically. That makes this scenario
        // indistinguishable, by design, from testConnectionFailurePropagatesAsCommandFailure()
        // (both hit the pre-flight probe's outer catch) — kept as a
        // separate test because it documents a different real-world root
        // cause (access denied / can't connect, the namespaced
        // Doctrine\DBAL\Exception\ConnectionException that MySQL's
        // ExceptionConverter actually throws for codes 1044/1045/1046/1049/
        // 2002/2005, as opposed to the generic exception used in the other
        // test) still results in the same Command::FAILURE outcome.
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $dbalException = new DbalDriverConnectionException($this->createDriverException("Access denied for user 'db'@'db'", 1045), null);

        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->method('executeQuery')->willThrowException($dbalException);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($failingConnection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Database connection error', $this->normalizedDisplay($tester));
    }

    public function testConnectionLostDuringImportPropagatesAsCommandFailure(): void
    {
        // Same rationale as testConnectionRefusedDuringImportPropagatesAsCommandFailure():
        // every executeQuery() call must fail for this to realistically
        // represent a connection that is actually gone, which — with the
        // liveness re-probe — means it is indistinguishable from a
        // pre-flight failure. See
        // testConnectionDiesMidImportAfterHealthyPreflightPropagatesAsCommandFailure()
        // below for a test that specifically exercises the mid-loop
        // re-probe path (pre-flight succeeds, then the connection dies).
        // Kept as a separate test because it documents the "server has
        // gone away" / connection-reset root cause specifically (MySQL
        // codes 2006, 4031 → Doctrine\DBAL\Exception\ConnectionLost).
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $dbalException = new DbalConnectionLost($this->createDriverException('MySQL server has gone away', 2006), null);

        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->method('executeQuery')->willThrowException($dbalException);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($failingConnection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Database connection error', $this->normalizedDisplay($tester));
    }

    public function testConnectionDiesMidImportAfterHealthyPreflightPropagatesAsCommandFailure(): void
    {
        // Specifically exercises the NEW mid-loop re-probe path introduced
        // by the liveness-check fix: the pre-flight "SELECT 1" probe
        // succeeds (so the loop is entered), but the connection is gone by
        // the time the first file's executeStatement() runs, and the
        // catch block's own re-probe correctly detects that and fails hard
        // — producing the distinct "Database connection lost during
        // import" message (as opposed to the pre-flight probe's "Database
        // connection error" message used by the two tests above).
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");
        $this->writeFixtureFile('dev', '02_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9998, 'second_admin');");

        $dbalException = new DbalConnectionLost($this->createDriverException('MySQL server has gone away', 2006), null);

        $executeQueryCallCount = 0;
        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->method('executeQuery')->willReturnCallback(
            function () use (&$executeQueryCallCount, $dbalException): Result {
                $executeQueryCallCount++;
                if ($executeQueryCallCount === 1) {
                    // The pre-flight probe: connection is still healthy.
                    return $this->createStub(Result::class);
                }

                // Every re-probe from here on: the connection has died.
                throw $dbalException;
            }
        );
        $failingConnection->method('executeStatement')->willThrowException($dbalException);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($failingConnection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Database connection lost during import', $this->normalizedDisplay($tester));
    }

    public function testRollbackFailureDuringImportPropagatesAsCommandFailureWithCleanMessage(): void
    {
        // Exercises the other new branch from the same fix: the connection
        // is genuinely fine (the re-probe succeeds) and a transaction is
        // active, but rollBack() itself throws (e.g. the connection drops
        // in the narrow window between the re-probe and the rollback
        // call). Before this fix, that would escape the catch block
        // entirely as an unhandled exception; now it must produce a clean
        // Command::FAILURE with a distinct "Rollback failed" message.
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->method('executeQuery')->willReturn($this->createStub(Result::class));
        $failingConnection->method('executeStatement')->willThrowException(new \RuntimeException('Simulated bad statement'));
        $failingConnection->method('isTransactionActive')->willReturn(true);
        $failingConnection->method('rollBack')->willThrowException(new \RuntimeException('Simulated rollback failure'));

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($failingConnection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Rollback failed for "01_admin.sql": Simulated rollback failure', $this->normalizedDisplay($tester));
    }

    public function testPrivilegeErrorDuringImportIsSkippedNotTreatedAsConnectionFailure(): void
    {
        // The scenario the whole re-probe fix exists for: a per-statement
        // GRANT/privilege error (MySQL 1142 "command denied to user ... for
        // table X", 1143 column access denied) on an otherwise perfectly
        // healthy connection. Notably, DBAL's own MySQL ExceptionConverter
        // wraps codes 1142/1143 in the exact same class
        // (Doctrine\DBAL\Exception\ConnectionException) used for genuine
        // connection failures like access-denied-at-connect-time — so any
        // class-based discriminator (this command's Fix Round 2) is
        // fundamentally unable to tell them apart. The re-probe can: since
        // the connection itself is untouched by a table-level GRANT error,
        // "SELECT 1" still succeeds, correctly classifying this as a
        // per-file problem rather than aborting the whole import.
        //
        // Matches Documentation/ImportFixturesCommand.md's documented
        // behavior and the brief's example: 01_categories (imports),
        // 02_be_users (the deploy DB user lacks INSERT on this table,
        // skipped), 03_content (still imports).
        $this->writeFixtureFile('dev', '01_categories.sql', "INSERT INTO be_users (uid, username) VALUES (9001, 'categories_admin');");
        $this->writeFixtureFile('dev', '02_be_users.sql', "INSERT INTO be_users (uid, username) VALUES (9002, 'privilege_denied_admin');");
        $this->writeFixtureFile('dev', '03_content.sql', "INSERT INTO be_users (uid, username) VALUES (9003, 'content_admin');");

        $privilegeException = new DbalDriverConnectionException(
            $this->createDriverException("INSERT command denied to user 'deploy'@'%' for table 'be_users'", 1142),
            null
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createStub(Result::class));
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql) use ($privilegeException): int {
                if (str_contains($sql, '9002')) {
                    throw $privilegeException;
                }

                return 1;
            }
        );
        $connection->method('isTransactionActive')->willReturn(true);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($connection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $this->normalizedDisplay($tester);
        self::assertStringContainsString('Imported 2 fixture file(s). Skipped 1.', $display);
        self::assertStringContainsString("Failed to import \"02_be_users.sql\"", $display);
        self::assertStringNotContainsString('Database connection', $display);
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
