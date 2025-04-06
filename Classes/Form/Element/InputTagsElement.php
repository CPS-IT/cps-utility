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

use Cpsit\CpsUtility\Configuration\SettingsInterface as SI;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Custom form element to display comma-separated values as tags
 * (required bootstrap-tagsinput package).
 *
 * Usage in TCA:
 * 'field' => [
 *     'config' => [
 *          'type' => 'input',
 *          'renderType' => 'inputTags',
 *          'placeholder' => 'please type your tags',
 *          'readOnly' => true,
 *          'required' => true,
 *          'pattern' => '^[A-Za-z_✲ ]{1,15}$',
 *          'size' => '30',
 *      ]
 *  ],
 */
class InputTagsElement extends AbstractFormElement
{

    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        $parameterArray = $this->data['parameterArray'];
        $elementName = $this->data['parameterArray']['itemFormElName'];

        $config = $parameterArray['fieldConf']['config'] ?? [];

        $fieldInformationResult = $this->renderFieldInformation();
        $fieldInformationHtml = $fieldInformationResult['html'];
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldInformationResult, false);

        $fieldControlResult = $this->renderFieldControl();
        $fieldControlHtml = $fieldControlResult['html'];
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldControlResult, false);

        $fieldWizardResult = $this->renderFieldWizard();
        $fieldWizardHtml = $fieldWizardResult['html'];
        $resultArray = $this->mergeChildReturnIntoExistingResult($resultArray, $fieldWizardResult, false);
        $itemValue = $parameterArray['itemFormElValue'] ?? '';
        $tagsId = StringUtility::getUniqueId('formengine-tags-');
        $width = $this->formMaxWidth(
            MathUtility::forceIntegerInRange($config['size'] ?? $this->defaultInputWidth, $this->minimumInputWidth,
                $this->maxInputWidth)
        );
        $attributes = [
            'data-role' => 'tagsinput',
            'value' => $itemValue,
            'class' => implode(' ', [
                'form-control',
            ]),
        ];
        if ($config['placeholder'] ?? false) {
            $attributes['placeholder'] = trim($config['placeholder']);
        }
        if ($config['readOnly'] ?? false) {
            $attributes['readonly'] = 'readonly';
        }
        if ($config['required'] ?? false) {
            $attributes['required'] = 'required';
        }
        if ($config['pattern'] ?? false) {
            $attributes['pattern'] = trim($config['pattern']);
        }

        // @todo: make this configurable via TSconfig
        $placeholder = $this->getLanguageService()->sL('LLL:EXT:tag/Resources/Private/Language/locallang_tca.xlf:reference.placeholder');
        $html = [];
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        $html[] = $fieldInformationHtml;
        $html[] = '<div class="form-control-wrap" style="max-width: ' . $width . 'px">';
        $html[] = '<div class="form-wizards-wrap">';
        $html[] = '<div class="form-wizards-element">';
        $html[] = '<input type="text" ' . GeneralUtility::implodeAttributes($attributes,
                true) . ' name="' . htmlspecialchars($elementName) . '">';
        $html[] = '</select>';
        $html[] = '</div>';
        if (!empty($fieldControlHtml)) {
            $html[] = '<div class="form-wizards-items-aside">';
            $html[] = '<div class="btn-group">';
            $html[] = $fieldControlHtml;
            $html[] = '</div>';
            $html[] = '</div>';
        }
        if (!empty($fieldWizardHtml)) {
            $html[] = '<div class="form-wizards-items-bottom">';
            $html[] = $fieldWizardHtml;
            $html[] = '</div>';
        }
        $html[] = '</div>';
        $html[] = '</div>';
        $html[] = '</div>';


        $resultArray['html'] = implode(LF, $html);
        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create(
            '@cpsit/cps-utility/input-tag-element.js'
        );
        $resultArray['stylesheetFiles'][] = 'EXT:' . SI::KEY . '/Resources/Public/Backend/Css/tagify.css';
        return $resultArray;
    }
}
