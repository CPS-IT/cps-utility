<?php

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Rendering;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperInterface;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\Rendering\FileRendererInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Vimp renderer class
 *
 * TypoScript configuration:
 * lib.contentElement {
 *     settings {
 *         media {
 *             additionalConfig {
 *                 frVideoRenderer {
 *                     vimp.srcAttribute = data-src
 *                 }
 *             }
 *         }
 *     }
 * }
 */
class VimpRenderer implements FileRendererInterface
{
    protected OnlineMediaHelperInterface|null|false $onlineMediaHelper = null;

    /**
     * Returns the priority of the renderer
     * This way it is possible to define/overrule a renderer
     * for a specific file type/context.
     * For example create a video renderer for a certain storage/driver type.
     * Should be between 1 and 100, 100 is more important than 1
     */
    public function getPriority() : int
    {
        return 2;
    }

    public function canRender(FileInterface $file) : bool
    {
        return ($file->getMimeType() === 'video/vimp' || $file->getExtension() === 'vimp') && $this->getOnlineMediaHelper($file) !== false;
    }

    protected function getOnlineMediaHelper(FileInterface $file) :? OnlineMediaHelperInterface
    {
        if ($this->onlineMediaHelper === null) {
            $orgFile = $file;
            if ($orgFile instanceof FileReference) {
                $orgFile = $orgFile->getOriginalFile();
            }
            if ($orgFile instanceof File) {
                $this->onlineMediaHelper = GeneralUtility::makeInstance(OnlineMediaHelperRegistry::class)->getOnlineMediaHelper($orgFile);
            } else {
                $this->onlineMediaHelper = false;
            }
        }

        return $this->onlineMediaHelper ?? null;
    }

    protected function collectOptions(array $options, FileInterface $file) : array
    {
        $attributes = [];

        if ($options['additionalConfig']['class'] ?? null) {
            $attributes['class'] = trim($options['additionalConfig']['class']);
        }

        if (!empty($options['additionalAttributes']) && is_array($options['additionalAttributes'])) {
            $attributes = array_merge($attributes, $options['additionalAttributes']);
        }

        if ($options['data'] ?? null && is_array($options['data'])) {
            $attributes['data'] = $options['data'];
        }

        return $attributes;
    }

    protected function collectIframeAttributes($width, $height, array $options) : array
    {
        $attributes = [];
        if ($options['allowfullscreen'] ?? true) {
            $attributes['allow'] = 'fullscreen';
        }

        if ((int)$width > 0) {
            $attributes['width'] = (int)$width;
        }
        if ((int)$height > 0) {
            $attributes['height'] = (int)$height;
        }
        if ($this->shouldIncludeFrameBorderAttribute()) {
            $attributes['frameborder'] = 0;
        }

        if ($options['data'] ?? null && is_array($options['data'])) {
            array_walk(
                $options['data'],
                static function (string $value, string|int $key) use (&$attributes): void {
                    $attributes['data-' . $key] = $value;
                }
            );
        }

        foreach (['class', 'dir', 'id', 'lang', 'style', 'title', 'accesskey', 'tabindex', 'onclick', 'allow'] as $key) {
            if (!empty($options[$key])) {
                $attributes[$key] = $options[$key];
            }
        }

        return $attributes;
    }

    protected function shouldIncludeFrameBorderAttribute(): bool
    {
        return GeneralUtility::makeInstance(PageRenderer::class)->getDocType()->shouldIncludeFrameBorderAttribute();
    }

    protected function createVimpUrl(array $options, FileInterface $file) :? string
    {
        $videoId = $this->getVideoIdFromFile($file);

        $urlParams = [];
        if (empty($videoId))
        {
            return null;
        }

        $urlParams[] = sprintf('key=%s', $videoId);

        return sprintf('https://video.z-u-g.org/media/embed?%s', implode('&', $urlParams));
    }

    protected function getVideoIdFromFile(FileInterface $file)
    {
        if ($file instanceof FileReference) {
            $orgFile = $file->getOriginalFile();
        } else {
            $orgFile = $file;
        }

        return $this->getOnlineMediaHelper($file)->getOnlineMediaId($orgFile);
    }

    protected function implodeAttributes(array $attributes) : string
    {
        $attributeList = [];
        foreach ($attributes as $name => $value) {
            $name = preg_replace('/[^\p{L}0-9_.-]/u', '', $name);
            if ($value === true) {
                $attributeList[] = $name;
            } else {
                $attributeList[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5) . '"';
            }
        }

        return implode(' ', $attributeList);
    }


    /**
     * Render for given File(Reference) html output
     *
     * @param FileInterface $file
     * @param int|string $width TYPO3 known format; examples: 220, 200m or 200c
     * @param int|string $height TYPO3 known format; examples: 220, 200m or 200c
     * @param array $options
     * @param bool $usedPathsRelativeToCurrentScript See $file->getPublicUrl()
     * @return string
     */
    public function render(
        FileInterface $file,
        $width,
        $height,
        array $options = [],
        $usedPathsRelativeToCurrentScript = false
    ) :? string
    {
        $options = $this->collectOptions($options, $file);
        $src = $this->createVimpUrl($options, $file);
        if (!$src)
        {
            return null;
        }

        $attributes = $this->collectIframeAttributes($width, $height, $options);

        $srcAttribute = $options['additionalConfig']['frVideoRenderer']['vimeo']['srcAttribute'] ?? 'src';

        return sprintf(
            '<iframe %1$s="%2$s"%3$s></iframe>',
            $srcAttribute,
            htmlspecialchars($src, ENT_QUOTES | ENT_HTML5),
            empty($attributes) ? '' : ' ' . $this->implodeAttributes($attributes)
        );
    }
}
