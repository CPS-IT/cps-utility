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

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper to render data in <head> section of website
 *
 * # Example: Basic example
 * <code>
 * <lib:headerData>
 *     <link rel="alternate"
 *          type="application/rss+xml"
 *          title="RSS 2.0"
 *          href="{f:uri.page(additionalParams: '{type:1600292946}')}" />
 * </lib:headerData>
 * </code>
 * <output>
 * Added to the header:
 * <link rel="alternate"
 *     type="application/rss+xml"
 *     title="RSS 2.0"
 *     href="uri to this page and type 9818" />
 * </output>
 */
class HeaderDataViewHelper extends AbstractViewHelper
{
    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     */
    public function render(): void
    {
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addHeaderData($this->renderChildren());
    }
}
