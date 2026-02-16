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

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Trims $content by stripping off $characters (string list
 * of individual chars to strip off, default is all whitespaces).
 *
 * Usage:
 * <cps:format.trim characters="|">trim the pipe |</cps:format.trim>
 *
 * Inline notation
 * {cps:format.trim(content:'trim the pipe |', characters:'|')}
 */
class TrimViewHelper extends AbstractViewHelper
{
    #[\Override]
    public function initializeArguments(): void
    {
        $this->registerArgument('content', 'string', 'String to trim');
        $this->registerArgument('characters', 'string', 'List of characters to trim, no separators, e.g. "abc123"');
    }

    /**
     * Trims content by stripping off $characters
     */
    #[\Deprecated(message: 'use \TYPO3Fluid\Fluid\ViewHelpers\Format\TrimViewHelper instead')]
    public function render(): string
    {
        $characters = $this->arguments['characters'];
        $content = $this->renderChildren();
        if (empty($characters) === false) {
            $content = trim((string)$content, $characters);
        } else {
            $content = trim((string)$content);
        }
        return $content;
    }

    public function getContentArgumentName(): string
    {
        return 'content';
    }
}
