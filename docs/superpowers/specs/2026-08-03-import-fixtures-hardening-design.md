# Design: Harden `cpsit:import-fixtures`

- Date: 2026-08-03
- Branch: `feature/fixture-importer`
- Status: Approved (pending spec self-review)

## Context

`ImportFixturesCommand` (`Classes/Command/ImportFixturesCommand.php`) is a new, unreleased TYPO3 console command that imports `.sql` fixture files from a context-resolved directory. It has not been tagged/released yet, so refactoring cost is low. A code review found:

- A production-safety bug (a `DbalException` on real DB failure is swallowed and reported as `Command::SUCCESS`).
- A naive, quote-unaware SQL statement splitter that can corrupt statement boundaries on `;` inside string literals.
- No transaction boundary per fixture file, so a mid-file failure leaves partial data committed.
- Zero test coverage (`Tests/Unit` and `Tests/Functional` are empty placeholders).
- Minor path-traversal and `EXT:`-prefix-casing issues on `--directory`.
- An apparent gap in the Production guard (only the exact string `Production` is blocked; `Production/Staging` and `Production/Preview` always auto-import) — **investigated and confirmed intentional**: staging/preview environments are meant to auto-seed their `staging` fixture set. No behavior change here; only clarify intent in code/docs and lock it in with a test.

Full findings are in the review that produced this design (severity-ranked, file:line references); this document captures only the agreed remediation.

## Goals

- Fix the two correctness bugs that affect production-safety and error reporting (DbalException swallowing, missing transactions).
- Replace the SQL splitter with a quote/comment-aware tokenizer.
- Extract testable collaborators so the riskiest logic can be unit-tested without a TYPO3 bootstrap.
- Add a `--dry-run` option.
- Bring test coverage from zero to covering all resolver/splitter branches and the command's control flow.
- Update `Documentation/ImportFixturesCommand.md` and `ChangeLog` to match every behavior change.

## Non-goals

- Changing which environments are guarded (Staging/Preview remain intentionally unguarded — confirmed, not a bug).
- Adding a SQL parser dependency — a hand-rolled tokenizer is sufficient for this command's scope (trusted, developer-authored fixture files).
- Changing the fixture file format/conventions (still one-file-per-concern `.sql`, `ON DUPLICATE KEY UPDATE` recommended for idempotency).

## Architecture

Split the current single class into three:

1. **`Classes/Fixture/SqlStatementSplitter`** — pure, stateless, no TYPO3 dependency.
   - Input: raw SQL file contents (`string`).
   - Output: `list<string>` of individual statements, ready to execute.
   - Behavior: strips `--` and `#` line comments and `/* ... */` block comments, tracks single-quoted strings (`'` with `''` and `\'` escaping), double-quoted identifiers, and backtick identifiers, and splits only on `;` that is outside any of the above. Comment markers and quote characters found inside a string/identifier are not treated as syntax.

2. **`Classes/Fixture/FixtureDirectoryResolver`** — encapsulates path/context logic (still touches `Environment`/`GeneralUtility` statics internally).
   - `resolveBasePath(?string $directoryOption): ?string` — same four branches as today (default `var/fixtures`, `EXT:`, absolute, project-relative), plus:
     - project-relative resolution passes through `realpath()` after concatenation and rejects the result if it falls outside `Environment::getProjectPath()`.
     - the `EXT:` prefix check becomes case-insensitive.
   - `resolveFixtureDirectory(string $basePath, ApplicationContext $context): ?string` — same `CONTEXT_SUBDIRECTORY_MAP` as today, unchanged mapping. Only the bare string `'Production'` is treated as blocked by the caller (see command below) — this class just resolves the subdirectory, it does not decide whether to block.

