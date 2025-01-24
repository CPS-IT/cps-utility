<?php

declare(strict_types=1);

/*
 * This file is part of the cpsit-proposal project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

class PageLinkService
{
    public function __construct(
        private readonly SiteFinder $siteFinder
    ) {}

    /**
     * @throws SiteNotFoundException
     */
    public function getPageUrl(
        int $pageId,
        array $params = [],
        string $type = RouterInterface::ABSOLUTE_URL,
        string $fragment = ''
    ): string {
        $site = $this->siteFinder->getSiteByPageId($pageId);
        return (string)$site->getRouter()
            ->generateUri($pageId, $params, $fragment, $type);
    }
}
