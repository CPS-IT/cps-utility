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
 * Replaces $substring in $content with $replacement.
 *
 * Usage:
 * <cps:format.replace substring="test" replacement="replaced">Content in which to perform test replacement</cps:format.replace>
 *
 * Inline notation
 * {cps:format.replace(content:'Content in which to perform test replacement', substring:'test', replacement:'replaced')}
 */
class ReplaceViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('content', 'string', 'Content in which to perform replacement');
        $this->registerArgument('substring', 'string', 'Substring to replace', true);
        $this->registerArgument('replacement', 'string', 'Replacement to insert. If not provided ', false, '');
        $this->registerArgument('count', 'integer', 'Maximum number of times to perform replacement');
        $this->registerArgument('caseSensitive', 'boolean', 'If true, perform case-sensitive replacement', false, true);
    }

    #[\Override]
    public function render(): string
    {
        $content = $this->renderChildren();
        $substring = $this->arguments['substring'];
        $replacement = $this->arguments['replacement'] ?? '';
        $count = (int)$this->arguments['count'] ?? null;
        $caseSensitive = (bool)$this->arguments['caseSensitive'];
        $function = ($caseSensitive === true ? 'str_replace' : 'str_ireplace');
        return $function($substring, $replacement, $content, $count);
    }

    public function getContentArgumentName(): string
    {
        return 'content';
    }
}
