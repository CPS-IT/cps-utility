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
