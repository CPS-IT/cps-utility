CPS Utility
==============================================================

Collection of utilities to use in TYPO3 Extensions.

## Utilities

### Page Utility

### expandPagesWithSubPages

@depreprecated since 3.0, use `\TYPO3\CMS\Core\Domain\Repository\PageRepository::getPageIdsRecursive` instead.

Retrieves subpages of given page(s) recursively until depth ist reached.

Usage: `$pageUtility->expandPagesWithSubPages([...], 0)`

### resolveStoragePages

Resolve a list of given page(s)  recursively until depth ist reached.

Usage: `$pageUtility->resolveStoragePages('1,2,3', 0)`

### TypoScript Utility

Useful function to parse TypoScript configuration array.

## Data Processors

Extends the FlexFormProcessor to be able to get values by path.

Usage:

```
10 = FlexForm
10 {
     fieldName = pi_flexform
     as = flexFormData
     valuePath = data|sDEF
     valuePathDelimiter = |
}
```

## View helpers

### Explode view helper

Explode view helper Split a string by a string.
Wrapper for PHPs :php:`explode` function.
See https://www.php.net/manual/en/function.explode.php

Usage:

```
<f:iterator.explode glue="," limit="10" as="varName">text,text,text</f:iterator.explode>
```

Inline notation: `{text_to_split -> f:iterator.explode()}` without `as` parameter returns an array.

### Title Tag ViewHelper

ViewHelper to render the page title tag
Example: Basic Example

```
<cps:titleTag>{item.title}</cps:titleTag>
```

### Meta Tag ViewHelper

ViewHelper to render meta tags

Example: Basic Example: News title as og:title meta tag
```
<cps:metaTag property="og:title" content="{newsItem.title}" />
```
Example: Force the attribute "name"
```
<cps:metaTag name="keywords" content="{newsItem.keywords}" />`
```

### Header Data ViewHelper

ViewHelper to render data in <head> section of website

Example: Basic example

```
 <lib:headerData>
     <link rel="alternate"
          type="application/rss+xml"
          title="RSS 2.0"
          href="{f:uri.page(additionalParams: '{type:1600292946}')}" />
 </lib:headerData>
```

Output

```
 <link rel="alternate"
     type="application/rss+xml"
     title="RSS 2.0"
     href="uri to this page and type 9818" />
```

## TCA

### Input tags element.

Custom form element to display comma-separated values as tags. Based on  [bootstrap-tagsinput] (https://github.com/bootstrap-tagsinput/bootstrap-tagsinput) package

Usage in TCA:

```
'field' => [
    'config' => [
         'type' => 'input',
         'renderType' => 'inputTags',
         'max' => 255,
         'eval' => 'trim'
     ]
 ],
```

## Traits

### Trait to add cache tags to pages

Trait to add cache tags to pages in FE context

Usage:
- Add trait to your class `use Cpsit\CpsUtility\Traits\FeCacheTagsTrait;`.
- Call method `$this->addCacheTags(['tag1','tag2')` were needed.
