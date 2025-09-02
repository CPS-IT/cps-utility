# CkContent Plugin for TYPO3

The CkContent plugin is a CKEditor 5 plugin that allows you to add custom CSS classes to the CKEditor content area in TYPO3. This plugin is particularly useful for applying theme-specific styles to the RTE editor content.

## Overview

This plugin extends the base CKEditor 5 Plugin class and provides functionality to dynamically add CSS classes to the editor's content area. In TYPO3 context, this is especially useful for applying site-specific themes and ensuring the editor content matches the frontend styling.

See use case `editor.config.contentsCss` in [TYPO3 CKEditor Documentation](https://docs.typo3.org/c/typo3/cms-rte-ckeditor/main/en-us/Configuration/Reference.html#editor) for more details on how to costumized the styles for your editor.

## Browser Support

This plugin works with all browsers supported by CKEditor 5 and TYPO3 v12+.

## Requirements

- TYPO3 v12.4+
- CKEditor 5
- PHP 8.2+
- Modern browser with JavaScript support

## TYPO3 Configuration

### 1. Import the Plugin

Add the plugin to your RTE configuration YAML file by importing it in the `importModules` section:

```yaml
editor:
  config:
    importModules:
      # Custom plugin to add CSS class to ck-content container
      - { module: '@cpsit/cps-utility/ck-content-class.js', exports: [ 'CkContentClass' ] }

    plugins:
      - CkContentClass
```

### 2. Add CSS Classes

Add the add CSS classes you want to add to the CKEditor content area in the `ckContentClass` section:

```yaml
editor:
    config:
        ckContentClass: "css-class-to-add to-ck-content-container"
```

### 3. Configure Content CSS

Ensure your content CSS includes the theme styles by adding it to the `contentsCss` configuration:

```yaml
editor:
  config:
    contentsCss:
      - "EXT:your_sitepackage/Resources/Public/Backend/CKeditor/contents.css"
```

## Example Configuration

Here's a complete example of how to configure the plugin in your TYPO3 RTE configuration:

```yaml
# File: Configuration/TSconfig/Base/Rte/Default.yaml
imports:
  - { resource: "EXT:rte_ckeditor/Configuration/RTE/Processing.yaml" }
  - { resource: "EXT:rte_ckeditor/Configuration/RTE/Editor/Base.yaml" }
  - { resource: "EXT:rte_ckeditor/Configuration/RTE/Editor/Plugins.yaml" }

editor:
  config:
    contentsCss:
      - "EXT:your_sitepackage/Resources/Public/Backend/CKeditor/contents.css"

    importModules:
      - { module: '@cpsit/cps-utility/ck-content-class.js', exports: [ 'CkContentClass' ] }

    plugins:
      - CkContentClass

    ckContentClass: "css-class-to-add to-ck-content-container"

    toolbar:
      items:
        - undo
        - redo
        - '|'
        - bold
        - italic
        # ... other toolbar items
```

## Contents CSS example

The plugin supports multiple theme configurations through CSS custom properties. Example themes include:

### A Theme

```css
:root.theme-a {
    --color-a-lime: #cede00;
    --color-a-blue: #4ec3ec;
    --color-a-blue-dark: #00568c;
    /* ... other theme variables */
}

:root.theme-b {
    --color-b-lime: #cede00;
    --color-b-green: #4da707;
    --color-b-green-dark: #5ece02;
    /* ... other theme variables */
}
```

## How It Works

1. **TYPO3 Integration**: The plugin is loaded through TYPO3's CKEditor 5 configuration system
2. **Site Detection**: It automatically detects the current TYPO3 site context
3. **Theme Application**: Based on the site, it applies the appropriate theme class to the editor content area
4. **CSS Inheritance**: The editor content inherits the theme-specific styling from the configured CSS files

## Implementation Details

### Plugin Structure

The plugin follows the CKEditor 5 plugin pattern:

```javascript
export class CkContentClass extends Plugin {
  static pluginName = 'CkContentClass';

  init() {
    // Plugin initialization
    // Listens to 'ready' and 'render' events
  }

  addClassToContentArea(className) {
    // Adds CSS class to editor content area
  }
}
```

### Event Handling

The plugin listens to two CKEditor events:
- **ready**: When the editor is fully initialized
- **render**: When the editor view is rendered

This ensures the CSS class is applied regardless of the initialization timing.

## File Structure

```
app/vendor/cpsit/cps-utility/
├── Resources/
│   └── Public/
│       └── JavaScript/
│           └── Ckeditor/
│               └── ckContentClass.js
└── Documentation/
    └── CkContent/
        └── README.md
```

## Usage in Multi-Site Setup

This plugin is particularly useful in TYPO3 multi-site setups where different sites require different themes:

- **A Site**: Default theme
- **B Site**: theme with lime and blue colors
- **C Site**: theme with blue and green colors

## Troubleshooting

### Plugin Not Loading
- Verify the module path in `importModules` is correct
- Check that the plugin is listed in the `plugins` array
- Ensure the JavaScript file is accessible

### CSS Not Applied
- Check that `contentsCss` includes your theme CSS file
- Verify the CSS file contains the theme-specific rules
- Ensure the theme class is being added to the editor content area

### Theme Not Detected
- Verify TYPO3 site configuration is correct
- Check browser console for any JavaScript errors
- Ensure the site identifier matches the expected theme class


