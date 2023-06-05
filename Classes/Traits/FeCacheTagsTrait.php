<?php
/*
 * This file is part of the gebaeudeforum-bundle project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Traits;


use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Add fe cache tags trait
 *
 * Usage $this->>addCacheTags(['tag1',[tag2]])
 *
 */
trait FeCacheTagsTrait
{
    /**
     * @param string[] $tags
     */
    public function addCacheTags(array $tags): void
    {
        // Only do this in Frontend Context
        if ($this->_isFrontEndContext()) {
            // We only want to set the tag once in one request, so we have to cache that statically if it has been done
            static $cacheTagsSet = false;

            $typoScriptFrontendController = $this->_getTypoScriptFrontendController();
            if (!$cacheTagsSet) {
                $typoScriptFrontendController->addCacheTags($tags);
                $cacheTagsSet = true;
            }
        }
    }

    /**
     * @return bool
     */
    protected function _isFrontEndContext(): bool
    {
        return !empty($GLOBALS['TSFE']) && is_object($GLOBALS['TSFE']);
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected function _getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'] ?? GeneralUtility::makeInstance(TypoScriptFrontendController::class);
    }
}
