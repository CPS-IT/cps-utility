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
3. Imports every `.sql` file found in `var/fixtures/<subdirectory>/`, in the order they are returned by the filesystem (see "Execution Order & Fixture Dependencies" below).

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

`--directory` accepts three path forms — absolute paths, `EXT:` extension-key notation, and project-relative paths — and `--production` only unlocks import when the resolved context is exactly `Production`. The full resolution algorithm for both, including the context-to-subdirectory mapping, is covered in "How It Resolves Paths & Context" below; this table is the flag-level reference only.

## How It Resolves Paths & Context

Three resolution steps happen in sequence every time the command runs. Understanding them together explains both the default behavior from Quick Start and every `--directory` override.

### (a) Base path resolution

Given the `--directory`/`-d` value (or its absence), `resolveBasePath()` picks the base fixtures directory using these rules, in this order:

| `--directory` value | Resolution |
|----------------------|------------|
| Not given (omitted) | `Environment::getVarPath() . '/fixtures'` — i.e. `var/fixtures/` |
| Starts with `EXT:` | Resolved via `GeneralUtility::getFileAbsFileName()`. An empty result here (e.g. unknown extension key) is a genuine error — see "Behavior & Failure Semantics" below. |
| Starts with `/` | Used as-is (absolute path) |
| Anything else | Treated as project-relative: `Environment::getProjectPath() . '/' . <value>` |

For `EXT:` paths, use the extension key with underscores (not hyphens), e.g. `EXT:my_sitepackage/Resources/Private/Fixtures`, and ensure the extension is loaded — an unresolvable key is one of two conditions in this command that produce `Command::FAILURE` (the other being a database connection error during import, covered in "Behavior & Failure Semantics" below).

### (b) Context resolution

The command reads the current TYPO3 application context via `Environment::getContext()` and converts it to its string form (e.g. `Development/Local`, `Production`, `Testing`).

### (c) Subdirectory resolution

The context string is looked up in `CONTEXT_SUBDIRECTORY_MAP`:

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

Each fixture file must be a valid SQL file. File names are not treated as table names; they are executed as-is, in whatever order `GeneralUtility::getFilesInDir()` returns them (see "Execution Order & Fixture Dependencies" below).

### Idempotency

Fixtures should be safe to re-run without producing duplicate or conflicting data — for example after a database reset or on every `ddev start`. Use `INSERT ... ON DUPLICATE KEY UPDATE`, as this project's own `.ddev/fixtures/be_users.sql` does for its admin-user fixture:

```sql
# Default admin user (password = AdminPassword!1)
SET @username := 'admin';
SET @password := '$argon2i$v=19$m=65536,t=16,p=1$dnFPM3F2Z2J1S3RFWW96Mw$bwkXqsGRdSu98m6BpFY7kTekyRDbhN0Dsd8Ib4cQGBY';

INSERT INTO be_users (uid, username, password, admin)
VALUES (1, @username, @password, 1)
ON DUPLICATE KEY UPDATE username = @username,
                                        password = @password;
```

Running this file any number of times leaves exactly one admin user with uid `1` and the expected credentials, rather than failing on a duplicate-key error or piling up duplicate rows.

### Comment Syntax

`splitStatements()` — the internal method that turns a fixture file's contents into individually-executed SQL statements — has a narrow, specific idea of "comment":

```php
private function splitStatements(string $sql): array
{
    $stripped = (string)preg_replace('/--[^\n]*/m', '', $sql);

    return array_values(array_filter(
        array_map('trim', explode(';', $stripped)),
        static fn(string $s): bool => $s !== ''
    ));
}
```

Only `--`-style line comments are stripped, via the regex `/--[^\n]*/m`. After that single stripping pass, the remaining text is split naively on every literal `;` character. This means:

- **`--` line comments are safe.** This repo's `.ddev/fixtures/service_center_level2_categories.sql` uses this style throughout (e.g. `-- Service Center: level-2 categories and their content assignments`) — they are stripped before splitting, so a `;` inside one of these comments would never corrupt statement boundaries.
- **`#` line comments are NOT stripped.** `.ddev/fixtures/be_users.sql` opens with `# Default admin user (password = AdminPassword!1)`. This is currently safe only because that comment contains no `;` character. If a future edit added a `;` inside a `#` comment anywhere in a fixture file, `explode(';', ...)` would split the file in the middle of that comment, producing a malformed statement.
- **`/* ... */` block comments are NOT stripped.** `fixtures/staging/filefill.sql` opens with a `/* ... */` block comment (`/*\n * Activate and configured file fill in stage System.\n */`). Again, this is currently safe only because it contains no `;`. A block comment spanning multiple lines with a `;` anywhere inside it would silently corrupt statement splitting for the rest of the file.

**Practical rule:** prefer `--` line comments in fixture files if you want a comment to be guaranteed safe regardless of its contents. If you use `#` or `/* ... */` comments, never put a literal `;` inside them.

## Execution Order & Fixture Dependencies

The command does not explicitly sort fixture files itself. The order files are processed in is whatever `GeneralUtility::getFilesInDir($fixtureDirectory, 'sql')` returns — which is alphabetical in current TYPO3 versions, but is not a contract this command adds or guarantees on top of that core utility.

