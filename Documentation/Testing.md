# Running Tests

This package ships two PHPUnit suites:

- **Unit** (`Tests/Unit/`) — pure/fast, no database, no TYPO3 bootstrap dependency beyond autoloading. Currently `SqlStatementSplitterTest` and `FixtureDirectoryResolverTest`.
- **Functional** (`Tests/Functional/`) — boots a real TYPO3 instance via `typo3/testing-framework` against a real database connection. Currently `ImportFixturesCommandTest`.

Both suites are PHPUnit 10+ style XML configs at `Tests/Build/UnitTests.xml` and `Tests/Build/FunctionalTests.xml`.

## Prerequisites

- PHP **8.3+** (the package requires `php: >=8.3` in `composer.json`)
- Composer **2.x**
- Optionally, Docker + Docker Compose v2, if you want to run the functional suite against a real MySQL server instead of SQLite (see below)

If you don't have PHP/Composer on your host, any container that has PHP 8.3 and Composer works too — e.g. this was verified using the `ddev` web container of a consuming project (`ddev exec <command>`), and separately using the `webdevops/php-dev:8.3` image via the package's own `Tests/Build/docker-compose.yml`.

## Install dependencies

This package's `composer.json` sets `"vendor-dir": ".Build/vendor"`, so dependencies (including `phpunit/phpunit` and `typo3/testing-framework`) are installed locally into `.Build/vendor/`, not into a project-wide vendor directory:

```bash
composer install
```

This also runs the `post-autoload-dump` scripts that symlink the package into `.Build/Web/typo3conf/ext/cps_utility`, which the functional test bootstrap needs.

Verify the PHPUnit binary is present:

```bash
.Build/vendor/bin/phpunit --version
# PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
```

## Unit tests

```bash
.Build/vendor/bin/phpunit -c Tests/Build/UnitTests.xml
```

or via the Composer script alias:

```bash
composer test:unit
```

Verified output (27 tests):

```
...........................                                       27 / 27 (100%)

Time: 00:00.014, Memory: 6.00 MB

There was 1 PHPUnit test runner warning:

1) No code coverage driver available

OK, but there were issues!
Tests: 27, Assertions: 66, PHPUnit Warnings: 1.
```

The "no code coverage driver available" warning is expected when Xdebug/PCOV isn't loaded — it is **not** a test failure, but it does make PHPUnit exit with status `1` instead of `0` ("OK, but there were issues!"). See "Code coverage" below if you need a clean `0` exit and an actual coverage report. If you don't care about coverage and just want pass/fail, ignore the warning and check the `Tests:` / `Assertions:` line and that no `FAILURES!` block appears.

## Functional tests

