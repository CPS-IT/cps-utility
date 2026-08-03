# Import Fixtures Command

The `cpsit:import-fixtures` CLI command imports database fixtures from SQL files into the default TYPO3 database connection. It selects the correct set of fixtures automatically based on the current TYPO3 application context.

The command is registered as `cpsit:import-fixtures` with the short alias `cpsit:fixtures`.

**Note:** in the gebaeudeforum-bundle project specifically, the DDEV custom commands `ddev import-fixtures` and `ddev loadFixtures` do **not** currently invoke this command — they use separate mysql-CLI / `typo3 database:import`-based mechanisms. The examples below using `ddev cms cpsit:import-fixtures` describe a valid, supported workflow for any project consuming this package, but it is not (yet) the one wired into gebaeudeforum-bundle's local DDEV setup.

## Quick Start

_TODO: filled in by Task 1._

## CLI Reference

_TODO: filled in by Task 1._

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
