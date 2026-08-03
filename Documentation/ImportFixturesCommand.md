# Import Fixtures Command

The `cpsit:import-fixtures` CLI command imports database fixtures from SQL files into the default TYPO3 database connection. It selects the correct set of fixtures automatically based on the current TYPO3 application context.

The command is registered as `cpsit:import-fixtures` with the short alias `cpsit:fixtures`.

**Note:** in the gebaeudeforum-bundle project specifically, the DDEV custom commands `ddev import-fixtures` and `ddev loadFixtures` do **not** currently invoke this command — they use separate mysql-CLI / `typo3 database:import`-based mechanisms. The examples below using `ddev cms cpsit:import-fixtures` describe a valid, supported workflow for any project consuming this package, but it is not (yet) the one wired into gebaeudeforum-bundle's local DDEV setup.

## Quick Start

Run the command with no options at all:

```bash
ddev cms cpsit:import-fixtures
```

(equivalently, outside DDEV: `php vendor/bin/typo3 cpsit:import-fixtures`)

With no flags, the command does three things automatically:

1. Resolves the base fixtures directory to `Environment::getVarPath() . '/fixtures'` — i.e. `var/fixtures/` inside the TYPO3 installation, a non-public directory present in every standard TYPO3 project.
2. Reads the current TYPO3 application context (e.g. `Development/Local`) and maps it to a subdirectory name (e.g. `Development/Local` → `dev`).
3. Imports every `.sql` file found in `var/fixtures/<subdirectory>/`, alphabetically (see "Execution Order & Fixture Dependencies" below).

For a typical local development setup this means dropping `.sql` files into `var/fixtures/dev/` and running the command above — no flags, no configuration. The rest of this document explains how to override the directory, what happens for other contexts, how to write fixtures safely, and exactly what the command does when something goes wrong.

## CLI Reference

```
cpsit:import-fixtures [options]
```

- **Name:** `cpsit:import-fixtures`
- **Alias:** `cpsit:fixtures`
- **Description:** Import database fixtures from SQL files depending on current environment

| Option | Short | Type | Default | Description |
|--------|-------|------|---------|--------------|
| `--directory=PATH` | `-d` | `VALUE_REQUIRED` | none at the CLI level — when omitted, the code resolves the base path to `Environment::getVarPath() . '/fixtures'` | Base directory for fixture files. Supports absolute paths and EXT: notation. |
| `--production` | `-p` | `VALUE_NONE` (flag) | not set | Allow fixture import in Production environment. Required when context is "Production". |
| `--dry-run` | none | `VALUE_NONE` (flag) | not set | List fixture files and their statement counts without executing anything. No transaction is opened and the database is left untouched. |

`--directory` accepts three path forms — absolute paths, `EXT:` extension-key notation, and project-relative paths — and `--production` only unlocks import when the resolved context is exactly `Production`. The full resolution algorithm for both, including the context-to-subdirectory mapping, is covered in "How It Resolves Paths & Context" below; this table is the flag-level reference only.

## How It Resolves Paths & Context

These are the resolution rules the command applies (the exact runtime order also involves an earlier Production-context check — see "Production Guard" below). Understanding them together explains both the default behavior from Quick Start and every `--directory` override.

### (a) Base path resolution

Given the `--directory`/`-d` value (or its absence), `resolveBasePath()` picks the base fixtures directory using these rules, in this order:

| `--directory` value | Resolution |
|----------------------|------------|
| Not given (omitted) | `Environment::getVarPath() . '/fixtures'` — i.e. `var/fixtures/` |
| Starts with `EXT:` | Resolved via `GeneralUtility::getFileAbsFileName()`. An empty result here (e.g. unknown extension key) is a genuine error — see "Behavior & Failure Semantics" below. |
| Starts with `/` | Used as-is (absolute path) |
| Anything else | Treated as project-relative: `Environment::getProjectPath() . '/' . <value>` |

