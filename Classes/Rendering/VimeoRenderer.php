<?php

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Rendering;

use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Rendering\VimeoRenderer;

/**
 * Vimeo renderer class
 *
 * TypoScript configuration:
 * lib.contentElement {
 *     settings {
 *         media {
 *             additionalConfig {
 *                 frVideoRenderer {
 *                     youTube.srcAttribute = data-src
 *                     vimeo.srcAttribute = data-src
 *                 }
 *             }
 *         }
 *     }
 * }
 */
class VimeoRenderer extends VimeoRenderer
{
    /**
     * Returns the priority of the renderer
     * This way it is possible to define/overrule a renderer
     * for a specific file type/context.
     * For example create a video renderer for a certain storage/driver type.
     * Should be between 1 and 100, 100 is more important than 1
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 2;
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
    ): string {
        $options = $this->collectOptions($options, $file);
        $src = $this->createVimeoUrl($options, $file);
        $attributes = $this->collectIframeAttributes($width, $height, $options);

        $srcAttribute = $options['additionalConfig']['frVideoRenderer']['vimeo']['srcAttribute'] ?? 'src';

        return sprintf(
            '<iframe ' . $srcAttribute . '="%s"%s></iframe>',
            htmlspecialchars($src, ENT_QUOTES | ENT_HTML5),
            empty($attributes) ? '' : ' ' . $this->implodeAttributes($attributes)
        );
    }
}
