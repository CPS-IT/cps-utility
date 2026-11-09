<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'CPS utility',
    'description' => 'Collection of utilities to use in TYPO3 Extensions.',
    'category' => 'misc',
    'state' => 'stable',
    'author' => 'Vladimir Falcon Piva',
    'author_email' => 'v.falcon@familie-redlich.de',
    'author_company' => 'Familie Redlich',
    'version' => '3.6.1',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.3.99',
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