**Practical implication:** if your fixtures have foreign-key dependencies on each other — for example, a categories table that must be populated before a table that references those category UIDs via a foreign key or MM-relation table — there is no explicit dependency-ordering mechanism. Rely on numeric or alphabetical filename prefixes to control import order, e.g. `01_categories.sql` before `02_category_assignments.sql`, since alphabetical filename order is the only ordering lever available.

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
| A SQL statement in a fixture file throws during execution | Error: "Failed to import "...": ..." — exception caught **per file**, skip-counter incremented, loop continues to the next file, **no rollback** of statements already applied from that same file | `Command::SUCCESS` (at end) | Other files: yes; this file: partially (whatever ran before the failing statement stays applied) |
| `DbalException` while acquiring the database connection | Error: "Database connection error: ..." | `Command::FAILURE` | No — aborts before any file is processed |
| Normal completion (all files processed, some may have been skipped) | Success: "Imported X fixture file(s). Skipped Y." | `Command::SUCCESS` | Yes, for however many files succeeded |

> **The command effectively never returns `Command::FAILURE` due to bad fixture SQL.** Only a connection-acquisition failure (`DbalException`) or a base-path resolution failure (bad `EXT:` key) produce a non-zero exit code — every other failure mode, including a fixture file throwing mid-import, is swallowed into a `SUCCESS` exit with a printed skip count. **A CI/deploy pipeline that only checks the exit code will not detect that fixtures were silently partially or fully skipped.** If you rely on this command in an automated pipeline, also inspect its output for skip counts and per-file error lines — do not trust the exit status alone.

## Production Guard

When the current context is exactly `Production`, the command prints a warning and does nothing (`Command::SUCCESS`, no import) unless the `--production`/`-p` flag is passed explicitly. This prevents accidental fixture imports against a live production database.

**Scope clarification:** `--production` only gates the exact string context `Production`. The related contexts `Production/Preview` and `Production/Staging` always import into their `staging` fixture subdirectory **unconditionally, with no gate at all** — passing `-p` has no effect in those contexts because the guard check only compares against the literal string `Production`. Do not assume `-p` (or its absence) protects every production-like environment; it protects only the exact `Production` context.

## Database Connection

The command always executes fixture SQL against TYPO3's default Doctrine DBAL connection, obtained via `$this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)`. There is no option to target an alternate or named database connection — if your TYPO3 installation is configured with multiple database connections, fixtures always run against the default one only. Plan fixture placement accordingly if your project relies on secondary connections for any of the tables you intend to seed.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Nothing was imported and no error was shown, when you expected the default `var/fixtures/<subdir>/` (or a `--directory`-supplied path) to be used | The resolved fixture directory does not exist on disk. The command prints an info message ("Fixture directory "..." does not exist. Nothing to import.") and returns `Command::SUCCESS` — it does **not** exit with an error. | Check the printed info message for the exact resolved path. For the default location, verify `var/fixtures/<subdir>/` exists relative to the project root. For `--directory`, confirm the path is correct and accessible to the web server / CLI user. For `EXT:` paths, ensure the extension is installed and the path inside it is correct. |
| Nothing was imported and you're not sure why, and the current TYPO3 application context is unusual (e.g. a custom context) | The current context is not listed in `CONTEXT_SUBDIRECTORY_MAP`. An info message **is** printed — "No fixture directory configured for context "...". Nothing to import." — this is not a silent no-op. | Check the value of `TYPO3_CONTEXT` in your environment against the context-mapping table in "How It Resolves Paths & Context." Add a matching entry to `CONTEXT_SUBDIRECTORY_MAP` in the command source if you need a new context supported (this requires a code change, not a config change). |
| A warning about the Production context appears and no import occurs | The current context is exactly `Production` and `--production`/`-p` was not passed. | Either switch to a non-production context, or pass `--production` if importing on a live system is genuinely intentional. Remember this guard only applies to the exact `Production` context — see "Production Guard." |
| A fixture file's SQL produced an error but the command reported overall success | Each fixture file's SQL execution is wrapped in its own try/catch with no rollback and no propagation to the command's exit code — a per-file failure only increments the skip counter and logs an error line for that file, per "Behavior & Failure Semantics" above. | Do not trust the exit code alone. Check the printed "Failed to import "...": ..." error line and the final "Imported X fixture file(s). Skipped Y." summary for a nonzero skip count. Consider validating fixture SQL independently (e.g. a dry-run against a scratch database) in CI, since this command's exit code will not surface the failure. |
| Command exits with `Command::FAILURE` | This is exclusively one of two causes: (1) a bad `EXT:` key passed to `--directory` that `GeneralUtility::getFileAbsFileName()` could not resolve, or (2) a `DbalException` thrown while acquiring the default database connection. | For (1), verify the extension key uses underscores (not hyphens) and that the extension is actually loaded/active. For (2), confirm database connectivity and credentials are correct and the default TYPO3 database connection is reachable. |

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

### Using the DDEV wrapper

```bash
ddev cms cpsit:import-fixtures
ddev cms cpsit:import-fixtures --directory .ddev/fixtures
```

### Force import in Production context

```bash
php vendor/bin/typo3 cpsit:import-fixtures --production
```
