<?php
namespace Cpsit\CpsUtility\Traits;

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;
use Cpsit\CpsUtility\Utility\TypoScriptUtility;

/**
 * Parse stdWrap options in a typoScript array
 * Use it in extbase context only
 * Expects a valid typoScript array
 */
trait ExtBaseTypoScriptStdWrapParserTrait
{
    /**
     * @param array $typoScript
     * @return array
     */
    public function parseTypoScriptStdWrap(array $typoScript): array
    {
        /** @var TypoScriptUtility $typoScriptUtility */
        $typoScriptUtility = GeneralUtility::makeInstance(TypoScriptUtility::class);

        $typoScript = $typoScriptUtility->convertPlainArrayToTypoScriptArray($typoScript);
        $typoScript = $typoScriptUtility->stdWrapParser($typoScript, $this->configurationManager->getContentObject());

        return $typoScriptUtility->convertTypoScriptArrayToPlainArray($typoScript);
    }
}
