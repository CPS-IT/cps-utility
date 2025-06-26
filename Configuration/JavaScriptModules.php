<?php

return [
    'dependencies' => [
        'core',
        'backend',
    ],
    'tags' => [
        'backend.form',
    ],
    'imports' => [
        '@cpsit/cps-utility/tagify.js' => 'EXT:cps_utility/Resources/Public/JavaScript/tagify.js',
        '@cpsit/cps-utility/input-tag-element.js' => 'EXT:cps_utility/Resources/Public/JavaScript/input-tag-element.js',
        '@cpsit/cps-utility/ck-content-class.js' => 'EXT:cps_utility/Resources/Public/JavaScript/Ckeditor/ckContentClass.js',
    ],
];
