<?php

declare(strict_types=1);

/*
 * This file is part of the cps_utility project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Utility;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

#[Autoconfigure(public: true)]
class PageUtility
{
    /**
     * @var PageRepository
     */
    public function __construct(protected PageRepository $pageRepository
    ) {}

    /**
     * Resolve storage page for a plugin
     *
     * @param string $storagePages list of pages to resolved separated by comma
     * @param int $depth tree depth to add
     * @return int[]
     */
    public function resolveStoragePages(string $storagePages = '0', int $depth = 0): array
    {
        $pages = GeneralUtility::intExplode(',', $storagePages);
        return $this->pageRepository->getPageIdsRecursive($pages, $depth);
    }

    /**
     * Retrieves subpages of given page(s)  recursively until depth ist reached
     *
     * @param array $pages
     * @param int $depth
     * @return int[] an array with all pageIds
     * @deprecated
     */
    public function expandPagesWithSubPages(array $pages, int $depth = 0): array
    {
        trigger_error(
            'The method ' . __METHOD__ . ' is deprecated and will be removed in new major version release. Use PageRepository::getPageIdsRecursive instead.',
            E_USER_DEPRECATED
        );
        // remove duplicates and return
        return $this->pageRepository->getPageIdsRecursive($pages, $depth);
    }
}
