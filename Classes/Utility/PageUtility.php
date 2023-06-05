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

use Cpsit\CpsUtility\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class PageUtility
{
    /**
     * @var PageRepository
     */
    protected PageRepository $pageRepository;

    public function __construct(
        PageRepository $pageRepository = null
    ) {
        $this->pageRepository = $pageRepository ?? GeneralUtility::makeInstance(PageRepository::class);
    }

    /**
     * Resolve storage page for a plugin
     * Should to be used in frontend context only
     *
     * @param string $storagePages list of pages to resolved separated by comma
     * @param int $depth tree depth to add
     * @return int[]
     */
    public function resolveStoragePages(string $storagePages = '0', int $depth = 0): array
    {
        if (empty($storagePages)) {
            try {
                // make sure we are in frontend context
                /** @var TypoScriptFrontendController $frontend */
                $frontend = GeneralUtility::makeInstance(TypoScriptFrontendController::class);
                $storagePages = [
                    (int)$frontend->id
                ];
            } catch (\Exception $exception) {
                // silently return an empty array
                return [];
            }
        } else {
            $storagePages = GeneralUtility::intExplode(',', $storagePages);
        }

        if (intval($depth) > 0) {
            $storagePages = $this->expandPagesWithSubPages($storagePages, (int)$depth);
        }
        return $storagePages;
    }


    /**
     * Retrieves subpages of given page(s)  recursively until depth ist reached
     *
     * @param array $pages
     * @param int $depth
     * @return int[] an array with all pageIds
     */
    public function expandPagesWithSubPages(array $pages, int $depth = 0): array
    {
        $pidList = $pages;
        // iterate through root-page ids and merge to array
        foreach ($pages as $pid) {
            // need to cast because getTreeList does not always return a string
            $treeList = $this->pageRepository->getTreeList($pid, $depth, 0, 'deleted = 0');
            if (!empty($treeList)) {
                $pidList = array_merge($pidList, explode(',', $treeList));
            }
        }
        // cast to int
        $pidList = GeneralUtility::intExplode(',', implode(',', $pidList));

        // remove duplicates and return
        return array_unique($pidList);
    }
}
