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

/**
 * Explode view helper Split a string by a string.
 * Wrapper for PHPs :php:`explode` function.
 * See https://www.php.net/manual/en/function.explode.php
 *
 * Examples
 * ========
 *
 * Default
 * -------
 *
 * ::
 *
 *    <f:iterator.explode glue="," limit="10" as="varName">text,text,text</f:iterator.explode>
 *
 * Exploded Text in an array.
 *
 * Inline notation
 * ---------------
 *
 * ::
 *
 *    {text_to_split -> f:iterator.explode()}
 *
 * Exploded Text in an array.
 */
class ExplodeViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * Initialize arguments.
     *
     * @throws \TYPO3Fluid\Fluid\Core\ViewHelper\Exception
     */
    public function initializeArguments(): void
    {
        $this->registerArgument('content', 'string', 'String to be exploded by glue');
        $this->registerArgument(
            'glue',
            'string',
            'String "glue" that separates values. If you need a constant (like PHP_EOL), use v:const to read it.',
            false,
            ','
        );
        $this->registerArgument(
            'limit',
            'int',
            'If limit is set and positive, the returned array will contain a maximum of limit elements with the last ' .
            'element containing the rest of string. If the limit parameter is negative, all components except the ' .
            'last-limit are returned. If the limit parameter is zero, then this is treated as 1.',
            false,
            PHP_INT_MAX
        );
        $this->registerArgument(
            'as',
            'string',
            'Name of variable to create, if not given an array is returned',
            false
        );
    }

    /**
     * Applies explode() on the specified value.
     */
    #[\Override]
    public function render(): ?array
    {
        $content = $this->renderChildren();
        $glue = $this->arguments['glue'];
        $limit = $this->arguments['limit'] ?? PHP_INT_MAX;
        $value = explode($glue, (string)$content, $limit);

        if ($value === false) {
            $value = [];
        }

        if ($this->hasArgument('as')) {
            $this->renderingContext->getVariableProvider()->add($this->arguments['as'], $value);
            return null;
        }

        return $value;
    }

    public function getContentArgumentName(): string
    {
        return 'content';
    }
}
