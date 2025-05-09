<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\ViewHelpers;

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInterface;

/**
 * Get last processed image properties
 */
class ProcessedMediaPropertiesViewHelper extends AbstractViewHelper implements ViewHelperInterface
{
    use CompileWithRenderStatic;

    public const ALLOWED_PROPERTIES = ['width', 'height', 'extension', 'resource', 'origFile', 'modificationTime'];

    /**
     * Initialize arguments
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();
        $this->registerArgument('property', 'string', 'either width or height', true);
        $this->registerArgument('resource', 'string', 'Image processed relative url', true);
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return int
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ) {
        $value = null;

        if (!in_array($arguments['property'], self::ALLOWED_PROPERTIES)) {
            throw new \RuntimeException(sprintf(
                'The value "%s" is not supported in LastProcessedImageSizeViewHelper',
                $arguments['property']
            ), 3682001728);
        }

        $mediaOnPage = GeneralUtility::makeInstance(AssetCollector::class)->getMedia();

        // The keys in the returned array from the AssetCollector->getMedia
        // method ist not consistent between different TYPO3 versions
        $media = $mediaOnPage[$arguments['resource']] ?? $mediaOnPage[ltrim($arguments['resource'], '/')] ?? null;

        if (is_array($media) && !empty($media)) {
            $getter = 'get' . ucfirst($arguments['property']);

            if (method_exists(__CLASS__, $getter)) {
                $value = self::$getter($media);
            }
        }
        return $value;
    }

    protected static function getWidth(array $media): int
    {
        return $media[0] ?? 0;
    }

    protected static function getHeight(array $media): int
    {
        return $media[1] ?? 0;
    }

    protected static function getExtension(array $media): string
    {
        return $media[2] ?? '';
    }

    protected static function getResource(array $media): string
    {
        return $media[3] ?? '';
    }

    protected static function getOrigFile(array $media): string
    {
        return $media['origFile'] ?? '';
    }

    protected static function getModificationTime(array $media): string
    {
        return $media['origFile_mtime'] ?? '';
    }
}
