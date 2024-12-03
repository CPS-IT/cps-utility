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

use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\ContentObject\DataProcessorInterface;

/**
 * Transform into array variables stored in flex form
 *
 * 10 = Cpsit\CpsUtility\DataProcessing\FlexFormDataProcessor
 * 10 {
 *      field = pi_flexform
 *      as = flexFormData
 *      valuePath = data|sDEF
 *      valuePathDelimiter = |
 * }
 */
class FlexFormDataProcessor implements DataProcessorInterface
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
    ) {
        $field = $processorConfiguration['field'] ?? 'pi_flexform';
        $valuePath = $processorConfiguration['valuePath'] ?? false;
        $valuePathDelimiter = $processorConfiguration['valuePathDelimiter'] ?? '|';
        $variableName = $processorConfiguration['as'] ?? 'flexFormData';

        $flexForm = $cObj->data[$field] ?? '';

        if ($flexForm && $variableName) {
            $flexFormArray = GeneralUtility::makeInstance(FlexFormService::class)
                ->convertFlexFormContentToArray($flexForm);

            if ($valuePath) {
                try {
                    $flexFormArray = ArrayUtility::getValueByPath($flexFormArray, $valuePath, $valuePathDelimiter);
                } catch (MissingArrayPathException $e) {
                    $flexFormArray = null;
                }
            }
            $processedData[$variableName] = $flexFormArray;
        }

        return $processedData;
    }
}
