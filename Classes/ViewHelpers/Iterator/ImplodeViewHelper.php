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

use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithContentArgumentAndRenderStatic;

/**
 * Implode view helper implode an array by a string.
 * Wrapper for PHPs :php:`implode` function.
 * See https://www.php.net/manual/en/function.implode.php
 *
 * Examples
 * ========
 *
 * Default
 * -------
 *
 * ::
 *
 *    <f:iterator.implode glue="," as="varName">{array}</f:iterator.explode>
 *
 * Imploded array in string.
 *
 * Inline notation
 * ---------------
 *
 * ::
 *
 *    {array_to_implode -> f:iterator.implode()}
 *
 * Imploded array in string.
 */
class ImplodeViewHelper extends AbstractViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

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
        $this->registerArgument('content', 'array', 'Arras to be imploded by glue');
        $this->registerArgument(
            'glue',
            'string',
            'String "glue" that separates values. If you need a constant (like PHP_EOL), use v:const to read it.',
            false,
            ','
        );
        $this->registerArgument(
            'as',
            'string',
            'Name of variable to create, if not given an string is returned',
            false
        );
    }

    /**
     * Applies implode() on the specified value.
     *
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param \TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface $renderingContext
     * @return array|null
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ): ?string {
        if (!is_array($arguments['content'])) {
            return null;
        }
        $content = $arguments['content'] ?? $renderChildrenClosure();
        $glue = $arguments['glue'];
        $value = implode($glue, $content);

        if (!$arguments['as']) {
            return $value;
        }

        $renderingContext->getVariableProvider()->add($arguments['as'], $value);
        return null;
    }
}
