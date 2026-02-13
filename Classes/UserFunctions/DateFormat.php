<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\UserFunctions;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Localization\DateFormatter;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3Fluid\Fluid\Core\ViewHelper\Exception;

/**
 * TYPO3 user function for formatting dates with locale support.
 *
 * The function expects to be given a string containing an English date format
 * @see https://www.php.net/manual/datetime.format.php
 *
 * Example:
 * month = USER
 * month {
 *   userFunc = Cpsit\CpsUtility\UserFunctions\DateFormat->parse
 *   date.field = event_start_date
 *   format.data = LLL:EXT:path/to/Language/locallang.xlf:monthNameFormat
 * }
 *
 * month = TEXT
 * month {
 *   value = 2015-09-31
 *   parseFunc {
 *     userFunc = Cpsit\DenaSitepackage\UserFunctions\DateFormat->render
 *     userFunc {
 *       format.data = LLL:EXT:path/to/Language/locallang.xlf:monthNameFormat
 *     }
 *   }
 * }
 *
 * Format date based on Unicode ICO format pattern given
 * see https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax.
 * If both "pattern" and "format" arguments are given, pattern will be used.
 * Example:
 * month = USER
 * month {
 *   userFunc = Cpsit\CpsUtility\UserFunctions\DateFormat->parse
 *   date.field = event_start_date
 *   pattern.data = LLL:EXT:path/to/Language/locallang.xlf:monthNamePattern
 * }
 *
 * month = TEXT
 * month {
 *   value = 2015-09-31
 *   parseFunc {
 *     userFunc = Cpsit\DenaSitepackage\UserFunctions\DateFormat->render
 *     userFunc {
 *       pattern.data = LLL:EXT:path/to/Language/locallang.xlf:monthNamePattern
 *     }
 *   }
 * }
 */
final class DateFormat
{
    private readonly Context $context;
    private ContentObjectRenderer $cObj;

    /**
     * @param Context|null $context Constructor-promoted property for accessing context aspects
     */
    public function __construct(
        ?Context $context = null
    ) {
        $this->context = $context ?? GeneralUtility::makeInstance(Context::class);
    }

    /**
     * Sets the ContentObjectRenderer instance.
     */
    public function setContentObjectRenderer(ContentObjectRenderer $cObj): void
    {
        $this->cObj = $cObj;
    }

    /**
     * Parses a formatted date string with locale support.
     *
     * @param string $content Date string or timestamp
     * @param array<string, mixed> $conf TypoScript configuration array
     * @return string Formatted date string
     */
    public function parse(string $content, array $conf, ServerRequestInterface $request): string
    {
        $format = $this->resolveFormat($conf);
        $pattern = $this->resolvePattern($conf);
        $date = $this->resolveDateString($content, $conf);

        if ($date === '') {
            $date = $this->resolveTimestamp('now');
        }

        $date = $this->resolveDate($date);
        $locale = $conf['locale'] ?? $this->resolveLocale($request);

        if ($pattern !== '') {
            return new DateFormatter()->format($date, $format, $locale);
        }
        return $date->format($format);
    }

    protected function resolveDateString($content, array $conf): string
    {
        $content = trim((string) $content);

        if (is_array($conf['date.'] ?? null)) {
            $conf['date'] = $this->cObj->stdWrap($content, $conf['date.']);
        }

        $test = $conf['date'] ?? $content;

        return $conf['date'] ?? $content;
    }

    /**
     * Converts date input to DateTimeInterface instance.
     *
     * @param int|string|\DateTimeInterface $date Timestamp, date string, or DateTime object
     * @throws Exception If date string cannot be parsed
     */
    protected function resolveDate(mixed $date): \DateTimeInterface
    {
        if (!$date instanceof \DateTimeInterface) {
            $dateTimestamp = strtotime(
                (MathUtility::canBeInterpretedAsInteger($date) ? '@' : '') . $date,
                $this->resolveTimestamp()
            );
            if ($dateTimestamp === false) {
                throw new Exception(
                    '"' . $date . '" could not be converted to a timestamp. Probably due to a parsing error.',
                    1770973934
                );
            }
            return new \DateTime()->setTimestamp($dateTimestamp);
        }
        return $date;

    }

    /**
     * Resolves locale from request context or backend user.
     */
    private function resolveLocale(ServerRequestInterface $request): Locale
    {
        if (ApplicationType::fromRequest($request)->isFrontend()) {
            // Frontend application
            $siteLanguage = $request->getAttribute('language');

            // Get values from site language
            if ($siteLanguage !== null) {
                return $siteLanguage->getLocale();
            }
        } elseif (($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication
            && !empty($GLOBALS['BE_USER']->user['lang'])) {
            return new Locale($GLOBALS['BE_USER']->user['lang']);
        }
        return new Locale();
    }

    /**
     * Extracts date format from configuration or system defaults.
     *
     * @param array<string, mixed> $conf TypoScript configuration
     * @return string Date format string
     */
    protected function resolveFormat(array $conf): string
    {
        if (is_array($conf['format.'] ?? null)) {
            $conf['format'] = $this->cObj->stdWrap('', $conf['format.']);
        }

        return $conf['format'] ?? $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'Y-m-d';
    }

    /**
     * Extracts date pattern from configuration.
     *
     * @param array<string, mixed> $conf TypoScript configuration
     * @return string Date pattern string
     */
    protected function resolvePattern(array $conf): string
    {
        if (is_array($conf['pattern.'] ?? null)) {
            $conf['pattern'] = $this->cObj->stdWrap('', $conf['pattern.']);
        }

        return $conf['pattern'] ?? '';
    }

    /**
     * Retrieves timestamp from context aspect or falls back to current time.
     *
     * @param mixed $default Default value if context access fails
     * @return int Unix timestamp
     */
    protected function resolveTimestamp(mixed $default = null): int
    {
        try {
            return $this->context->getPropertyFromAspect('date', 'timestamp', $default);
        } catch (\Exception) {
            return time();
        }
    }

}