For `EXT:` paths, use the extension key with underscores (not hyphens), e.g. `EXT:my_sitepackage/Resources/Private/Fixtures`, and ensure the extension is loaded — an unresolvable key is one of the conditions in this command that produce `Command::FAILURE` (the others being database connection errors, covered in "Behavior & Failure Semantics" below).

### (b) Context resolution

The command reads the current TYPO3 application context via `Environment::getContext()` and converts it to its string form (e.g. `Development/Local`, `Production`, `Testing`).

### (c) Subdirectory resolution

The context string is looked up in `FixtureDirectoryResolver::CONTEXT_SUBDIRECTORY_MAP` — this constant lives in `Classes/Fixture/FixtureDirectoryResolver.php`, not in the command class itself:

| TYPO3 Application Context | Subdirectory used |
|---------------------------|--------------------|
| `Production` | `production` |
| `Production/Preview` | `staging` |
| `Production/Staging` | `staging` |
| `Development` | `dev` |
| `Development/Local` | `dev` |
| `Testing` | `dev` |

The final fixture directory is `<base path>/<subdirectory>`, e.g. with the default base path and context `Development/Local`, that resolves to `var/fixtures/dev/`.

**Important correction:** a context that is *not* listed in this table does **not** silently exit with no output. The command prints an info message — `No fixture directory configured for context "<context>". Nothing to import.` — and returns `Command::SUCCESS` with nothing imported. If you add a new custom application context, you will see this message rather than no feedback at all.

## Writing Fixtures

Each fixture file must be a valid SQL file. File names are not treated as table names; they are executed in an explicit, sorted order (see "Execution Order & Fixture Dependencies" below).

### Collaborator classes

The command itself is a thin orchestrator. Two collaborators, injected via constructor DI, do the actual work and are the classes to look at (or extend) if you need to change parsing or path/context behavior:

- **`Cpsit\CpsUtility\Fixture\SqlStatementSplitter`** — turns a fixture file's raw contents into a list of individually-executable SQL statements. See "Comment Syntax" below for exactly what it recognises.
- **`Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver`** — resolves the `--directory` option (or its default) to a base path, and resolves a base path plus the current `ApplicationContext` to a concrete fixture directory. Also owns the `CONTEXT_SUBDIRECTORY_MAP` constant used in "How It Resolves Paths & Context" above.

### Idempotency

Fixtures should be safe to re-run without producing duplicate or conflicting data — for example after a database reset or on every `ddev start`. Use `INSERT ... ON DUPLICATE KEY UPDATE`, as this project's own `.ddev/fixtures/be_users.sql` does for its admin-user fixture (this and the other real fixture files cited in this document — e.g. `.ddev/fixtures/be_users.sql`, `.ddev/fixtures/service_center_level2_categories.sql`, `fixtures/staging/filefill.sql` — are drawn from one consuming project as real-world illustrations of the patterns described; the paths themselves are illustrative and are not part of this package):

```sql
# Default admin user (this is a documentation placeholder, not a real
# credential — generate your own hash with TYPO3's own password-hashing
# service, or any argon2i hasher, before using this pattern for a real fixture)
SET @username := 'admin';
SET @password := 'EXAMPLE_HASH_NOT_A_REAL_CREDENTIAL';

INSERT INTO be_users (uid, username, password, admin)
VALUES (1, @username, @password, 1)
ON DUPLICATE KEY UPDATE username = @username,
                                        password = @password;
```

Running this file any number of times leaves exactly one admin user with uid `1` and the same password hash, rather than failing on a duplicate-key error or piling up duplicate rows.

### Comment Syntax

Statement splitting is handled by `Cpsit\CpsUtility\Fixture\SqlStatementSplitter::split()`, a quote/comment-aware tokenizer (not a naive `explode(';', ...)`). It recognises, and does not treat as statement-splitting syntax:

