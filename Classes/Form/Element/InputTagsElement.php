<?php
declare(strict_types=1);

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use Cpsit\CpsUtility\Configuration\SettingsInterface as SI;

/**
 * Custom form element to display comma-separated values as tags
 * (required bootstrap-tagsinput package).
 *
 * Usage in TCA:
 * 'field' => [
 *     'config' => [
 *          'type' => 'input',
 *          'renderType' => 'inputTags',
 *          'max' => 255,
 *          'eval' => 'trim'
 *      ]
 *  ],
 *
 */
class InputTagsElement extends AbstractFormElement
{
    /**
     * Default field information enabled for this element.
     *
     * @var array
     */
    protected $defaultFieldInformation = [
        'tcaDescription' => [
            'renderType' => 'tcaDescription',
        ],
    ];

    /**
     * Default field wizards enabled for this element.
     *
     * @var array
     */
    protected $defaultFieldWizard = [
        'localizationStateSelector' => [
            'renderType' => 'localizationStateSelector',
        ],
        'otherLanguageContent' => [
            'renderType' => 'otherLanguageContent',
            'after' => [
                'localizationStateSelector'
            ],
        ],
        'defaultLanguageDifferences' => [
            'renderType' => 'defaultLanguageDifferences',
            'after' => [
                'otherLanguageContent',
            ],
        ],
    ];

    /**
     * This will render a single-line input form field, possibly with various control/validation features
     *
     * @return array As defined in initializeResultArray() of AbstractNode
     */
    public function render(): array
    {
        $languageService = $this->getLanguageService();

        $table = $this->data['tableName'];
        $fieldName = $this->data['fieldName'];
        $row = $this->data['databaseRow'];
        $parameterArray = $this->data['parameterArray'];
        $resultArray = $this->initializeResultArray();

        $itemValue = $parameterArray['itemFormElValue'];
        $config = $parameterArray['fieldConf']['config'];
        $evalList = GeneralUtility::trimExplode(',', $config['eval'], true);
        $size = MathUtility::forceIntegerInRange($config['size'] ?? $this->defaultInputWidth, $this->minimumInputWidth,
            $this->maxInputWidth);
        $width = (int)$this->formMaxWidth($size);

        $fieldInformationResult = $this->renderFieldInformation();
        $fieldInformationHtml = $fieldInformationResult['html'];
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldInformationResult, false);

        if ($config['readOnly']) {
            return $this->renderReadOnlyFieldHtml(
                $parameterArray,
                $itemValue,
                $config,
                $fieldInformationHtml,
                $width
            );
        }
        $this->evalHandling($evalList, $resultArray, $itemValue);

        $fieldId = StringUtility::getUniqueId('formengine-input-');
        $attributes = $this->getFieldAttributes($fieldName, $fieldId, $config, $parameterArray, $evalList);

        $resultArray['requireJsModules'][] = 'TYPO3/CMS/' . SI::NAME . '/InputTagsElement';
        $resultArray['stylesheetFiles'][] = 'EXT:' . SI::KEY . '/Resources/Public/Backend/Css/Lib/bootstrap-tagsinput.css';

        $fieldControlResult = $this->renderFieldControl();
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldControlResult, false);

        $html = [];
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        $html[] = $fieldInformationHtml;
        $html[] = '<div class="form-wizards-wrap">';
        $html[] = '<div class="form-wizards-element">';
        $html[] = '<div class="form-control-wrap">';
        $html[] = '<input type="text" value="' . htmlspecialchars($itemValue, ENT_QUOTES) . '" ';
        $html[] = GeneralUtility::implodeAttributes($attributes, true);
        $html[] = ' />';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';
        $resultArray['html'] = implode(LF, $html);

        return $resultArray;
    }


    /**
     * @param string $fieldName
     * @param string $fieldId
     * @param array $config
     * @param array $parameterArray
     * @param array $evalList
     * @return array
     */
    protected function getFieldAttributes(
        string $fieldName,
        string $fieldId,
        array $config,
        array $parameterArray,
        array $evalList
    ): array {
        $attributes = [
            'name' => $parameterArray['itemFormElName'],
            'value' => '',
            'id' => $fieldId,
            'class' => implode(' ', [
                'form-control',
                'hasDefaultValue',
            ]),
            'data-formengine-validation-rules' => $this->getValidationDataAsJsonString($config),
            'data-formengine-input-params' => (string)json_encode([
                'field' => $parameterArray['itemFormElName'],
                'evalList' => implode(',', $evalList),
            ]),

            'data-formengine-input-name' => (string)$parameterArray['itemFormElName'],
            'data-role' => 'tagsinput'
        ];

        $maxLength = $config['max'] ?? 0;
        if ((int)$maxLength > 0) {
            $attributes['maxlength'] = (string)(int)$maxLength;
        }
        if (!empty($config['placeholder'])) {
            $attributes['placeholder'] = trim($config['placeholder']);
        }

        // Disabled autocomplete
        $attributes['autocomplete'] = 'new-' . $fieldName;

        return $attributes;
    }

    /**
     * Encapsulate code in a function for readability
     * Code taken from  TYPO3\CMS\Backend\Form\Element\InputTextElement
     * Line: 121 - 140
     */
    protected function evalHandling(array $evalList, array &$resultArray, string &$itemValue): void
    {
        // @todo: The whole eval handling is a mess and needs refactoring
        foreach ($evalList as $func) {
            // @todo: This is ugly: The code should find out on it's own whether an eval definition is a
            // @todo: keyword like "date", or a class reference. The global registration could be dropped then
            // Pair hook to the one in \TYPO3\CMS\Core\DataHandling\DataHandler::checkValue_input_Eval()
            if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tce']['formevals'][$func])) {
                if (class_exists($func)) {
                    $evalObj = GeneralUtility::makeInstance($func);
                    if (method_exists($evalObj, 'deevaluateFieldValue')) {
                        $_params = [
                            'value' => $itemValue
                        ];
                        $itemValue = $evalObj->deevaluateFieldValue($_params);
                    }
                    if (method_exists($evalObj, 'returnFieldJS')) {
                        $resultArray['additionalJavaScriptPost'][] = 'TBE_EDITOR.customEvalFunctions[' . GeneralUtility::quoteJSvalue($func) . ']'
                            . ' = function(value) {' . $evalObj->returnFieldJS() . '};';
                    }
                }
            }
        }
    }

    /**
     * @param array $parameterArray
     * @param string $itemValue
     * @param array $config
     * @param string $fieldInformationHtml
     * @param int $width
     * @return array
     */
    protected function renderReadOnlyFieldHtml(
        array $parameterArray,
        string $itemValue,
        array $config,
        string $fieldInformationHtml,
        int $width
    ): array {
        $disabledFieldAttributes = [
            'class' => 'form-control',
            'data-formengine-input-name' => $parameterArray['itemFormElName'],
            'type' => 'text',
            'value' => $itemValue,
            'placeholder' => trim($config['placeholder']) ?? '',
        ];

        $html = [];
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        $html[] = $fieldInformationHtml;
        $html[] = '<div class="form-wizards-wrap">';
        $html[] = '<div class="form-wizards-element">';
        $html[] = '<div class="form-control-wrap" style="max-width: ' . $width . 'px">';
        $html[] = '<input ' . GeneralUtility::implodeAttributes($disabledFieldAttributes, true) . ' disabled>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';
        $resultArray['html'] = implode(LF, $html);

        return $resultArray;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
