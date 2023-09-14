<?php
/*
 * This file is part of the iki-project-import project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Utility;

use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RecordSlugDecorator
{
    public function decorate(array $record, string $table, string $field, int $altPid = 0): string
    {
        $tcaConfiguration = $this->getSlugFieldConfigurationFromTca($table, $field);

        // Early return slug can not be generated if not TCA
        if (!$tcaConfiguration) {
            return '';
        }

        $slugService = GeneralUtility::makeInstance(
            SlugHelper::class,
            $table,
            $field,
            $tcaConfiguration
        );

        $pid = $record['pid'] ?? $altPid;

        return $slugService->generate($record, $pid);
    }

    public function getSlugFieldConfigurationFromTca(string $table, string $field): ?array
    {
        return $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;
    }
}
