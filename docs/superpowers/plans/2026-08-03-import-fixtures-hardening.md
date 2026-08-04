# Harden `cpsit:import-fixtures` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split `ImportFixturesCommand` into a pure `SqlStatementSplitter`, a `FixtureDirectoryResolver`, and a thin command orchestrator; fix the DbalException-swallowing and missing-transaction bugs; add a `--dry-run` option and explicit file-ordering; bring test coverage from zero to full branch coverage of the new collaborators plus functional coverage of the command's control flow; update documentation to match.

**Architecture:** Two new pure/TYPO3-light collaborator classes under `Classes/Fixture/` (`SqlStatementSplitter`, `FixtureDirectoryResolver`), both constructor-injected into `ImportFixturesCommand` via the existing `Configuration/Services.yaml` autowiring convention (`resource: '../Classes/*'` already picks up new classes recursively — no Services.yaml change needed). The command becomes an orchestrator: resolve directory → guard → list/sort files → dry-run or execute-with-transaction-per-file.

**Tech Stack:** PHP 8.1+/8.3, TYPO3 v12.4/v13.4 (`typo3/cms-core: ^12.4 || ^13.4`), Symfony Console, Doctrine DBAL (via TYPO3's `TYPO3\CMS\Core\Database\Connection`/`ConnectionPool`), PHPUnit via `typo3/testing-framework`.

## Global Constraints

- PHP: `declare(strict_types=1);` in every new/modified file (repo convention, confirmed in existing `ImportFixturesCommand.php`).
- TYPO3 core constraint: `typo3/cms-core: ^12.4 || ^13.4` (from `composer.json`) — any new dev dependency must support both.
- Indentation: 4 spaces PHP, per `.editorconfig`/TYPO3 coding standard already used in this repo.
- The Production guard (`$contextKey === 'Production'` exact-string check) is UNCHANGED behavior — do not gate `Production/Staging` or `Production/Preview`. This was investigated and is an intentional, approved design decision, not a bug.
- No new SQL-parser dependency — hand-rolled tokenizer only (per design doc "Non-goals").
- Fixture file format/conventions (one file per concern, `ON DUPLICATE KEY UPDATE` for idempotency) are unchanged.
- Every code task follows TDD: failing test first, minimal implementation, verify pass, commit.

## Pre-existing repo gap found during investigation (read this before Task 1)

`Tests/Build/UnitTests.xml` and `Tests/Build/FunctionalTests.xml` both reference bootstrap files under `.Build/vendor/typo3/testing-framework/...` and `.Build/vendor/phpunit/phpunit/phpunit.xsd`. However, `composer.json` has **no `config.vendor-dir` setting** and **no `typo3/testing-framework` (or any PHPUnit) dependency at all** — `require-dev` only lists `roave/security-advisories`. Checking the full git history of `composer.json` confirms `vendor-dir` was never configured. This means the existing PHPUnit XML configs have never actually been runnable as committed: a plain `composer install` today would populate `vendor/`, not `.Build/vendor/`, and there is no `phpunit`/`typo3/testing-framework` to install anyway. Task 1 below fixes this (adds the dependency and the matching `config.vendor-dir`) so the pre-existing XML configs finally work as written, without needing to touch either XML file.

## Design-doc citation correction (informational, affects Task 4 only)

The design doc says to "Add a ChangeLog entry for this feature, per repo convention (see e.g. `d6ab2c7`)." Checking `git show d6ab2c7 --stat` shows that commit touched **only** `Classes/UserFunctions/DateFormat.php` — it did **not** touch `ChangeLog`. Checking the actual `[RELEASE]` commits (e.g. `ab8b8f0`, `98086e7`) shows `ChangeLog` is updated **only** by dedicated `[RELEASE]` commits, which bulk-append one line per commit since the previous release (each with that commit's real, already-existing SHA) followed by a blank line before the previous block. Individual `[FEATURE]`/`[BUGFIX]`/`[TASK]` commits never touch `ChangeLog` themselves — and structurally can't cite their own SHA before they exist. Task 4 therefore does **not** hand-edit `ChangeLog`; it documents this finding instead. Flagged explicitly per the requester's instruction to surface design-doc/reality mismatches rather than silently guessing.

---

### Task 1: Test tooling setup + `SqlStatementSplitter`

**Files:**
- Modify: `composer.json`
- Create: `Classes/Fixture/SqlStatementSplitter.php`
- Test: `Tests/Unit/Fixture/SqlStatementSplitterTest.php`

**Interfaces:**
- Produces: `Cpsit\CpsUtility\Fixture\SqlStatementSplitter::split(string $sql): array` — returns `list<string>` of trimmed, non-empty SQL statements. This is the exact method name/signature Task 3 (command refactor) will call as `$this->statementSplitter->split($sql)`.

- [ ] **Step 1: Add the test dependency and fix the vendor-dir mismatch in `composer.json`**

Edit `composer.json` (current full content shown for context — apply exactly these three changes: add `typo3/testing-framework` to `require-dev`, add a `config.vendor-dir` block, and add a `test:functional` script plus wire it into `test`):

```json
{
  "name": "cpsit/cps-utility",
  "description": "Collection of utilities to use in TYPO3 Extensions.",
  "license": "GPL-2.0-or-later",
  "type": "typo3-cms-extension",
  "homepage": "https://github.com/CPS-IT/cps-utility",
  "require": {
    "typo3/cms-core": "^12.4 || ^13.4"
  },
  "require-dev": {
    "roave/security-advisories": "dev-master",
    "typo3/testing-framework": "^8.3"
  },
  "config": {
    "vendor-dir": ".Build/vendor"
  },
  "autoload": {
    "psr-4": {
      "Cpsit\\CpsUtility\\": "Classes"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Cpsit\\CpsUtility\\Tests\\": "Tests"
    }
  },
  "extra": {
    "typo3/cms": {
      "extension-key": "cps_utility"
    }
  },
  "scripts": {
    "post-autoload-dump": [
      "mkdir -p .Build/Web/typo3conf/ext/",
      "[ -L .Build/Web/typo3conf/ext/cps_utility ] || ln -snvf ../../../../. .Build/Web/typo3conf/ext/cps_utility"
    ],
    "test": [
      "@test:unit",
      "@test:functional"
    ],
    "test:unit": [
      "phpunit -c Tests/Build/UnitTests.xml"
    ],
    "test:functional": [
      "phpunit -c Tests/Build/FunctionalTests.xml"
    ]
  }
}
```

`typo3/testing-framework: ^8.3` is confirmed (via Packagist metadata for `typo3/testing-framework` versions `8.0.0`–`8.3.3`) to require `typo3/cms-core: 12.*.*@dev || 13.*.*@dev` and `phpunit/phpunit: ^10.1 || ^11.0`, matching this package's own `typo3/cms-core: ^12.4 || ^13.4` constraint and the PHPUnit 10+ XML syntax already used in `Tests/Build/UnitTests.xml` (`<coverage><report>`, `<source><include>`).

- [ ] **Step 2: Install dependencies and verify the vendor-dir fix**

Run:

```bash
composer update
```

Expected: Composer resolves and installs into `.Build/vendor/` (a full TYPO3 core + `typo3/testing-framework` + `phpunit/phpunit` tree — this will take a while and download a large dependency graph, that is expected). Verify with:

```bash
ls .Build/vendor/bin/phpunit
ls .Build/vendor/typo3/testing-framework/Resources/Core/Build/UnitTestsBootstrap.php
```

Both paths must exist. If `composer update` fails to resolve, re-check the `typo3/testing-framework` version constraint against current Packagist metadata (`composer show -a typo3/testing-framework` after a first partial install, or `curl -s https://repo.packagist.org/p2/typo3/testing-framework.json`) — do not loosen the constraint to something incompatible with `^12.4 || ^13.4`.

- [ ] **Step 3: Write the failing test**

Create `Tests/Unit/Fixture/SqlStatementSplitterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Tests\Unit\Fixture;

use Cpsit\CpsUtility\Fixture\SqlStatementSplitter;
use PHPUnit\Framework\TestCase;

final class SqlStatementSplitterTest extends TestCase
{
    private SqlStatementSplitter $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SqlStatementSplitter();
    }

    public function testEmptyInputProducesNoStatements(): void
    {
        self::assertSame([], $this->subject->split(''));
    }

    public function testCommentOnlyInputProducesNoStatements(): void
    {
        self::assertSame([], $this->subject->split("-- just a comment\n"));
    }

    public function testWhitespaceOnlyInputProducesNoStatements(): void
    {
        self::assertSame([], $this->subject->split("   \n\t  \n"));
    }

    public function testSingleStatementIsReturnedTrimmed(): void
    {
        self::assertSame(
            ['SELECT 1'],
            $this->subject->split('  SELECT 1;  ')
        );
    }

    public function testMultipleStatementsInOneFile(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2', 'SELECT 3'],
            $this->subject->split('SELECT 1; SELECT 2; SELECT 3;')
        );
    }

    public function testTrailingStatementWithoutSemicolonIsIncluded(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->subject->split('SELECT 1; SELECT 2')
        );
    }

    public function testDoubleDashLineCommentIsStripped(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->subject->split("SELECT 1; -- a comment with a ; inside it\nSELECT 2;")
        );
    }

    public function testHashLineCommentIsStripped(): void
    {
        self::assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->subject->split("# a comment with a ; inside it\nSELECT 1;\nSELECT 2;")
        );
    }

    public function testBlockCommentIsStripped(): void
    {
        self::assertSame(
            ['SELECT 1'],
            $this->subject->split('/* a block comment with a ; inside it */ SELECT 1;')
        );
    }

    public function testMultiLineBlockCommentIsStripped(): void
    {
        $sql = "/*\n * multi-line comment\n * with a ; inside it\n */\nSELECT 1;";

        self::assertSame(['SELECT 1'], $this->subject->split($sql));
    }

    public function testSemicolonInsideSingleQuotedValueDoesNotSplit(): void
    {
        self::assertSame(
            ["INSERT INTO t (a) VALUES ('a;b')"],
            $this->subject->split("INSERT INTO t (a) VALUES ('a;b');")
        );
    }

    public function testDoubledSingleQuoteEscapeInsideValue(): void
    {
        self::assertSame(
            ["INSERT INTO t (a) VALUES ('it''s a test')"],
            $this->subject->split("INSERT INTO t (a) VALUES ('it''s a test');")
        );
    }

    public function testBackslashEscapedSingleQuoteInsideValue(): void
    {
        self::assertSame(
            ["INSERT INTO t (a) VALUES ('it\\'s a test')"],
            $this->subject->split("INSERT INTO t (a) VALUES ('it\\'s a test');")
        );
    }

    public function testBacktickIdentifierContainingSemicolonDoesNotSplit(): void
    {
        self::assertSame(
            ['SELECT `col;name` FROM t'],
            $this->subject->split('SELECT `col;name` FROM t;')
        );
    }

    public function testDoubleQuotedIdentifierContainingSemicolonDoesNotSplit(): void
    {
        self::assertSame(
            ['SELECT "col;name" FROM t'],
            $this->subject->split('SELECT "col;name" FROM t;')
        );
    }

    public function testDoubleDashInsideSingleQuotedValueIsNotTreatedAsComment(): void
    {
        self::assertSame(
            ["INSERT INTO t (a) VALUES ('a--b')"],
            $this->subject->split("INSERT INTO t (a) VALUES ('a--b');")
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `.Build/vendor/bin/phpunit -c Tests/Build/UnitTests.xml --filter SqlStatementSplitterTest`

Expected: FAIL — `Class "Cpsit\CpsUtility\Fixture\SqlStatementSplitter" not found`.

- [ ] **Step 5: Write the implementation**

Create `Classes/Fixture/SqlStatementSplitter.php`:

```php
<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Fixture;

/**
 * Splits raw SQL file contents into a list of individually executable
 * statements.
 *
 * Recognises and skips, without treating their contents as syntax:
 *  - `--` line comments
 *  - `#` line comments
 *  - `/ * ... * /` block comments (including multi-line)
 *  - single-quoted string literals ('...'), with '' and \' escaping
 *  - double-quoted identifiers ("..."), with "" and \" escaping
 *  - backtick-quoted identifiers (`...`), with `` escaping
 *
 * Statements are split only on a `;` character that is outside all of the
 * above contexts. Empty statements (comment-only input, blank input,
 * trailing/leading whitespace) are omitted from the result.
 */
final class SqlStatementSplitter
{
    /**
     * @return list<string>
     */
    public function split(string $sql): array
    {
        $length = strlen($sql);
        $statements = [];
        $current = '';
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if (($char === '-' && $next === '-') || $char === '#') {
                $i = $this->skipToEndOfLine($sql, $i, $length);
                continue;
            }

            if ($char === '/' && $next === '*') {
                $i = $this->skipBlockComment($sql, $i, $length);
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                [$literal, $i] = $this->consumeQuoted($sql, $i, $length, $char);
                $current .= $literal;
                continue;
            }

            if ($char === ';') {
                $statements[] = trim($current);
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return array_values(array_filter(
            $statements,
            static fn(string $statement): bool => $statement !== ''
        ));
    }

    private function skipToEndOfLine(string $sql, int $i, int $length): int
    {
        while ($i < $length && $sql[$i] !== "\n") {
            $i++;
        }

        return $i;
    }

    private function skipBlockComment(string $sql, int $i, int $length): int
    {
        $i += 2; // consume "/*"

        while ($i < $length) {
            if ($sql[$i] === '*' && $i + 1 < $length && $sql[$i + 1] === '/') {
                return $i + 2;
            }
            $i++;
        }

        return $i;
    }

    /**
     * Consumes a quoted string/identifier starting at $i (which points at
     * the opening quote character $quoteChar), honouring doubled-quote
     * escaping ('', "", ``) and, for ' and ", backslash escaping (\', \").
     * Backtick identifiers do not support backslash escaping (matches
     * MySQL behaviour: only doubling the backtick escapes it).
     *
     * @return array{0: string, 1: int} the consumed literal (including both
     *     quote characters) and the index just after the closing quote
     */
    private function consumeQuoted(string $sql, int $i, int $length, string $quoteChar): array
    {
        $literal = $quoteChar;
        $i++;

        while ($i < $length) {
            $char = $sql[$i];

            if ($quoteChar !== '`' && $char === '\\' && $i + 1 < $length) {
                $literal .= $char . $sql[$i + 1];
                $i += 2;
                continue;
            }

            if ($char === $quoteChar) {
                if ($i + 1 < $length && $sql[$i + 1] === $quoteChar) {
                    $literal .= $quoteChar . $quoteChar;
                    $i += 2;
                    continue;
                }

                $literal .= $quoteChar;
                return [$literal, $i + 1];
            }

            $literal .= $char;
            $i++;
        }

        return [$literal, $i];
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `.Build/vendor/bin/phpunit -c Tests/Build/UnitTests.xml --filter SqlStatementSplitterTest`

Expected: PASS, all 16 test methods green.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock Classes/Fixture/SqlStatementSplitter.php Tests/Unit/Fixture/SqlStatementSplitterTest.php
git commit -m "[FEATURE] Add quote/comment-aware SqlStatementSplitter with test tooling"
```

(If `composer update` did not produce a `composer.lock` change worth committing, or this repo intentionally does not commit `composer.lock` — check `git status` first; only add files that `git status` actually shows as changed.)

---

### Task 2: `FixtureDirectoryResolver`

**Files:**
- Create: `Classes/Fixture/FixtureDirectoryResolver.php`
- Test: `Tests/Unit/Fixture/FixtureDirectoryResolverTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver::resolveBasePath(?string $directoryOption): ?string`
  - `Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver::resolveFixtureDirectory(string $basePath, \TYPO3\CMS\Core\Core\ApplicationContext $context): ?string`
  - `Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver::CONTEXT_SUBDIRECTORY_MAP` (public const array, same 6 entries as today's `ImportFixturesCommand::CONTEXT_SUBDIRECTORY_MAP`)

  Task 3 (command refactor) calls both methods exactly as named above, passing the `ApplicationContext` object returned by `Environment::getContext()` (not a pre-stringified context key) to `resolveFixtureDirectory()`.

- [ ] **Step 1: Write the failing test**

Create `Tests/Unit/Fixture/FixtureDirectoryResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Tests\Unit\Fixture;

use Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FixtureDirectoryResolverTest extends UnitTestCase
{
    private FixtureDirectoryResolver $subject;
    private string $projectPath;
    private ApplicationContext $originalContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new FixtureDirectoryResolver();
        $this->originalContext = Environment::getContext();
        $rawProjectPath = sys_get_temp_dir() . '/cps_utility_resolver_test_' . uniqid('', true);
        mkdir($rawProjectPath, 0777, true);
        // Canonicalize once via realpath() (macOS resolves /tmp and /var
        // through symlinks to /private/...; FixtureDirectoryResolver calls
        // realpath() internally, so every comparison below must use the
        // same already-resolved form or assertions would spuriously fail
        // on any platform where the temp dir sits behind a symlink).
        $this->projectPath = realpath($rawProjectPath);
        $this->initializeEnvironment($this->projectPath);
    }

    protected function tearDown(): void
    {
        $this->initializeEnvironment($this->projectPath, $this->originalContext);
        $this->removeDirectory($this->projectPath);
        parent::tearDown();
    }

    private function initializeEnvironment(string $projectPath, ?ApplicationContext $context = null): void
    {
        Environment::initialize(
            $context ?? new ApplicationContext('Testing'),
            true,
            false,
            $projectPath,
            $projectPath . '/public',
            $projectPath . '/var',
            $projectPath . '/config',
            $projectPath . '/bin/typo3',
            'UNIX'
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . '/' . $item;
            is_dir($itemPath) ? $this->removeDirectory($itemPath) : unlink($itemPath);
        }
        rmdir($path);
    }

    // --- resolveBasePath ---

    public function testResolveBasePathReturnsVarFixturesPathWhenOptionIsNull(): void
    {
        self::assertSame(
            $this->projectPath . '/var/fixtures',
            $this->subject->resolveBasePath(null)
        );
    }

    public function testResolveBasePathResolvesAbsolutePathAsIs(): void
    {
        self::assertSame(
            '/absolute/custom/path',
            $this->subject->resolveBasePath('/absolute/custom/path')
        );
    }

    public function testResolveBasePathResolvesProjectRelativePath(): void
    {
        mkdir($this->projectPath . '/fixtures-dir', 0777, true);

        self::assertSame(
            $this->projectPath . '/fixtures-dir',
            $this->subject->resolveBasePath('fixtures-dir')
        );
    }

    public function testResolveBasePathReturnsUnresolvedCandidateWhenProjectRelativePathDoesNotExistYet(): void
    {
        self::assertSame(
            $this->projectPath . '/not-created-yet',
            $this->subject->resolveBasePath('not-created-yet')
        );
    }

    public function testResolveBasePathRejectsProjectRelativePathEscapingProjectRoot(): void
    {
        // The parent of the project path exists (it is sys_get_temp_dir()),
        // so realpath() resolves "../.." successfully, allowing the escape
        // check to actually trigger instead of silently no-op-ing on a
        // non-existent target.
        self::assertNull($this->subject->resolveBasePath('../..'));
    }

    public function testResolveBasePathAcceptsProjectRelativePathEqualToProjectRoot(): void
    {
        self::assertSame(
            $this->projectPath,
            $this->subject->resolveBasePath('.')
        );
    }

    public function testResolveBasePathResolvesExtPathCaseInsensitively(): void
    {
        // "ext:" (lowercase) must be recognised the same as "EXT:".
        self::assertNull($this->subject->resolveBasePath('ext:some_unloaded_extension_key'));
        self::assertNull($this->subject->resolveBasePath('EXT:some_unloaded_extension_key'));
    }

    public function testResolveBasePathReturnsNullForUnresolvableExtPath(): void
    {
        self::assertNull($this->subject->resolveBasePath('EXT:definitely_not_a_loaded_extension'));
    }

    // --- resolveFixtureDirectory ---

    public function testResolveFixtureDirectoryForEachMappedContext(): void
    {
        $expectations = [
            'Production' => 'production',
            'Production/Preview' => 'staging',
            'Production/Staging' => 'staging',
            'Development' => 'dev',
            'Development/Local' => 'dev',
            'Testing' => 'dev',
        ];

        foreach ($expectations as $contextKey => $expectedSubdirectory) {
            self::assertSame(
                '/base/' . $expectedSubdirectory,
                $this->subject->resolveFixtureDirectory('/base', new ApplicationContext($contextKey)),
                sprintf('Context "%s" should map to subdirectory "%s"', $contextKey, $expectedSubdirectory)
            );
        }
    }

    public function testResolveFixtureDirectoryReturnsNullForUnmappedContext(): void
    {
        self::assertNull(
            $this->subject->resolveFixtureDirectory('/base', new ApplicationContext('Development/CustomUnmapped'))
        );
    }

    public function testResolveFixtureDirectoryStripsTrailingSlashFromBasePath(): void
    {
        self::assertSame(
            '/base/dev',
            $this->subject->resolveFixtureDirectory('/base/', new ApplicationContext('Testing'))
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.Build/vendor/bin/phpunit -c Tests/Build/UnitTests.xml --filter FixtureDirectoryResolverTest`

Expected: FAIL — `Class "Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver" not found`.

- [ ] **Step 3: Write the implementation**

Create `Classes/Fixture/FixtureDirectoryResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Fixture;

use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the base fixture directory and the context-specific
 * subdirectory used by the `cpsit:import-fixtures` command.
 */
final class FixtureDirectoryResolver
{
    /**
     * Maps TYPO3 application contexts to fixture subdirectory names.
     * Only contexts listed here will trigger a fixture import.
     *
     * NOTE: `Production/Preview` and `Production/Staging` are mapped here
     * alongside `Production` itself, but they are NOT subject to the
     * command's Production guard (see `ImportFixturesCommand::execute()`),
     * which checks only the exact string `Production`. This is a deliberate,
     * reviewed design decision — staging/preview environments are meant to
     * auto-seed their fixtures without a `--production` flag — not an
     * oversight. See Documentation/ImportFixturesCommand.md, "Production
     * Guard".
     */
    public const array CONTEXT_SUBDIRECTORY_MAP = [
        'Production' => 'production',
        'Production/Preview' => 'staging',
        'Production/Staging' => 'staging',
        'Development' => 'dev',
        'Development/Local' => 'dev',
        'Testing' => 'dev',
    ];

    public function resolveBasePath(?string $directoryOption): ?string
    {
        if ($directoryOption === null) {
            return Environment::getVarPath() . '/fixtures';
        }

        if (stripos($directoryOption, 'EXT:') === 0) {
            $resolved = GeneralUtility::getFileAbsFileName($directoryOption);
            return $resolved !== '' ? rtrim($resolved, '/') : null;
        }

        if (str_starts_with($directoryOption, '/')) {
            return $directoryOption;
        }

        return $this->resolveProjectRelativePath($directoryOption);
    }

    public function resolveFixtureDirectory(string $basePath, ApplicationContext $context): ?string
    {
        $contextKey = $context->__toString();

        if (!isset(self::CONTEXT_SUBDIRECTORY_MAP[$contextKey])) {
            return null;
        }

        return rtrim($basePath, '/') . '/' . self::CONTEXT_SUBDIRECTORY_MAP[$contextKey];
    }

    private function resolveProjectRelativePath(string $directoryOption): ?string
    {
        $projectPath = Environment::getProjectPath();
        $candidate = $projectPath . '/' . ltrim($directoryOption, '/');
        $realCandidate = realpath($candidate);

        if ($realCandidate === false) {
            // The path (or a parent segment) does not exist yet, e.g. a
            // fixture directory that has not been created on disk. There is
            // nothing to escape-check against; the caller's later is_dir()
            // check reports "does not exist" for this case, same as before.
            return $candidate;
        }

        $realProjectPath = realpath($projectPath);

        if ($realProjectPath === false) {
            return null;
        }

        if ($realCandidate !== $realProjectPath && !str_starts_with($realCandidate, $realProjectPath . '/')) {
            return null;
        }

        return $realCandidate;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `.Build/vendor/bin/phpunit -c Tests/Build/UnitTests.xml --filter FixtureDirectoryResolverTest`

Expected: PASS, all test methods green (the `testResolveFixtureDirectoryForEachMappedContext` method alone exercises all 6 map entries plus asserts via `sprintf` failure messages).

- [ ] **Step 5: Commit**

```bash
git add Classes/Fixture/FixtureDirectoryResolver.php Tests/Unit/Fixture/FixtureDirectoryResolverTest.php
git commit -m "[FEATURE] Add FixtureDirectoryResolver with path-traversal and case-insensitive EXT: handling"
```

---

### Task 3: Refactor `ImportFixturesCommand` — DI, DbalException/transaction fix, `--dry-run`, explicit sort

**Files:**
- Modify: `Classes/Command/ImportFixturesCommand.php`
- Test: `Tests/Functional/Command/ImportFixturesCommandTest.php`

**Interfaces:**
- Consumes:
  - `Cpsit\CpsUtility\Fixture\SqlStatementSplitter::split(string $sql): array` (Task 1)
  - `Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver::resolveBasePath(?string $directoryOption): ?string` (Task 2)
  - `Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver::resolveFixtureDirectory(string $basePath, ApplicationContext $context): ?string` (Task 2)
- Produces: `ImportFixturesCommand` constructor becomes `__construct(FixtureDirectoryResolver $directoryResolver, SqlStatementSplitter $statementSplitter, ConnectionPool $connectionPool)` — all three autowired via `Configuration/Services.yaml`'s existing `resource: '../Classes/*'` glob (no Services.yaml change needed; `public: false` services are still autowirable as constructor arguments).

This task's functional test file is written **before** the implementation changes (per TDD), so running it first against the *current* (pre-refactor) command will show some scenarios passing already (happy path, missing directory) and some failing (the DbalException/rollback bugs this task fixes, plus `--dry-run` which does not exist yet). That mixed initial result is expected and documents exactly which bugs this task fixes.

- [ ] **Step 1: Write the failing functional test**

Create `Tests/Functional/Command/ImportFixturesCommandTest.php`:

```php
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

    public function testHappyPathImportsFixtureFile(): void
    {
        $this->writeFixtureFile('dev', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Imported 1 fixture file(s). Skipped 0.', $tester->getDisplay());

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
        self::assertStringContainsString('does not exist. Nothing to import.', $tester->getDisplay());
    }

    public function testEmptyDirectoryReturnsSuccessWithNothingImported(): void
    {
        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No SQL fixture files found', $tester->getDisplay());
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
        self::assertStringContainsString('Imported 2 fixture file(s). Skipped 1.', $tester->getDisplay());

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

        $failingConnection = $this->createMock(Connection::class);
        $failingConnection->method('executeQuery')->willThrowException(
            new DbalException('Simulated connection failure', 1234)
        );

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($failingConnection);

        $tester = $this->getCommandTester($connectionPool);
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Database connection error', $tester->getDisplay());
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
        self::assertStringContainsString('01_admin.sql: 2 statement(s)', $tester->getDisplay());

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
        self::assertStringContainsString('disabled in Production environment', $tester->getDisplay());

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
        self::assertStringContainsString('Imported 1 fixture file(s). Skipped 0.', $tester->getDisplay());
    }

    public function testProductionStagingContextAutoImportsWithoutFlag(): void
    {
        $this->switchContext('Production/Staging');
        $this->writeFixtureFile('staging', '01_admin.sql', "INSERT INTO be_users (uid, username) VALUES (9999, 'fixture_admin');");

        $tester = $this->getCommandTester();
        $exitCode = $tester->execute(['--directory' => $this->fixtureBasePath]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Imported 1 fixture file(s). Skipped 0.', $tester->getDisplay());
    }
}
```

- [ ] **Step 2: Run the test to verify the expected mixed result**

Run:

```bash
typo3DatabaseDriver=pdo_sqlite .Build/vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml --filter ImportFixturesCommandTest
```

Expected: some tests FAIL —
- `testFailingFileIsFullyRolledBackAndOtherFilesStillImport` fails (uid 9002 is currently *not* rolled back — the pre-refactor command has no transaction).
- `testConnectionFailurePropagatesAsCommandFailure` fails (the pre-refactor command's inner `catch (\Exception $e)` currently swallows the mocked `DbalException` and returns `Command::SUCCESS`, plus the constructor signature does not yet accept `FixtureDirectoryResolver`/`SqlStatementSplitter` so this will actually fail with a constructor-argument-count `TypeError` first).
- `testDryRunReportsFileAndStatementCountsWithoutImporting` fails (`--dry-run` option does not exist yet — `execute()` throws for an undefined option).
- All other tests also currently fail with the same constructor `TypeError`, since the test file passes 3 constructor arguments to a class that today accepts only 1 (`ConnectionPool`).

This full-failure state (rather than a genuinely mixed pass/fail) is expected and confirms the test file is correctly wired to the target (post-refactor) interface before that interface exists.

- [ ] **Step 3: Write the implementation**

Replace the full contents of `Classes/Command/ImportFixturesCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Command;

use Cpsit\CpsUtility\Fixture\FixtureDirectoryResolver;
use Cpsit\CpsUtility\Fixture\SqlStatementSplitter;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(
    name: 'cpsit:import-fixtures',
    description: 'Import database fixtures from SQL files depending on current environment',
    aliases: ['cpsit:fixtures']
)]
final class ImportFixturesCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly FixtureDirectoryResolver $directoryResolver,
        private readonly SqlStatementSplitter $statementSplitter,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Import SQL fixture files for the current TYPO3 application context.' . PHP_EOL
                . PHP_EOL
                . 'The command resolves a context-specific subdirectory under the fixture base path:' . PHP_EOL
                . '  Development/Local → {base}/dev/' . PHP_EOL
                . '  Production/Staging → {base}/staging/' . PHP_EOL
                . '  Production         → {base}/production/' . PHP_EOL
                . PHP_EOL
                . 'Default base path: var/fixtures/ (never publicly accessible).' . PHP_EOL
                . 'Override with --directory, which accepts absolute paths or EXT: notation.' . PHP_EOL
                . 'Use --dry-run to list files and statement counts without executing anything.'
            )
            ->addOption(
                'directory',
                'd',
                InputOption::VALUE_REQUIRED,
                'Base directory for fixture files. Supports absolute paths and EXT: notation.',
            )
            ->addOption(
                'production',
                'p',
                InputOption::VALUE_NONE,
                'Allow fixture import in Production environment. Required when context is "Production".'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'List fixture files and statement counts without executing anything.'
            );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applicationContext = Environment::getContext();
        $contextKey = $applicationContext->__toString();
        $forceProduction = (bool)$input->getOption('production');
        $dryRun = (bool)$input->getOption('dry-run');

        // Guard: only the exact "Production" context is blocked without
        // --production. "Production/Preview" and "Production/Staging" are
        // intentionally left unguarded so staging/preview environments can
        // auto-seed their fixture set without any deploy-script changes.
        // This is a deliberate, reviewed design decision — see
        // Documentation/ImportFixturesCommand.md, "Production Guard" — not
        // an oversight. Do not widen this check to cover Production/* contexts.
        if ($contextKey === 'Production') {
            if (!$forceProduction) {
                $this->io->warning('Fixture import is disabled in Production environment. Use --production to override.');
                return Command::SUCCESS;
            }
            $this->io->note('Forcing fixture import in Production environment.');
        }

        $directoryOption = $input->getOption('directory');
        $basePath = $this->directoryResolver->resolveBasePath($directoryOption);

        if ($basePath === null) {
            $this->io->error(sprintf(
                'Cannot resolve fixture directory "%s". '
                . 'For EXT: paths use the extension key (underscores, not hyphens) and ensure the extension is loaded. '
                . 'For project-relative paths, ensure the path does not escape the project root.',
                $directoryOption
            ));
            return Command::FAILURE;
        }

        $fixtureDirectory = $this->directoryResolver->resolveFixtureDirectory($basePath, $applicationContext);

        if ($fixtureDirectory === null) {
            $this->io->info(sprintf(
                'No fixture directory configured for context "%s". Nothing to import.',
                $contextKey
            ));
            return Command::SUCCESS;
        }

        if (!is_dir($fixtureDirectory)) {
            $this->io->info(sprintf('Fixture directory "%s" does not exist. Nothing to import.', $fixtureDirectory));
            return Command::SUCCESS;
        }

        $fixtureFiles = GeneralUtility::getFilesInDir($fixtureDirectory, 'sql');

        if (empty($fixtureFiles)) {
            $this->io->info(sprintf('No SQL fixture files found in "%s".', $fixtureDirectory));
            return Command::SUCCESS;
        }

        // Own the ordering contract explicitly instead of relying on
        // scandir()'s incidental default order (GeneralUtility::getFilesInDir()
        // returns an array keyed by md5 hash; sort() re-indexes numerically
        // and sorts the filenames themselves).
        sort($fixtureFiles);

        if ($dryRun) {
            return $this->executeDryRun($fixtureDirectory, $fixtureFiles);
        }

        return $this->executeImport($fixtureDirectory, $fixtureFiles);
    }

    /**
     * @param list<string> $fixtureFiles
     */
    private function executeDryRun(string $fixtureDirectory, array $fixtureFiles): int
    {
        $this->io->info(sprintf(
            'Dry run: %d fixture file(s) found in "%s". Nothing will be imported.',
            count($fixtureFiles),
            $fixtureDirectory
        ));

        foreach ($fixtureFiles as $filename) {
            $filePath = $fixtureDirectory . '/' . $filename;
            $sql = file_get_contents($filePath);

            if ($sql === false || $sql === '') {
                $this->io->writeln(sprintf('  %s: unreadable or empty, would be skipped', $filename));
                continue;
            }

            $statementCount = count($this->statementSplitter->split($sql));
            $this->io->writeln(sprintf('  %s: %d statement(s)', $filename, $statementCount));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $fixtureFiles
     */
    private function executeImport(string $fixtureDirectory, array $fixtureFiles): int
    {
        $this->io->info(sprintf('Importing %d fixture file(s) from "%s"', count($fixtureFiles), $fixtureDirectory));

        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
            // Force an eager connectivity check here, outside the per-file
            // loop below, so a genuine database outage propagates to
            // Command::FAILURE instead of being swallowed by the per-file
            // catch (which is only meant to catch bad fixture SQL, not a
            // dead connection).
            $connection->executeQuery('SELECT 1');
        } catch (DbalException $e) {
            $this->io->error('Database connection error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $importedCount = 0;
        $skippedCount = 0;

        foreach ($fixtureFiles as $filename) {
            $filePath = $fixtureDirectory . '/' . $filename;
            $sql = file_get_contents($filePath);

            if ($sql === false || $sql === '') {
                $this->io->warning(sprintf('Skipping empty or unreadable file: %s', $filename));
                $skippedCount++;
                continue;
            }

            try {
                $connection->beginTransaction();
                foreach ($this->statementSplitter->split($sql) as $statement) {
                    $connection->executeStatement($statement);
                }
                $connection->commit();
                $this->io->writeln(sprintf('  Imported: %s', $filename));
                $importedCount++;
            } catch (\Throwable $e) {
                $connection->rollBack();
                $this->io->error(sprintf('Failed to import "%s": %s', $filename, $e->getMessage()));
                $skippedCount++;
            }
        }

        $this->io->success(sprintf(
            'Imported %d fixture file(s). Skipped %d.',
            $importedCount,
            $skippedCount
        ));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run:

```bash
typo3DatabaseDriver=pdo_sqlite .Build/vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml --filter ImportFixturesCommandTest
```

Expected: PASS, all 9 test methods green.

- [ ] **Step 5: Run the full test suite to check for regressions**

Run:

```bash
.Build/vendor/bin/phpunit -c Tests/Build/UnitTests.xml
typo3DatabaseDriver=pdo_sqlite .Build/vendor/bin/phpunit -c Tests/Build/FunctionalTests.xml
```

Expected: PASS across both suites (Task 1's and Task 2's unit tests plus this task's functional tests).

- [ ] **Step 6: Commit**

```bash
git add Classes/Command/ImportFixturesCommand.php Tests/Functional/Command/ImportFixturesCommandTest.php
git commit -m "[BUGFIX] Fix DbalException swallowing and missing per-file transactions in cpsit:import-fixtures; add --dry-run and explicit file sort"
```

---

### Task 4: Documentation and design-doc-citation correction

**Files:**
- Modify: `Documentation/ImportFixturesCommand.md`

**Interfaces:** None (documentation only, no code).

This task has no test cycle (it is documentation), but every edit below must be applied — do not skip any of the seven changes.

- [ ] **Step 1: Add `--dry-run` to the CLI Reference table**

In `Documentation/ImportFixturesCommand.md`, find this table (currently lines 37–40):

```
| Option | Short | Type | Default | Description |
|--------|-------|------|---------|--------------|
| `--directory=PATH` | `-d` | `VALUE_REQUIRED` | none at the CLI level — when omitted, the code resolves the base path to `Environment::getVarPath() . '/fixtures'` | Base directory for fixture files. Supports absolute paths and EXT: notation. |
| `--production` | `-p` | `VALUE_NONE` (flag) | not set | Allow fixture import in Production environment. Required when context is "Production". |
```

Replace with:

```
| Option | Short | Type | Default | Description |
|--------|-------|------|---------|--------------|
| `--directory=PATH` | `-d` | `VALUE_REQUIRED` | none at the CLI level — when omitted, the code resolves the base path to `Environment::getVarPath() . '/fixtures'` | Base directory for fixture files. Supports absolute paths and EXT: notation. |
| `--production` | `-p` | `VALUE_NONE` (flag) | not set | Allow fixture import in Production environment. Required when context is "Production". |
| `--dry-run` | none | `VALUE_NONE` (flag) | not set | List fixture files and their statement counts without executing anything. No transaction is opened and the database is left untouched. |
```

- [ ] **Step 2: Replace the sample password/hash with an unambiguous placeholder**

Find this block in the "Idempotency" subsection (currently lines 90–99):

```
# Default admin user (password = AdminPassword!1)
SET @username := 'admin';
SET @password := '$argon2i$v=19$m=65536,t=16,p=1$dnFPM3F2Z2J1S3RFWW96Mw$bwkXqsGRdSu98m6BpFY7kTekyRDbhN0Dsd8Ib4cQGBY';

INSERT INTO be_users (uid, username, password, admin)
VALUES (1, @username, @password, 1)
ON DUPLICATE KEY UPDATE username = @username,
                                        password = @password;
```

Replace with:

```
# Default admin user (this is a documentation placeholder, not a real
# credential — generate your own hash with `typo3/bin/typo3 t3faq...` or any
# argon2i hasher before using this pattern for a real fixture)
SET @username := 'admin';
SET @password := '$argon2i$v=19$m=65536,t=16,p=1$REPLACE_WITH_YOUR_OWN_SALT$REPLACE_WITH_YOUR_OWN_HASH';

INSERT INTO be_users (uid, username, password, admin)
VALUES (1, @username, @password, 1)
ON DUPLICATE KEY UPDATE username = @username,
                                        password = @password;
```

- [ ] **Step 3: Rewrite the "Comment Syntax" section to describe the new splitter**

Find the entire "Comment Syntax" subsection (currently lines 103–126, from the heading `### Comment Syntax` through the paragraph ending `...avoid literal ; or -- inside quoted SQL values.`).

Replace it with:

```
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
```

- [ ] **Step 4: Update "Execution Order & Fixture Dependencies" for the explicit sort**

Find the "Execution Order & Fixture Dependencies" section (currently lines 128–132).

Replace with:

```
## Execution Order & Fixture Dependencies

The command explicitly sorts the fixture file list alphabetically (`sort()` on the list returned by `GeneralUtility::getFilesInDir($fixtureDirectory, 'sql')`) before importing. This is now a guaranteed contract of the command, not an incidental side effect of `scandir()`'s default order.

**Practical implication:** if your fixtures have foreign-key dependencies on each other — for example, a categories table that must be populated before a table that references those category UIDs via a foreign key or MM-relation table — rely on numeric or alphabetical filename prefixes to control import order, e.g. `01_categories.sql` before `02_category_assignments.sql`.
```

- [ ] **Step 5: Update the "Behavior & Failure Semantics" table for the rollback fix and `--dry-run`**

Find the table row (currently within lines 134–148):

```
| A SQL statement in a fixture file throws during execution | Error: "Failed to import "...": ..." — exception caught **per file**, skip-counter incremented, loop continues to the next file, **no rollback** of statements already applied from that same file | `Command::SUCCESS` (at end) | Other files: yes; this file: partially (whatever ran before the failing statement stays applied) |
```

Replace with:

```
| A SQL statement in a fixture file throws during execution | Error: "Failed to import "...": ..." — exception caught **per file**, the file's transaction is rolled back in full, skip-counter incremented, loop continues to the next file | `Command::SUCCESS` (at end) | Other files: yes; this file: no — any statements from that file that ran before the failing one are rolled back, not left partially applied |
```

Then, directly after the row for `DbalException while acquiring the database connection`, add a new row for `--dry-run`:

```
| `--dry-run` passed | Info: dry-run summary line, then one line per file with its statement count (or "unreadable or empty, would be skipped") | `Command::SUCCESS` | No — no transaction is opened, no statement is executed |
```

Also update the paragraph immediately following the table (currently starting `> **The command effectively never returns Command::FAILURE due to bad fixture SQL.**`) — the claim itself is still true, but "including a fixture file throwing mid-import" should be clarified. Replace:

```
> **The command effectively never returns `Command::FAILURE` due to bad fixture SQL.** Only a connection-acquisition failure (`DbalException`) or a base-path resolution failure (bad `EXT:` key) produce a non-zero exit code — every other failure mode, including a fixture file throwing mid-import, is swallowed into a `SUCCESS` exit with a printed skip count. **A CI/deploy pipeline that only checks the exit code will not detect that fixtures were silently partially or fully skipped.** If you rely on this command in an automated pipeline, also inspect its output for skip counts and per-file error lines — do not trust the exit status alone.
```

with:

```
> **The command effectively never returns `Command::FAILURE` due to bad fixture SQL.** Only a connectivity failure (`DbalException`, checked once before any file is processed) or a base-path resolution failure (bad `EXT:` key, or a project-relative `--directory` that escapes the project root) produce a non-zero exit code — a fixture file throwing mid-import is caught per file, that file's transaction is fully rolled back, and the command continues with the next file, ending in a `SUCCESS` exit with a printed skip count. **A CI/deploy pipeline that only checks the exit code will not detect that one or more fixture files were silently and fully skipped.** If you rely on this command in an automated pipeline, also inspect its output for skip counts and per-file error lines — do not trust the exit status alone. Consider `--dry-run` as a pre-flight check in CI.
```

- [ ] **Step 6: Strengthen the "Production Guard" scope note**

Find the "Production Guard" section (currently lines 152–156). Append one sentence to the end of the existing "Scope clarification" paragraph — after `...it protects only the exact Production context.` add:

```
 This has been explicitly reviewed and confirmed as intentional (not a gap to be closed): staging/preview environments are meant to auto-seed their `staging` fixture set on every deploy without requiring a `--production` flag or any deploy-script changes. `FixtureDirectoryResolver::CONTEXT_SUBDIRECTORY_MAP` carries a code comment to the same effect, and `ImportFixturesCommandTest::testProductionStagingContextAutoImportsWithoutFlag()` locks this behavior in as a regression test.
```

- [ ] **Step 7: Update the Troubleshooting table row about partial imports**

Find this row (currently line 169):

```
| A fixture file's SQL produced an error but the command reported overall success | Each fixture file's SQL execution is wrapped in its own try/catch with no rollback and no propagation to the command's exit code — a per-file failure only increments the skip counter and logs an error line for that file, per "Behavior & Failure Semantics" above. | Do not trust the exit code alone. Check the printed "Failed to import "...": ..." error line and the final "Imported X fixture file(s). Skipped Y." summary for a nonzero skip count. Consider validating fixture SQL independently (e.g. a dry-run against a scratch database) in CI, since this command's exit code will not surface the failure. |
```

Replace with:

```
| A fixture file's SQL produced an error but the command reported overall success | Each fixture file's SQL execution runs inside its own transaction with its own try/catch; on any error that file's transaction is rolled back in full and the skip counter is incremented, per "Behavior & Failure Semantics" above. The command's exit code is not affected by this. | Do not trust the exit code alone. Check the printed "Failed to import "...": ..." error line and the final "Imported X fixture file(s). Skipped Y." summary for a nonzero skip count. Use `--dry-run` to validate fixture files (file list and statement counts) ahead of a real run, since this command's exit code will not surface a per-file SQL failure. |
```

- [ ] **Step 8: Verify the edits render correctly and commit**

Run:

```bash
git diff Documentation/ImportFixturesCommand.md
```

Read through the full diff once to confirm all seven changes above are present, no other lines were accidentally altered, and no markdown table got misaligned. Then:

```bash
git add Documentation/ImportFixturesCommand.md
git commit -m "[TASK] Update ImportFixturesCommand docs for rollback, splitter, dry-run and sort changes"
```

Note on `ChangeLog`: per the "Design-doc citation correction" section at the top of this plan, do **not** hand-edit `ChangeLog` as part of this feature. Repo convention (confirmed via `git show` on `ab8b8f0`, `98086e7`, `d6ab2c7`, `bb05e5b`) is that `ChangeLog` is only ever updated by a dedicated `[RELEASE]` commit that bulk-appends one line per commit since the previous release, using each commit's real (already-existing) SHA. This feature's `ChangeLog` entries will be added automatically the next time a release is cut.

---

## Self-Review Notes

- **Spec coverage:** All design-doc items are covered — `SqlStatementSplitter` (Task 1), `FixtureDirectoryResolver` including case-insensitive `EXT:` and traversal rejection (Task 2), DI refactor + DbalException fix + per-file transactions + `--dry-run` + explicit `sort()` (Task 3), documentation for every behavior-change row in the design's "Behavior changes summary" table plus the "Guard Scope" note and password-hash placeholder (Task 4). The intentionally-unchanged Production guard is preserved and locked in by `testProductionStagingContextAutoImportsWithoutFlag`.
- **Placeholder scan:** No "TBD"/"add appropriate handling"/"similar to Task N" phrasing anywhere above; every step shows the exact code or exact markdown diff to apply.
- **Type consistency:** `SqlStatementSplitter::split(string $sql): array` (Task 1) is called identically in Task 3's `executeDryRun()`/`executeImport()`. `FixtureDirectoryResolver::resolveBasePath(?string): ?string` and `resolveFixtureDirectory(string, ApplicationContext): ?string` (Task 2) are called with matching argument types/order in Task 3's `execute()`. `FixtureDirectoryResolver::CONTEXT_SUBDIRECTORY_MAP` fully replaces (not duplicates) the old `ImportFixturesCommand::CONTEXT_SUBDIRECTORY_MAP` constant, which is removed in Task 3's replacement file.
- **Flagged inconsistencies with the design doc** (both already called out inline above, repeated here for visibility): (1) the design doc's testing-strategy section implicitly assumes a working PHPUnit bootstrap, but `composer.json` had no `typo3/testing-framework`/PHPUnit dependency and no matching `vendor-dir` — fixed in Task 1. (2) The design doc's ChangeLog instruction cites commit `d6ab2c7` as a convention example, but that commit never touched `ChangeLog`; the actual convention (bulk-append only at `[RELEASE]` commits) is documented in Task 4 instead of followed literally.