3. **`Classes/Command/ImportFixturesCommand`** — becomes a thin orchestrator, both collaborators injected via constructor (autowired per existing `Services.yaml` convention):
   - Resolve directory via `FixtureDirectoryResolver`.
   - Guard: if `ApplicationContext::__toString() === 'Production'` and `--production` not passed, warn and return `Command::SUCCESS` (unchanged behavior, just made explicit with a comment referencing this design's intentional scope decision).
   - List `.sql` files via `GeneralUtility::getFilesInDir()`, then explicitly `sort()` the result (own the ordering contract instead of relying on `scandir()`'s incidental default).
   - If `--dry-run`: for each file, split statements via `SqlStatementSplitter` and report file name + statement count; execute nothing; return `Command::SUCCESS`.
   - Otherwise, for each file: `beginTransaction()`, execute each split statement, `commit()` on success. On any `\Throwable` from within the transaction, `rollBack()`, log the file as skipped, continue to the next file. `Doctrine\DBAL\Exception` thrown while acquiring/using the connection itself (not a per-statement SQL error) is NOT caught here — it propagates to a single outer `catch (DbalException $e)` that logs and returns `Command::FAILURE`, matching the documented contract.
   - The key correctness fix: distinguish "this statement's SQL failed" (per-file catch, continue) from "the database connection itself is unusable" (propagate, abort, `Command::FAILURE`). Concretely: `getConnectionByName()` and an explicit connectivity check (e.g. `$connection->connect()` or equivalent) happen once, outside the per-file loop entirely; only the per-file `beginTransaction()`/`executeStatement()`/`commit()`/`rollBack()` sequence is wrapped in the per-file `catch`, so a connection-level failure can only surface at the single outer `catch (DbalException $e)`.

## Behavior changes summary

| Area | Before | After |
|---|---|---|
| DB outage | Swallowed per-file, `Command::SUCCESS` | Propagates, `Command::FAILURE` |
| Mid-file failure | Partial commit, file marked skipped | Full rollback for that file, file marked skipped |
| `;` inside string values | Corrupts statement split | Handled correctly |
| `#`, `/* */` comments | Not stripped | Stripped |
| File ordering | Incidental (`scandir()` default) | Explicit `sort()` |
| `--directory ../..` | Escapes project root | Rejected |
| `--directory ext:...` (wrong case) | Silently treated as literal relative path | Recognized as `EXT:` prefix |
| Dry-run | Not available | `--dry-run` flag added |
| Production/Staging/Preview guard | Auto-import, unguarded | **Unchanged** (confirmed intentional) |

## Testing strategy

**Unit tests** (`Tests/Unit/Fixture/SqlStatementSplitterTest.php`, `Tests/Unit/Fixture/FixtureDirectoryResolverTest.php`) — no TYPO3 bootstrap required for the splitter; the resolver needs `Environment` initialized (existing unit test bootstrap already supports this per `Tests/Build/UnitTests.xml`):

- Splitter: `--` comment, `#` comment, `/* */` block comment (including multi-line), `;` inside single-quoted value, escaped quote (`''` and `\'`) inside a value, backtick-quoted identifier containing `;`, empty file, comment-only file, multiple statements in one file.
- Resolver: default path, `EXT:` valid extension, `EXT:` unloaded/invalid extension, case-insensitive `EXT:`/`ext:`, absolute path, project-relative path, project-relative path with `..` traversal (rejected), each `CONTEXT_SUBDIRECTORY_MAP` entry, unmapped context → `null`.

**Functional tests** (`Tests/Functional/Command/ImportFixturesCommandTest.php`, using existing `Tests/Functional/Fixtures/Database/` placeholder):

- Happy path: valid fixture directory imports successfully, `Command::SUCCESS`.
- Missing directory → `Command::SUCCESS` with informational message (unchanged existing behavior).
- Empty directory → `Command::SUCCESS`, nothing imported.
- One file with a bad statement → that file rolled back and marked skipped, remaining files still import, overall `Command::SUCCESS`.
- Simulated connection failure → `Command::FAILURE` (regression test for the DbalException-swallowing bug).
- `--dry-run` → reports files/counts, database unchanged (assert no rows inserted).
- Production context without `-p` → blocked, `Command::SUCCESS`, warning shown.
- Production context with `-p` → imports.
- `Production/Staging` context without `-p` → imports (locks in the intentional-scope decision).

## Documentation & changelog

- Rewrite the relevant sections of `Documentation/ImportFixturesCommand.md`: transaction/rollback semantics, splitter capabilities (comment styles, quote-awareness), `--dry-run` usage, explicit file-ordering guarantee, and a clear "Guard Scope" note explaining that Staging/Preview are deliberately excluded from the Production guard (not a gap).
- Add a `ChangeLog` entry for this feature, per repo convention (see e.g. `d6ab2c7`).
- Verify the sample password hash shown in the docs (`AdminPassword!1` / argon2i hash) is a throwaway example, not a real leaked credential; replace with an obviously-fake placeholder if there's any doubt.

## Out of scope / deferred

- Gating `Production/Staging`/`Production/Preview` behind `--production` — explicitly decided against; staging must keep auto-seeding without code changes to the consuming project's deploy scripts.
- A general-purpose SQL parser dependency.
- Changing the fixture file naming/directory conventions used by consuming projects.
