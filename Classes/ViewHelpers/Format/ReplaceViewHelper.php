<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\ViewHelpers\Format;

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * Back ported from https://github.com/FluidTYPO3/vhs
 */

use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithContentArgumentAndRenderStatic;

/**
 * Replaces $substring in $content with $replacement.
 */
class ReplaceViewHelper extends AbstractViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

    public function initializeArguments()
    {
        $this->registerArgument('content', 'string', 'Content in which to perform replacement');
        $this->registerArgument('substring', 'string', 'Substring to replace', true);
        $this->registerArgument('replacement', 'string', 'Replacement to insert', false, '');
        $this->registerArgument('count', 'integer', 'Maximum number of times to perform replacement');
        $this->registerArgument('caseSensitive', 'boolean', 'If true, perform case-sensitive replacement', false, true);
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return mixed
     */
    public static function renderStatic(array $arguments, \Closure $renderChildrenClosure, RenderingContextInterface $renderingContext)
    {
        $content = $renderChildrenClosure();
        $substring = $arguments['substring'];
        $replacement = $arguments['replacement'];
        $count = (int)$arguments['count'];
        $caseSensitive = (bool)$arguments['caseSensitive'];
        $function = ($caseSensitive === true ? 'str_replace' : 'str_ireplace');
        return $function($substring, $replacement, $content, $count);
    }
}
