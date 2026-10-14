<?php

declare(strict_types=1);

/*
 * This file is part of the gebaeudeforum-bundle project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Traits;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Add fe cache tags trait
 *
 * Usage $this->>addCacheTags(['tag1',[tag2]])
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

            if (!$cacheTagsSet) {
                $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
                $cacheCollector = $request instanceof ServerRequestInterface ? $request->getAttribute('frontend.cache.collector') : null;
                if ($cacheCollector !== null) {
                    // TYPO3 13.3+: the TSFE method is deprecated, CacheTag does not exist on 12.4
                    $cacheCollector->addCacheTags(...array_map(static fn(string $tag): CacheTag => new CacheTag($tag), $tags));
                } else {
                    $this->_getTypoScriptFrontendController()->addCacheTags($tags);
                }
                $cacheTagsSet = true;
            }
        }
    }

    protected function _isFrontEndContext(): bool
    {
        return !empty($GLOBALS['TSFE']) && is_object($GLOBALS['TSFE']);
    }

    protected function _getTypoScriptFrontendController(): TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'] ?? GeneralUtility::makeInstance(TypoScriptFrontendController::class);
    }
}
