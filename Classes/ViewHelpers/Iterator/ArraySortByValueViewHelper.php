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

use Closure;
use Exception;
use Traversable;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithContentArgumentAndRenderStatic;

/**
 * Sorts a simple array, iterable or csv by value.
 * Wrapper for PHPs :php: `asort, arsort, shuffle` array functions.
 * See https://www.php.net/manual/en/ref.array.php
 *
 * Examples
 * ========
 *
 * Default
 * -------
 *
 * ::
 *
 *    <fr:iterator.sortByValue order="ASC" sortFlags="SORT_STRING, SORT_FLAG_CASE, SORT_NATURAL" as="sorted">{subject}</f:iterator.sortByValue>
 *
 * ... sorted array
 *
 * Inline notation
 * ---------------
 *
 * ::
 *
 *    {text_to_split -> f:iterator.sortByValue()}
 *
 * ... sorted array
 */
class ArraySortByValueViewHelper extends AbstractViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

    /**
     * @var bool
     */
    protected $escapeChildren = false;

    /**
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * Contains all flags that are allowed to be used
     * with the sorting functions
     *
     * @var array
     */
    protected static array $allowedSortFlags = [
        'SORT_REGULAR',
        'SORT_STRING',
        'SORT_NUMERIC',
        'SORT_NATURAL',
        'SORT_LOCALE_STRING',
        'SORT_FLAG_CASE',
    ];

    /**
     * Initialize arguments.
     *
     * @throws \TYPO3Fluid\Fluid\Core\ViewHelper\Exception
     */
    public function initializeArguments()
    {
        $this->registerArgument('subject', 'mixed', 'The array/Traversable instance to sort');
        $this->registerArgument(
            'order',
            'string',
            'ASC, DESC, RAND or SHUFFLE. RAND preserves keys, SHUFFLE does not - but SHUFFLE is faster',
            false,
            'ASC'
        );
        $this->registerArgument(
            'sortFlags',
            'string',
            'Constant name from PHP for `SORT_FLAGS`: `SORT_REGULAR`, `SORT_STRING`, `SORT_NUMERIC`, ' .
            '`SORT_NATURAL`, `SORT_LOCALE_STRING` or `SORT_FLAG_CASE`. You can provide a comma seperated list or ' .
            'array to use a combination of flags.',
            false,
            'SORT_REGULAR'
        );
        $this->registerArgument(
            'as',
            'string',
            'Template variable name to assign; if not specified the ViewHelper returns the variable instead.'
        );
    }

    /**
     * Sorts an array
     *
     * @param array $arguments
     * @param Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return array|null
     * @throws Exception
     */
    public static function renderStatic(
        array $arguments,
        Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ): ?array {
        $subject = $arguments['subject'] ?? $renderChildrenClosure();

        if (empty($subject)) {
            return null;
        }

        $subject = static::arrayFromArrayOrTraversableOrCSVStatic($subject);
        $sorted = static::sortArray($subject, $arguments);

        if (!$arguments['as']) {
            return $sorted;
        }

        $renderingContext->getVariableProvider()->add($arguments['as'], $sorted);
        return null;
    }

    /**
     * Sort an array
     *
     * @param array $array
     * @param array $arguments
     * @return array
     * @throws Exception
     */
    protected static function sortArray(array $array, array $arguments): array
    {
        if ($arguments['order'] === 'ASC') {
            asort($array, static::getSortFlags($arguments));
        } elseif ($arguments['order'] === 'RAND') {
            $sortedKeys = array_keys($array);
            shuffle($sortedKeys);
            $backup = $array;
            $array = [];
            foreach ($sortedKeys as $sortedKey) {
                $array[$sortedKey] = $backup[$sortedKey];
            }
        } elseif ($arguments['order'] === 'SHUFFLE') {
            shuffle($array);
        } else {
            arsort($array, static::getSortFlags($arguments));
        }
        return $array;
    }

    /**
     * Parses the supplied flags into the proper value for the sorting
     * function.
     *
     * @param array|string $arguments
     * @return int
     * @throws Exception
     */
    protected static function getSortFlags(mixed $arguments): int
    {
        $constants = static::arrayFromArrayOrTraversableOrCSVStatic($arguments['sortFlags']);
        $flags = 0;
        foreach ($constants as $constant) {
            if (!in_array($constant, static::$allowedSortFlags)) {
                throw new Exception(
                    'The constant "' . $constant . '" you\'re trying to use as a sortFlag is not allowed. Allowed ' .
                    'constants are: ' . implode(', ', static::$allowedSortFlags) . '.',
                    1676474590
                );
            }
            $flags = $flags | constant(trim($constant));
        }
        return $flags;
    }

    /**
     * @throws Exception
     */
    protected static function arrayFromArrayOrTraversableOrCSVStatic($candidate, bool $useKeys = true): array
    {
        if (is_array($candidate)) {
            return $candidate;
        }
        if ($candidate instanceof Traversable) {
            return iterator_to_array($candidate, $useKeys);
        }
        if (is_string($candidate)) {
            return GeneralUtility::trimExplode(',', $candidate, true);
        }
        throw new Exception('Unsupported input type; cannot convert to array!', 1676474590);
    }
}
