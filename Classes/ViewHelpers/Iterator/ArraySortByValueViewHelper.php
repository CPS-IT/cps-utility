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

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

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
 *    <fr:iterator.sortByValue order="ASC" sortFlags="SORT_NATURAL | SORT_FLAG_CASE" as="sorted">{subject}</f:iterator.sortByValue>
 *
 * ... sorted array (case-insensitive natural sorting)
 *
 * Multiple flags with comma separation
 * ------------------------------------
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
 *    {subject -> f:iterator.sortByValue()}
 *
 * ... sorted array
 *
 * Note: SORT_FLAG_CASE can be combined (bitwise OR) with SORT_STRING or SORT_NATURAL using the pipe operator (|)
 */
class ArraySortByValueViewHelper extends AbstractViewHelper
{
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
    protected array $allowedSortFlags = [
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
    #[\Override]
    public function initializeArguments(): void
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
            '`SORT_NATURAL`, `SORT_LOCALE_STRING` or `SORT_FLAG_CASE`. You can provide a comma separated list or ' .
            'array to use multiple flags. Use pipe (|) for bitwise OR combinations (e.g., "SORT_NATURAL | SORT_FLAG_CASE"). ' .
            'Note: SORT_FLAG_CASE can only be combined with SORT_STRING or SORT_NATURAL.',
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
     * @throws \Exception
     */
    public function render(): ?array
    {
        $subject = $this->renderChildren();

        if (empty($subject)) {
            return [];
        }

        $subject = $this->normalizeToArray($subject);
        $sorted = $this->sortArray($subject, $this->arguments);

        if ($this->hasArgument('as')) {
            $this->renderingContext->getVariableProvider()->add($this->arguments['as'], $sorted);
            return null;
        }

        return $sorted;
    }

    public function getContentArgumentName(): string
    {
        return 'subject';
    }

    /**
     * Sort an array
     *
     * @throws \Exception
     */
    protected function sortArray(array $array, array $arguments): array
    {
        return match ($arguments['order']) {
            'ASC' => $this->sortAscending($array, $this->getSortFlags($arguments)),
            'DESC' => $this->sortDescending($array, $this->getSortFlags($arguments)),
            'RAND' => $this->shufflePreservingKeys($array),
            'SHUFFLE' => $this->shuffleArray($array),
            default => $this->sortDescending($array, $this->getSortFlags($arguments)),
        };
    }

    protected function sortAscending(array $array, int $flags): array
    {
        asort($array, $flags);
        return $array;
    }

    protected function sortDescending(array $array, int $flags): array
    {
        arsort($array, $flags);
        return $array;
    }

    protected function shufflePreservingKeys(array $array): array
    {
        $keys = array_keys($array);
        shuffle($keys);
        return array_merge(array_flip($keys), $array);
    }

    protected function shuffleArray(array $array): array
    {
        shuffle($array);
        return $array;
    }

    /**
     * Parses the supplied flags into the proper value for the sorting function.
     *
     * @throws \Exception
     */
    protected function getSortFlags(array $arguments): int
    {
        $flagString = $arguments['sortFlags'];
        $parsedFlags = $this->parseFlagString($flagString);
        $this->validateFlags($parsedFlags);

        return array_reduce(
            $parsedFlags,
            fn(int $result, string $flag) => $result | constant($flag),
            0
        );
    }

    /**
     * Parse flag string into individual flag names.
     * Handles both comma separation (multiple flag groups) and pipe separation (bitwise OR).
     */
    protected function parseFlagString(string $flagString): array
    {
        $flagGroups = $this->normalizeToArray($flagString);
        $allFlags = [];

        foreach ($flagGroups as $group) {
            $flags = array_map(trim(...), explode('|', (string)$group));
            $flags = array_filter($flags, fn($flag) => $flag !== '');
            $allFlags = array_merge($allFlags, $flags);
        }

        return $allFlags;
    }

    /**
     * Validate that all flags are allowed and properly combined.
     *
     * @throws \Exception
     */
    protected function validateFlags(array $flags): void
    {
        foreach ($flags as $flag) {
            if (!in_array($flag, $this->allowedSortFlags, true)) {
                throw new \Exception(
                    sprintf(
                        'The constant "%s" is not allowed. Allowed constants: %s',
                        $flag,
                        implode(', ', $this->allowedSortFlags)
                    ),
                    1676474590
                );
            }
        }

        if (in_array('SORT_FLAG_CASE', $flags, true)) {
            $this->validateSortFlagCaseCombination($flags);
        }
    }

    /**
     * Validates that SORT_FLAG_CASE is only combined with SORT_STRING or SORT_NATURAL
     *
     * @throws \Exception
     */
    protected function validateSortFlagCaseCombination(array $flags): void
    {
        $validCombinations = ['SORT_STRING', 'SORT_NATURAL'];
        $hasValidCombination = !empty(array_intersect($flags, $validCombinations));

        if (!$hasValidCombination) {
            throw new \Exception(
                sprintf(
                    'SORT_FLAG_CASE can only be combined with SORT_STRING or SORT_NATURAL. Current flags: %s',
                    implode(', ', $flags)
                ),
                1676474591
            );
        }
    }

    /**
     * Normalize various input types to array.
     *
     * @throws \Exception
     */
    protected function normalizeToArray(mixed $candidate, bool $preserveKeys = true): array
    {
        return match (true) {
            is_array($candidate) => $candidate,
            $candidate instanceof \Traversable => iterator_to_array($candidate, $preserveKeys),
            is_string($candidate) => GeneralUtility::trimExplode(',', $candidate, true),
            default => throw new \Exception(
                sprintf('Unsupported input type "%s"; cannot convert to array', get_debug_type($candidate)),
                1676474590
            ),
        };
    }
}
