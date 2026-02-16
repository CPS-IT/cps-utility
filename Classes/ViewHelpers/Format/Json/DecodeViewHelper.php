<?php

declare(strict_types=1);
/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\ViewHelpers\Format\Json;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Converts the JSON encoded argument into a PHP variable
 * @codeCoverageIgnore
 *
 * Useful to create nested json strings using fluid
 *
 * Example: related categories to json
 *
 * {f:format.json(value: {
 *     categories: '{f:render(section: \'Categories\', arguments: \'{categories:author.categories}\')->f:spaceless()->fr:format.json.decode()}'
 * })->f:format.raw()}
 *
 * <f:section name="Categories">
 *     <f:spaceless>
 *          [<f:for each="{categories}" as="category" iteration="iterator">
 *              {f:format.json(value: {
 *              id: '{category.uid}',
 *              pid: '{category.pid}',
 *              title: '{category.title}',
 *              parent: '{category.parent.uid}'
 *              })->f:format.raw()}
 *              {f:if(condition: '{iterator.isLast}', else: ',')}
 *          </f:for>]
 *     </f:spaceless>
 * </f:section>
 */
class DecodeViewHelper extends AbstractViewHelper
{
    /**
     * Initialize
     */
    public function initializeArguments(): void
    {
        $this->registerArgument(
            'value',
            'string',
            'Json string to decode. If not in arguments then taken from tag content'
        );
        $this->registerArgument('name', 'string', 'Name of variable to create', false);
    }
    
    public function render(): mixed
    {
        $json = trim((string)$this->renderChildren());
        if ($json === '') {
            return null;
        }
        $object = json_decode((string)$json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if ($GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] ?? false) {
                throw new \Exception(sprintf(
                    'Failure "%s" occured when running json_decode() for string: %s',
                    json_last_error_msg(),
                    $json
                ), 5400916655);
            }
        }

        if ($this->hasArgument('name')) {
            $this->renderingContext->getVariableProvider()->add($this->arguments['name'], $object);
        }

        return $object;
    }

    /**
     * Explicitly set argument name to be used as content.
     */
    public function getContentArgumentName(): string
    {
        return 'value';
    }
}
