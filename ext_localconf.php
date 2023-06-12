<?php
defined('TYPO3') or die();

(function ($extKey = Cpsit\CpsUtility\Configuration\SettingsInterface::KEY) {

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1631886776] = [
        'nodeName' => 'inputTags',
        'priority' => 40,
        'class' => \Cpsit\CpsUtility\Form\Element\InputTagsElement::class,
    ];

    TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(trim('
    config.pageTitleProviders {
        TitleProvider {
            provider = Cpsit\CpsUtility\PageTitle\TitleProvider
            before = seo
        }
    }'));

    /** @var \TYPO3\CMS\Core\Resource\Rendering\RendererRegistry $rendererRegistry */
    $rendererRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\Rendering\RendererRegistry::class);
    $rendererRegistry->registerRendererClass(\Cpsit\CpsUtility\Rendering\YouTubeRenderer::class);
    $rendererRegistry->registerRendererClass(\Cpsit\CpsUtility\Rendering\VimeoRenderer::class);

})();
