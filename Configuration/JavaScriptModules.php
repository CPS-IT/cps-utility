<?php

return [
    'dependencies' => [
        'core',
    ],
    'tags' => [
        'backend.form',
    ],
    'imports' => [
        '@cpsit/cps-utility/tagify.js' => 'EXT:cps_utility/Resources/Public/JavaScript/tagify.js',
        '@cpsit/cps-utility/input-tag-element.js' => 'EXT:cps_utility/Resources/Public/JavaScript/input-tag-element.js',
    ],
];
