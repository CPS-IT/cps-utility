<?php

declare(strict_types=1);

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\ViewHelpers\Iterator;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception;

/**
 * SliceViewHelper
 *
 * Extracts a slice of an array in Fluid templates, similar to PHP's array_slice.
 *
 * ## Usage example in a Fluid template:
 *
 * <cps:iterator.slice content="{myArray}" offset="1" length="3" preserveKeys="1" />
 *
 * Inline notation:
 * {myArray -> cps:iterator.slice(offset: 1, length: 3, preserveKeys: 1)}
 *
 * Replace cps: with the correct namespace alias for this ViewHelper.
 */
final class SliceViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('content', 'array', 'Input array');
        $this->registerArgument(
            'offset',
            'integer',
            'If offset is non-negative, the sequence will start at that offset in the array.
            If offset is negative, the sequence will start that far from the end of the array.',
            false,
            0
        );

        $this->registerArgument(
            'length',
            'integer',
            'If length is given and is positive, then the sequence will have that many elements in it.
            If length is given and is negative then the sequence will stop that many elements from the end of the array.
            If it is omitted, then the sequence will have everything from offset up until the end of the array',
            false
        );

        $this->registerArgument(
            'preserveKeys',
            'boolean',
            'If set to true keys will be preserved. Default is false which reindexes the array numerically.',
            false,
            false
        );
        $this->registerArgument(
            'as',
            'string',
            'Name of variable to create, if not given an string is returned',
            false
        );
    }

    public function render(): ?array
    {
        $content = $this->arguments['content'] ?? $this->renderChildren();

        if ($content === null) {
            throw new Exception('ArraySliceViewHelper requires a subject');
        }

        if (!is_array($content)) {
            throw new Exception('ArraySliceViewHelper requires an array as subject');
        }

        $length = null;
        if ($this->hasArgument('length')) {
            $length = (int)$this->arguments['length'];
        }

        $result = array_slice(
            $content,
            (int)$this->arguments['offset'],
            $length,
            (bool)$this->arguments['preserveKeys']
        );

        if (!$this->hasArgument['as']) {
            return $result;
        }

        $this->renderingContext->getVariableProvider()->add($this->arguments['as'], $result);
        return null;
    }

}
