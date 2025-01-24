<?php

declare(strict_types=1);

/*
 * This file is part of the cpsit-proposal project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Cpsit\CpsUtility\Domain\Model\Dto;

use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Mail\FluidEmail;

class NotificationRequest
{
    protected array $templateArguments = [];
    protected string $subject = '';
    protected array $recipients = [];
    protected string $format = FluidEmail::FORMAT_HTML;
    protected string $templateName = '';
    protected Address $senderAddress;

    public function __construct()
    {
        $fromEmail = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'];
        $fromName = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'];
        $this->senderAddress = new Address($fromEmail, $fromName);
    }

    public function getTemplateArguments(): array
    {
        return $this->templateArguments;
    }

    public function setTemplateArguments(array $templateArguments): NotificationRequest
    {
        $this->templateArguments = $templateArguments;
        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): NotificationRequest
    {
        $this->subject = $subject;
        return $this;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function setRecipients(Address ...$recipients): NotificationRequest
    {
        $this->recipients = $recipients;
        return $this;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): NotificationRequest
    {
        $this->format = $format;
        return $this;
    }

    /**
     * @return string
     */
    public function getTemplateName(): string
    {
        return $this->templateName;
    }

    public function setTemplateName(string $templateName): NotificationRequest
    {
        $this->templateName = $templateName;
        return $this;
    }

    public function getSenderAddress(): Address
    {
        return $this->senderAddress;
    }

    public function setSenderAddress(Address $senderAddress): NotificationRequest
    {
        $this->senderAddress = $senderAddress;
        return $this;
    }
}
