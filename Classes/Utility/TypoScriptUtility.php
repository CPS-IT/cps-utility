<?php
declare(strict_types=1);

/*
 * This file is part of the fr_utilities project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Utility;

use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class TypoScriptUtility
{
    /**
     * Parse stdWrap options in a typoScript array
     * Expects a valid typoScript array
     *
     * @param array $typoScript
     * @param ContentObjectRenderer $contentObject
     * @param array $excludeKeys Exclude keys from parsing
     * @return array
     */
    public function stdWrapParser(
        array $typoScript,
        ContentObjectRenderer $contentObject
    ): array {
        ksort($typoScript);
        foreach ($typoScript as $key => $value) {
            // parse typo script if parseable
            if (is_array($value) && $this->isParseableTypoScriptObject($key, $typoScript)) {
                $content = $typoScriptArray[rtrim($key, '.')] ?? '';
                $typoScriptArray[rtrim($key, '.')] = $contentObject->stdWrap($content, $value);
                continue;
            }
            // recursion
            if (is_array($value) && !$this->isParseableTypoScriptObject($key, $typoScript)) {
                $typoScriptArray[$key] = $this->stdWrapParser($value, $contentObject);
                continue;
            }
            $typoScriptArray[$key] = $value;
        }
        return $typoScriptArray ?? $typoScript;
    }

    /**
     * Return true if typoscript array is parseable
     *
     * @param string $tsKey
     * @param array $tsArray
     * @return bool
     */
    public function isParseableTypoScriptObject(string $tsKey, array $tsArray): bool
    {
        if (strpos($tsKey, '.') && is_array($tsArray[$tsKey])
            && (isset($tsArray[rtrim($tsKey, '.')])
                || (isset($tsArray[$tsKey]['stdWrap.']) && is_array($tsArray[$tsKey]['stdWrap.'])))) {
            return true;
        }
        return false;
    }

    /**
     * Convert plain array to typoScript array
     *
     * @param array $plainArray
     * @return array
     */
    public function convertPlainArrayToTypoScriptArray(array $plainArray): array
    {
        $typoScriptService = GeneralUtility::makeInstance(TypoScriptService::class);

        return $typoScriptService->convertPlainArrayToTypoScriptArray($plainArray);
    }

    /**
     * Convert typoScript array to plain array
     *
     * @param array $plainArray
     * @return array
     */
    public function convertTypoScriptArrayToPlainArray(array $plainArray): array
    {
        $typoScriptService = GeneralUtility::makeInstance(TypoScriptService::class);

        return $typoScriptService->convertTypoScriptArrayToPlainArray($plainArray);
    }

}
