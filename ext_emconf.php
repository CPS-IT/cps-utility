<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'CPS utility',
    'description' => 'Collection of utilities to use in TYPO3 Extensions.',
    'category' => 'misc',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => 'Vladimir Falcon Piva',
    'author_email' => 'v.falcon@familie-redlich.de',
    'author_company' => 'Familie Redlich',
    'version' => '1.2.0',
    'constraints' => [
        'depends' => [
            'typo3' => '10.2.0-11.5.99'
        ],
        'conflicts' => [
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Cpsit\\CpsUtility\\' => 'Classes',
        ],
    ],
];
