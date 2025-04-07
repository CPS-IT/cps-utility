<?php

declare(strict_types=1);

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\DataProcessor;

use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\DataProcessing\FlexFormProcessor;

/**
 * Extends the FlexFormProcessor to be able to get values by path.
 *
 * 10 = FlexForm
 * 10 {
 *      fieldName = pi_flexform
 *      as = flexFormData
 *      valuePath = data|sDEF
 *      valuePathDelimiter = |
 * }
 */
class FlexFormDataProcessor extends FlexFormProcessor
{
    /**
     * @param ContentObjectRenderer $cObj
     * @param array $contentObjectConfiguration
     * @param array $processorConfiguration
     * @param array $processedData
     * @return array
     */
    public function process(
        ContentObjectRenderer $cObj,
        array $contentObjectConfiguration,
        array $processorConfiguration,
        array $processedData
    ): array {
        $processedData = parent::process($cObj, $contentObjectConfiguration, $processorConfiguration, $processedData);

        $valuePath = $processorConfiguration['valuePath'] ?? '';
        $valuePathDelimiter = $processorConfiguration['valuePathDelimiter'] ?? '|';
        if (empty($valuePath)) {
            return $processedData;
        }
        $targetVariableName = $cObj->stdWrapValue('as', $processorConfiguration, 'flexFormData');
        $flexFormArray = $processedData[$targetVariableName] ?? [];
        try {
            $flexFormArray = ArrayUtility::getValueByPath($flexFormArray, $valuePath, $valuePathDelimiter);
        } catch (MissingArrayPathException $e) {
            $flexFormArray = [];
        }
        $processedData[$targetVariableName] = $flexFormArray;
        return $processedData;
    }
}
