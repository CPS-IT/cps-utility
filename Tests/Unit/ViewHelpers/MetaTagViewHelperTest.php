<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Tests\Unit\ViewHelpers;

use Cpsit\CpsUtility\ViewHelpers\MetaTagViewHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class MetaTagViewHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GeneralUtility::setIndpEnv('TYPO3_REQUEST_URL', 'https://example.com/current/page');
        GeneralUtility::setIndpEnv('TYPO3_SITE_URL', 'https://example.com/');
    }

    protected function tearDown(): void
    {
        GeneralUtility::flushInternalRuntimeCaches();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{0: string, 1: string, 2: string} $expectedCall
     */
    #[DataProvider('metaTagProvider')]
    public function testRenderSetsMetaTag(array $arguments, array $expectedCall): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::once())->method('setMetaTag')->with(...$expectedCall);

        $this->render($pageRenderer, $arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('noMetaTagProvider')]
    public function testRenderSetsNoMetaTag(array $arguments): void
    {
        $pageRenderer = $this->createMock(PageRenderer::class);
        $pageRenderer->expects(self::never())->method('setMetaTag');

        $this->render($pageRenderer, $arguments);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array{0: string, 1: string, 2: string}}>
     */
    public static function metaTagProvider(): array
    {
        return [
            'property and content' => [
                ['property' => 'og:title', 'content' => 'TYPO3 is awesome'],
                ['property', 'og:title', 'TYPO3 is awesome'],
            ],
            // pre-existing behavior: the name branch passes the type "property"
            'name and content' => [
                ['name' => 'keywords', 'content' => 'news 1, news 2'],
                ['property', 'keywords', 'news 1, news 2'],
            ],
            'property wins over name' => [
                ['property' => 'og:title', 'name' => 'title', 'content' => 'Title'],
                ['property', 'og:title', 'Title'],
            ],
            'empty property falls back to name' => [
                ['property' => '', 'name' => 'title', 'content' => 'Title'],
                ['property', 'title', 'Title'],
            ],
            'current domain replaces content' => [
                ['property' => 'og:url', 'content' => 'ignored', 'useCurrentDomain' => true],
                ['property', 'og:url', 'https://example.com/current/page'],
            ],
            'current domain without content' => [
                ['property' => 'og:url', 'useCurrentDomain' => true],
                ['property', 'og:url', 'https://example.com/current/page'],
            ],
            'force absolute url prepends the site url' => [
                ['property' => 'og:image', 'content' => '/fileadmin/image.jpg', 'forceAbsoluteUrl' => true],
                ['property', 'og:image', 'https://example.com/fileadmin/image.jpg'],
            ],
            'force absolute url keeps a url with host' => [
                ['property' => 'og:image', 'content' => 'https://cdn.example.org/image.jpg', 'forceAbsoluteUrl' => true],
                ['property', 'og:image', 'https://cdn.example.org/image.jpg'],
            ],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function noMetaTagProvider(): array
    {
        return [
            'no content' => [['property' => 'og:title']],
            'empty content' => [['property' => 'og:title', 'content' => '']],
            'neither property nor name' => [['content' => 'Title']],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(PageRenderer $pageRenderer, array $arguments): void
    {
        $subject = new MetaTagViewHelper($pageRenderer);
        $subject->initializeArguments();
        $subject->setArguments($arguments + [
            'property' => null,
            'name' => null,
            'content' => null,
            'useCurrentDomain' => false,
            'forceAbsoluteUrl' => false,
        ]);
        $subject->initialize();
        $subject->render();
    }
}
