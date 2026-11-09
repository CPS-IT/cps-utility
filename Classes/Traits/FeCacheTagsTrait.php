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
        // We only want to set the tag once in one request, so we have to cache that statically if it has been done
        static $cacheTagsSet = false;

        if ($cacheTagsSet) {
            return;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $cacheCollector = $request instanceof ServerRequestInterface ? $request->getAttribute('frontend.cache.collector') : null;
        // Only available in Frontend Context
        if ($cacheCollector !== null) {
            $cacheCollector->addCacheTags(...array_map(static fn(string $tag): CacheTag => new CacheTag($tag), $tags));
            $cacheTagsSet = true;
        }
    }
}
