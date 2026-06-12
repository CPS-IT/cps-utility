# Import Fixtures Command

The `cpsit:import-fixtures` CLI command imports database fixtures from SQL files into the default TYPO3 database connection. It selects the correct set of fixtures automatically based on the current TYPO3 application context.

## Overview

This command is intended for populating or refreshing database state in development, staging, and — with an explicit opt-in flag — production environments. It reads `.sql` files from a context-specific subdirectory and executes them in sequence.

The command is registered as `cpsit:import-fixtures` with the short alias `cpsit:fixtures`.

## Directory Structure

By default the command looks for fixtures under `var/fixtures/` inside the TYPO3 project (resolved via `Environment::getVarPath()`). This directory is non-public and present in every standard TYPO3 installation.

Within that base directory, fixtures are organised into three environment subdirectories:

```
var/fixtures/
├── dev/
│   └── *.sql        # Development and Testing contexts
├── staging/
│   └── *.sql        # Production/Preview and Production/Staging contexts
└── production/
    └── *.sql        # Production context (requires --production flag)
```

Only the subdirectory that matches the current context is used. File names inside a subdirectory are free-form; every `.sql` file found is executed.

## Context Mapping

The command maps TYPO3 application contexts to subdirectory names as follows:

| TYPO3 Application Context | Subdirectory used |
|---------------------------|-------------------|
| `Development`             | `dev`             |
| `Development/Local`       | `dev`             |
| `Testing`                 | `dev`             |
| `Production/Preview`      | `staging`         |
| `Production/Staging`      | `staging`         |
| `Production`              | `production`      |

Any context not listed in this table causes the command to exit cleanly with no output and no error.

## Production Guard

When the current context is `Production` the command exits with a warning and does nothing unless the `--production` flag is passed explicitly. This prevents accidental fixture imports on live systems.

## CLI Reference

```
cpsit:import-fixtures [options]
```

| Option | Short | Description |
|--------|-------|-------------|
| `--directory=PATH` | `-d` | Override the base fixtures directory. Accepts an absolute path, an `EXT:` path, or a project-relative path. |
| `--production` | `-p` | Allow execution in `Production` context. Required when the application context is `Production`. |

### Path formats accepted by `--directory`

| Format | Example |
|--------|---------|
| Absolute path | `/var/www/html/shared/fixtures` |
| `EXT:` notation | `EXT:my_sitepackage/Resources/Private/Fixtures` |
| Project-relative path | `.ddev/fixtures` |

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

## Writing Fixtures

Each fixture file must be a valid SQL file. File names are not treated as table names; they are executed as-is.

### Idempotency

Fixtures should be safe to re-run without producing duplicate or conflicting data. Use `INSERT ... ON DUPLICATE KEY UPDATE` to achieve this:

```sql
INSERT INTO tx_myextension_domain_model_example
    (uid, pid, title, description)
VALUES
    (1, 1, 'Example record', 'Used for local development')
ON DUPLICATE KEY UPDATE
    title       = VALUES(title),
    description = VALUES(description);
```

This pattern ensures the same fixture file can be imported multiple times — for example, after a database reset or on every `ddev start` — without errors.

## Troubleshooting

### Directory not found

The command exits with an error if the resolved directory does not exist. Verify the path:

- For the default location, check that `var/fixtures/<subdir>/` exists relative to the project root.
- For `--directory`, confirm the path is correct and accessible to the web server user.
- For `EXT:` paths, ensure the extension is installed and the path inside it is correct.

### Context not in the mapping table

If the current TYPO3 application context is not listed in the context mapping table the command exits silently without importing anything. Check the value of `TYPO3_CONTEXT` in your environment and add a matching context subdirectory if needed.

### Production guard triggered

If you see a warning about the Production context and no import occurs, either:

- Switch to a non-production context, or
- Pass the `--production` flag if importing on a live system is intentional.
