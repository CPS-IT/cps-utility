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
            // TYPO3's PathUtility::isExtensionPath() (used internally by
            // getFileAbsFileName()) only recognises the exact uppercase
            // "EXT:" prefix. Normalise the casing here so lowercase "ext:"
            // is resolved as an extension path too, instead of silently
            // falling through to core's "relative path" branch.
            $normalizedDirectoryOption = 'EXT:' . substr($directoryOption, 4);
            $resolved = GeneralUtility::getFileAbsFileName($normalizedDirectoryOption);
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
