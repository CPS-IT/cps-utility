<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\PageTitle;

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\PageTitle\AbstractPageTitleProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Generate page title based on array properties
 */
class TitleProvider extends AbstractPageTitleProvider
{
    private const DEFAULT_PROPERTIES = 'title';

    /**
     * @param array $record
     * @param array $configuration
     */
    public function setTitleFromArrayProperties(array $record, array $configuration = []): void
    {
        $title = '';
        $fields = GeneralUtility::trimExplode(',', $configuration['properties'] ?? self::DEFAULT_PROPERTIES, true);

        foreach ($fields as $field) {
            $value = $record[$field] ?? null;
            if ($value) {
                $title = $value;
                break;
            }
        }
        if ($title) {
            $this->title = $title;
        }
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
