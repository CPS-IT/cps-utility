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

_TODO: filled in by Task 2._

## Writing Fixtures

_TODO: filled in by Task 3._

## Execution Order & Fixture Dependencies

_TODO: filled in by Task 4._

## Behavior & Failure Semantics

_TODO: filled in by Task 5._

## Production Guard

_TODO: filled in by Task 5._

## Database Connection

_TODO: filled in by Task 4._

## Troubleshooting

_TODO: filled in by Task 6._

## Usage Examples

_TODO: filled in by Task 6._
