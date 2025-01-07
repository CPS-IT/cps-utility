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
    'version' => '2.0.5',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-12.4.99',
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