Functional tests boot a full TYPO3 instance (via `typo3/testing-framework`'s `FunctionalTestsBootstrap.php`) and need a real database connection. There are two ways to provide one.

### Fast path: SQLite (no DB server needed)

Set `typo3DatabaseDriver=pdo_sqlite` as an environment variable before invoking PHPUnit — the testing framework then creates an in-memory/file-based SQLite database on the fly, no separate DB server required:

```bash
typo3DatabaseDriver=pdo_sqlite .Build/vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml
```

Verified output (15 tests):

```
...W...........                                                   15 / 15 (100%)

Time: 00:02.105, Memory: 64.00 MB

1 test triggered 2 PHP warnings:
...
* Cpsit\CpsUtility\Tests\Functional\Command\ImportFixturesCommandTest::testUnreadableFixtureDirectoryReturnsCommandFailure

OK, but there were issues!
Tests: 15, Assertions: 39, Warnings: 2.
```

The 2 PHP warnings above come from `testUnreadableFixtureDirectoryReturnsCommandFailure` itself (it makes a fixture directory unreadable and asserts the command handles that gracefully); depending on which user PHP runs as, `scandir()` may or may not actually get "Permission denied" (root bypasses permission checks entirely, in which case the test self-skips instead — see the Docker/MySQL run below). Either way this is not a regression in the package, it's an artifact of how the permission-denial scenario is simulated per-environment.

**Important limitation of the SQLite path:** on SQLite, `CREATE TABLE`/`DROP TABLE`/`TRUNCATE` (DDL) participate in transactions like any other statement. On MySQL/MariaDB they do **not** — see "Why the MySQL/Docker path matters" below.

### Docker path: real MySQL (closer to production)

`Tests/Build/docker-compose.yml` defines two services:

- `web` — `webdevops/php-dev:8.3`, with `Tests/Build/docker/php.ini` mounted in (`xdebug.mode = coverage,develop`, so coverage works out of the box in this container without extra env vars), and the package root bind-mounted at `/app` (`../../:/app`)
- `db` — `mysql:8.0`, seeded with an empty `typo3` database, root password `joh316`

Bring the stack up from `Tests/Build/`:

```bash
cd Tests/Build
docker compose up -d
```

Then run PHPUnit inside the `web` container, pointing the testing framework at the `db` service via the `typo3Database*` environment variables it reads (`typo3DatabaseDriver`, `typo3DatabaseHost`, `typo3DatabaseUsername`, `typo3DatabasePassword`, `typo3DatabaseName`, `typo3DatabasePort` — see `TYPO3\TestingFramework\Core\Testbase::defineOriginalDatabaseSettings()`):

```bash
docker compose exec -T \
  -e typo3DatabaseDriver=mysqli \
  -e typo3DatabaseHost=db \
  -e typo3DatabaseUsername=root \
  -e typo3DatabasePassword=joh316 \
  -e typo3DatabaseName=typo3 \
  -e typo3DatabasePort=3306 \
  web bash -lc 'cd /app && .Build/vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml'
```

Note there is no separate `composer install` step needed inside the `web` container: `.Build/vendor` is pure PHP (no compiled extensions), so the `.Build/vendor` produced by `composer install` on the host (or in another container, e.g. `ddev`) works unmodified once bind-mounted into `web` — verified directly, `uname -m` inside `web` reported `aarch64` while the vendor dir had been built under a different container, and PHPUnit still ran correctly. If your `composer install` ever *does* need to run inside this container (e.g. no host-built `.Build/vendor` yet), just run `docker compose exec web bash -lc 'cd /app && composer install'` first.

Verified output (15 tests, real MySQL):

```
...S...........                                                   15 / 15 (100%)

Time: 00:04.074, Memory: 125.00 MB

OK, but some tests were skipped!
Tests: 15, Assertions: 37, Skipped: 1.

Generating code coverage report in PHP format ... done [00:00.001]

Generating code coverage report in HTML format ... done [00:00.154]

Code Coverage Report Summary:
  Classes:  0.00% (0/28)
  Methods:  2.73% (3/110)
  Lines:   19.79% (153/773)
```

(The one skip is `testUnreadableFixtureDirectoryReturnsCommandFailure` self-skipping because the container runs as `root`, which bypasses filesystem permission checks — this is expected in this environment, not a bug.)

Tear the stack down when done:

```bash
docker compose down -v
```

### Why the MySQL/Docker path matters: DML-only rollback

`Documentation/ImportFixturesCommand.md` documents that the `cpsit:import-fixtures` command's per-file rollback guarantee is **DML-only on MySQL/MariaDB**:

> Rollback is a DML-only guarantee on MySQL/MariaDB. The per-file `beginTransaction()`/`commit()`/`rollBack()` wrapping only protects plain DML (`INSERT`/`UPDATE`/`DELETE`/...). `TRUNCATE`, `CREATE TABLE`, `DROP TABLE`, and other DDL statements trigger an **implicit commit** on MySQL/MariaDB — if a fixture file mixes DDL and DML and a *later* statement in that same file fails, any DML that ran *before* the DDL statement has already been implicitly committed and will **not** be rolled back, even though the file is reported as skipped.

This is a real MySQL/MariaDB-specific behavior (implicit commit on DDL) that **SQLite does not have** — on SQLite, DDL is transactional like everything else, so a functional test run only ever exercised via the SQLite fast path (as above) cannot actually verify this caveat; it would pass on SQLite even if the DDL-implicit-commit distinction were mishandled in the code. As of this writing, the existing `ImportFixturesCommandTest` suite has only ever been run against SQLite in CI/local dev — running it against the Docker/MySQL stack (as above) is currently the *only* way to exercise real MySQL semantics, and any future test that specifically asserts the DML-only rollback guarantee (e.g. a fixture file mixing `CREATE TABLE` + `INSERT` where a later statement fails) must be run against this Docker/MySQL path, not SQLite, to mean anything.

## Running a single test

Filter by test class name:

```bash
.Build/vendor/bin/phpunit -c Tests/Build/UnitTests.xml --filter SqlStatementSplitterTest
```

Filter by test method name (works the same way for functional tests, prefixed with the appropriate DB env vars):

```bash
typo3DatabaseDriver=pdo_sqlite .Build/vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml --filter testHappyPathImportsFixtureFile
```

Both were verified to correctly narrow the run to the matching test(s) only (16/16 and 1/1 respectively, including the `SqlStatementSplitterTest` class filter, which also matches its data-provider-expanded cases).

## Code coverage

Both `Tests/Build/UnitTests.xml` and `Tests/Build/FunctionalTests.xml` already declare `<coverage>` blocks (PHP, HTML, and stdout-summary reports under `.Build/coverage/`). You need a coverage driver (Xdebug or PCOV) loaded for this to do anything; otherwise PHPUnit just prints the "No code coverage driver available" warning shown above and skips report generation.

If Xdebug is installed but not enabled for coverage by default, set `XDEBUG_MODE=coverage` for the run:

```bash
XDEBUG_MODE=coverage .Build/vendor/bin/phpunit -c Tests/Build/UnitTests.xml
```

This was verified to produce a real coverage summary, e.g.:

```
Code Coverage Report Summary:
  Classes:  0.00% (0/28)
  Methods:  3.64% (4/110)
  Lines:   10.01% (78/779)
```

The Docker/MySQL path (`Tests/Build/docker-compose.yml`) already bakes `xdebug.mode = coverage,develop` into its `web` container's `php.ini`, so coverage reports are generated there without setting `XDEBUG_MODE` explicitly.

**Caution:** if you use `ddev xdebug on` to enable Xdebug in a `ddev` container for interactive step-debugging, remember to run `ddev xdebug off` again before running the test suites headlessly. `ddev xdebug on` sets `xdebug.start_with_request=yes` with debug mode active, which makes every PHP process (including each PHPUnit test process) try to connect to a debug client — with no IDE listening, this was observed to hang PHPUnit runs indefinitely rather than failing fast.

## Running both suites together

```bash
composer test
```

runs `composer test:unit` followed by `composer test:functional` (the latter needs one of the two database setups above to be reachable via the `typo3Database*` env vars).
