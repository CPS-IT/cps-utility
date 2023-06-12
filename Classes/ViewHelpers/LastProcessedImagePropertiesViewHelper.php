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

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;


/**
 * Get last processed image properties
 * @deprecated Replace with ProcessedMediaPropertiesViewHelper
 * See: https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/10.3/Deprecation-90522-TSFEPropertiesRegardingImages.html
 */
class LastProcessedImagePropertiesViewHelper extends ProcessedMediaPropertiesViewHelper
{
    use CompileWithRenderStatic;

    const ALLOWED_PROPERTIES = ['width', 'height', 'size'];

    /**
     * Initialize arguments
     */
    public function initializeArguments()
    {
        $this->registerArgument('property', 'string', 'either width or height', true);
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
        $typo3Version = new Typo3Version();
        if ($typo3Version->getMajorVersion() > 10) {
            throw new \RuntimeException(sprintf('
            TYPO3 V-%s does not longer support this view helper. Use ProcessedMediaPropertiesViewHelper instead.',
                $typo3Version->getMajorVersion()));
        }

        if (!in_array($arguments['property'], self::ALLOWED_PROPERTIES)) {
            throw new \RuntimeException(sprintf('The value "%s" is not supported in LastProcessedImageSizeViewHelper',
                $arguments['property']));
        }

        $lastImageInfo = self::getTypoScriptFrontendController()->lastImageInfo;

        if (isset($lastImageInfo[3])) {
            $mediaOnPage = GeneralUtility::makeInstance(AssetCollector::class)->getMedia();
            $media = $mediaOnPage[$lastImageInfo[3]] ?? $mediaOnPage[ltrim($lastImageInfo[3], '/')] ?? null;

            if(is_array($media) && !empty($media)) {
                $getter = 'get' . ucfirst($arguments['property']);
                if (method_exists(__CLASS__, $getter)) {
                    return self::$getter($media);
                }
            }
        }
        return null;
    }

    /**
     * @return TypoScriptFrontendController
     */
    protected static function getTypoScriptFrontendController()
    {
        return $GLOBALS['TSFE'];
    }
}