- `--` line comments (to end of line)
- `#` line comments (to end of line)
- `/* ... */` block comments, including ones spanning multiple lines
- single-quoted string literals (`'...'`), with `''` and `\'` escaping
- double-quoted identifiers (`"..."`), with `""` and `\"` escaping
- backtick-quoted identifiers (`` `...` ``), with `` `` `` escaping (no backslash escaping, matching MySQL's own backtick-identifier rules)

Because comment markers and quote characters are only recognised outside of each other's spans, all of the following are now safe in fixture files:

- A `;` inside a comment of any of the three supported styles.
- A `;` inside a single-quoted value, e.g. `INSERT INTO t (a) VALUES ('a;b');`.
- A `;` inside a double-quoted or backtick-quoted identifier.
- A `--` sequence inside a quoted string value (it is no longer mistaken for a line comment).

**Practical rule:** any of `--`, `#`, or `/* */` comments are safe to use; quoting rules match standard MySQL behaviour. There is no longer a narrower "prefer `--`" caveat — this was specific to the old naive splitter and no longer applies.

## Execution Order & Fixture Dependencies

The command explicitly sorts the fixture file list with PHP's `sort()` on the array returned by `GeneralUtility::getFilesInDir($fixtureDirectory, 'sql')` before importing. This is now a guaranteed contract of the command, not an incidental side effect of `scandir()`'s default order.

**`sort()` is byte-wise/lexicographic, not a natural-numeric sort.** This matters for numerically-prefixed filenames: `"10_x.sql"` sorts *before* `"2_x.sql"`, because the comparison is character-by-character (`'1' < '2'`) rather than by numeric value. If you rely on numeric prefixes to sequence more than 9 files, **zero-pad them** (`01_`, `02_`, ..., `10_`, ...) so lexicographic order matches numeric order.

**Practical implication:** if your fixtures have foreign-key dependencies on each other — for example, a categories table that must be populated before a table that references those category UIDs via a foreign key or MM-relation table — rely on zero-padded numeric or alphabetical filename prefixes to control import order, e.g. `01_categories.sql` before `02_category_assignments.sql`.

## Behavior & Failure Semantics

Every exit path in the command, and its actual result:

| Condition | Message shown | Exit code | Import performed? |
|-----------|----------------|-----------|---------------------|
| Context is exactly `Production`, `--production` not passed | Warning: "Fixture import is disabled in Production environment. Use --production to override." | `Command::SUCCESS` | No |
| Base path resolution fails (e.g. bad `EXT:` key) | Error: "Cannot resolve fixture directory "...". For EXT: paths use the extension key (underscores, not hyphens) and ensure the extension is loaded." | `Command::FAILURE` | No |
| Context not present in `CONTEXT_SUBDIRECTORY_MAP` | Info: "No fixture directory configured for context "...". Nothing to import." | `Command::SUCCESS` | No |
| Resolved fixture directory does not exist on disk | Info: "Fixture directory "..." does not exist. Nothing to import." | `Command::SUCCESS` | No |
| No `.sql` files found in the directory | Info: "No SQL fixture files found in "...". " | `Command::SUCCESS` | No |
| A fixture file is unreadable or empty | Warning: "Skipping empty or unreadable file: ..." — file skipped, loop continues | `Command::SUCCESS` (at end) | Other files: yes; this file: no |
| `--dry-run` passed | Info: dry-run summary line, then one line per file with its statement count (or "unreadable or empty, would be skipped") | `Command::SUCCESS` | No — no transaction is opened, no statement is executed |
| Connectivity check fails before any file is processed (`DbalException` on the pre-flight `SELECT 1`) | Error: "Database connection error: ..." | `Command::FAILURE` | No — aborts before any file is processed |
| A SQL statement in a fixture file throws during execution, and the failure is **not** a connection loss | Error: "Failed to import "...": ..." — exception caught **per file**, that file's transaction is rolled back in full, skip-counter incremented, loop continues to the next file | `Command::SUCCESS` (at end) | Other files: yes; this file: no — any statements from that file that ran before the failing one are rolled back too, not left partially applied |
| The database connection is lost or refused **mid-loop**, during an actual import (detected as `Doctrine\DBAL\Exception\ConnectionException`, which also covers `ConnectionLost`/"server has gone away") | Error: "Database connection error: ..." | `Command::FAILURE` | No — aborts immediately; files not yet processed are never attempted |
| Normal completion (all files processed, some may have been skipped) | Success: "Imported X fixture file(s). Skipped Y." | `Command::SUCCESS` | Yes, for however many files succeeded |

> **The command effectively never returns `Command::FAILURE` due to bad fixture SQL.** Only a connectivity failure — checked both before any file is processed (a pre-flight `SELECT 1` probe) *and* mid-loop during an actual import (a lost/refused connection detected via `Doctrine\DBAL\Exception\ConnectionException`) — or a base-path resolution failure (bad `EXT:` key, or a project-relative `--directory` that escapes the project root) produce a non-zero exit code. A fixture file throwing mid-import for any other reason (a genuine SQL error in that file) is caught per file, that file's transaction is fully rolled back, and the command continues with the next file, ending in a `SUCCESS` exit with a printed skip count. **A CI/deploy pipeline that only checks the exit code will not detect that one or more fixture files were silently and fully skipped.** If you rely on this command in an automated pipeline, also inspect its output for skip counts and per-file error lines — do not trust the exit status alone. Consider `--dry-run` as a pre-flight sanity check (file list and statement counts) in CI, though note it does not validate SQL syntax or execute anything.
>
> **Rollback is a DML-only guarantee on MySQL/MariaDB.** The per-file `beginTransaction()`/`commit()`/`rollBack()` wrapping only protects plain DML (`INSERT`/`UPDATE`/`DELETE`/...). `TRUNCATE`, `CREATE TABLE`, `DROP TABLE`, and other DDL statements trigger an **implicit commit** on MySQL/MariaDB — if a fixture file mixes DDL and DML and a *later* statement in that same file fails, any DML that ran *before* the DDL statement has already been implicitly committed and will **not** be rolled back, even though the file is reported as skipped. Keep each fixture file to either pure DML or pure DDL, not a mix of both, if you rely on the rollback guarantee.

## Production Guard

When the current context is exactly `Production`, the command prints a warning and does nothing (`Command::SUCCESS`, no import) unless the `--production`/`-p` flag is passed explicitly. This prevents accidental fixture imports against a live production database.

**Scope clarification:** `--production` only gates the exact string context `Production`. The related contexts `Production/Preview` and `Production/Staging` always import into their `staging` fixture subdirectory **unconditionally, with no gate at all** — passing `-p` has no effect in those contexts because the guard check only compares against the literal string `Production`. Do not assume `-p` (or its absence) protects every production-like environment; it protects only the exact `Production` context. This has been explicitly reviewed and confirmed as intentional (not a gap to be closed): staging/preview environments are meant to auto-seed their `staging` fixture set on every deploy without requiring a `--production` flag or any deploy-script changes. `FixtureDirectoryResolver::CONTEXT_SUBDIRECTORY_MAP` carries a code comment to the same effect, and `ImportFixturesCommandTest::testProductionStagingContextAutoImportsWithoutFlag()` locks this behavior in as a regression test.

**Interaction with `--dry-run`:** the Production guard is checked *before* the `--dry-run` branch, so `--dry-run` on the exact `Production` context still requires `--production`/`-p` to run at all — even though a dry run writes nothing to the database. This is a deliberate guard-ordering choice (the guard exists to prevent unintended production-database access in general, not specifically unintended writes), not a bug; if you only want to preview what a Production run would do, pass both flags together: `--production --dry-run`.

## Database Connection

The command always executes fixture SQL against TYPO3's default Doctrine DBAL connection, obtained via `$this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)`. There is no option to target an alternate or named database connection — if your TYPO3 installation is configured with multiple database connections, fixtures always run against the default one only. Plan fixture placement accordingly if your project relies on secondary connections for any of the tables you intend to seed.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Nothing was imported and no error was shown, when you expected the default `var/fixtures/<subdir>/` (or a `--directory`-supplied path) to be used | The resolved fixture directory does not exist on disk. The command prints an info message ("Fixture directory "..." does not exist. Nothing to import.") and returns `Command::SUCCESS` — it does **not** exit with an error. | Check the printed info message for the exact resolved path. For the default location, verify `var/fixtures/<subdir>/` exists relative to the project root. For `--directory`, confirm the path is correct and accessible to the web server / CLI user. For `EXT:` paths, ensure the extension is installed and the path inside it is correct. |
| Nothing was imported and you're not sure why, and the current TYPO3 application context is unusual (e.g. a custom context) | The current context is not listed in `FixtureDirectoryResolver::CONTEXT_SUBDIRECTORY_MAP`. An info message **is** printed — "No fixture directory configured for context "...". Nothing to import." — this is not a silent no-op. | Check the value of `TYPO3_CONTEXT` in your environment against the context-mapping table in "How It Resolves Paths & Context." Add a matching entry to `CONTEXT_SUBDIRECTORY_MAP` in `Classes/Fixture/FixtureDirectoryResolver.php` if you need a new context supported (this requires a code change, not a config change). |
| A warning about the Production context appears and no import occurs | The current context is exactly `Production` and `--production`/`-p` was not passed. This also applies to `--dry-run` on the exact `Production` context — see "Interaction with `--dry-run`" under "Production Guard." | Either switch to a non-production context, or pass `--production` if importing (or dry-running) on a live system is genuinely intentional. Remember this guard only applies to the exact `Production` context — see "Production Guard." |
| A fixture file's SQL produced an error but the command reported overall success | Each fixture file's SQL execution runs inside its own transaction with its own try/catch; on any error that is not a lost/refused connection, that file's transaction is rolled back in full and the skip counter is incremented, per "Behavior & Failure Semantics" above. The command's exit code is not affected by this. | Do not trust the exit code alone. Check the printed "Failed to import "...": ..." error line and the final "Imported X fixture file(s). Skipped Y." summary for a nonzero skip count. Use `--dry-run` to check the file list and statement counts ahead of a real run — it lists files and counts, but does not validate SQL syntax or execute anything, so it cannot substitute for checking the skip count after a real run. |
| Command exits with `Command::FAILURE` | This has three possible causes: (1) a bad `EXT:` key passed to `--directory` that `GeneralUtility::getFileAbsFileName()` could not resolve, or a project-relative `--directory` that escapes the project root; (2) the pre-flight connectivity probe (`SELECT 1`) fails before any file is processed; or (3) the connection is lost or refused **mid-import**, detected as `Doctrine\DBAL\Exception\ConnectionException` (which also covers `ConnectionLost`/"server has gone away"). | For (1), verify the extension key uses underscores (not hyphens), that the extension is actually loaded/active, and that a project-relative path stays inside the project root. For (2) and (3), confirm database connectivity and credentials are correct and the default TYPO3 database connection stays reachable for the duration of the import — a connection dropped mid-run (e.g. `wait_timeout`, a DB restart) is now surfaced as `Command::FAILURE` rather than silently skipping the remaining files. |

## Usage Examples

### Default run

Runs against `var/fixtures/<context-subdir>/` using the active application context:

```bash
php vendor/bin/typo3 cpsit:import-fixtures
```

### Override the fixtures directory

Load fixtures from a custom directory inside a site package:

```bash
php vendor/bin/typo3 cpsit:import-fixtures --directory EXT:my_sitepackage/Resources/Private/Fixtures
```

Load from a project-relative path (useful in DDEV workflows):

```bash
php vendor/bin/typo3 cpsit:import-fixtures --directory .ddev/fixtures
```

Note that `--directory` only sets the *base* path — a context subdirectory (`dev`/`staging`/`production`) is still appended on top of whatever you pass, per "How It Resolves Paths & Context" above. Before reusing this example, make sure `<your-directory>/<context-subdir>/` (e.g. `.ddev/fixtures/dev/`) actually exists; otherwise the command silently reports "Nothing to import" and exits `Command::SUCCESS`.

### Using the DDEV wrapper

```bash
ddev cms cpsit:import-fixtures
ddev cms cpsit:import-fixtures --directory .ddev/fixtures
```

### Force import in Production context

```bash
php vendor/bin/typo3 cpsit:import-fixtures --production
```
