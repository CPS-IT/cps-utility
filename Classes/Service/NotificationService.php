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

use Cpsit\CpsUtility\Domain\Model\Dto\NotificationRequest;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class NotificationService
{
    /**
     * @throws TransportExceptionInterface
     */
    public function notify(NotificationRequest $request): void
    {
        $fluidEmail = new FluidEmail();

        $fluidEmail->to(...$request->getRecipients())
            ->from($request->getSenderAddress())
            ->subject($request->getSubject())
            ->format($request->getFormat())
            ->setTemplate($request->getTemplateName())
            ->assignMultiple($request->getTemplateArguments());

        GeneralUtility::makeInstance(Mailer::class)->send($fluidEmail);
    }
}
