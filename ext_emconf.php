<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'CPS utility',
    'description' => 'Collection of utilities to use in TYPO3 Extensions.',
    'category' => 'misc',
    'state' => 'stable',
    'author' => 'Vladimir Falcon Piva',
    'author_email' => 'v.falcon@familie-redlich.de',
    'author_company' => 'Familie Redlich',
    'version' => '2.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
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
