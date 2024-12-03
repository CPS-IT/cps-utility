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
 * Trims $content by stripping off $characters (string list
 * of individual chars to strip off, default is all whitespaces).
 *
 * Usage:
 * <fr:format.trim characters="|">trim the pipe |</fr:format.trim>
 *
 * Inline notation
 * {fr:format.trim(content:'trim the pipe |', characters:'| t')}
 */
class TrimViewHelper extends AbstractViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

    public function initializeArguments()
    {
        $this->registerArgument('content', 'string', 'String to trim');
        $this->registerArgument('characters', 'string', 'List of characters to trim, no separators, e.g. "abc123"');
    }

    /**
     * Trims content by stripping off $characters
     *
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return mixed
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ) {
        $characters = $arguments['characters'];
        $content = $renderChildrenClosure();
        if (empty($characters) === false) {
            $content = trim($content, $characters);
        } else {
            $content = trim($content);
        }
        return $content;
    }
}
