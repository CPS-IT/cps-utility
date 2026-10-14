<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Tests\Unit\Traits;

use Cpsit\CpsUtility\Traits\FeCacheTagsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

final class FeCacheTagsTraitTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TSFE'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    public function testCacheCollectorIsUsedWhenRequestHasCollectorAttribute(): void
    {
        if (!class_exists(CacheTag::class)) {
            self::markTestSkipped('CacheTag exists since TYPO3 13.3, the collector path cannot run on 12.4.');
        }

        $collector = $this->createCollector();
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest($collector);
        $tsfe = $this->createMock(TypoScriptFrontendController::class);
        $tsfe->expects(self::never())->method('addCacheTags');
        $GLOBALS['TSFE'] = $tsfe;

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a', 'tag_b']);

        self::assertSame(['tag_a', 'tag_b'], array_map(static fn(CacheTag $tag): string => $tag->name, $collector->received));
        self::assertSame(PHP_INT_MAX, $collector->received[0]->lifetime);
    }

    public function testTypoScriptFrontendControllerIsUsedWithoutCollectorAttribute(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest(null);
        $tsfe = $this->createMock(TypoScriptFrontendController::class);
        $tsfe->expects(self::once())->method('addCacheTags')->with(['tag_a', 'tag_b']);
        $GLOBALS['TSFE'] = $tsfe;

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a', 'tag_b']);
    }

    public function testTypoScriptFrontendControllerIsUsedWithoutRequest(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $tsfe = $this->createMock(TypoScriptFrontendController::class);
        $tsfe->expects(self::once())->method('addCacheTags')->with(['tag_a']);
        $GLOBALS['TSFE'] = $tsfe;

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a']);
    }

    public function testNothingIsTaggedOutsideFrontendContext(): void
    {
        unset($GLOBALS['TSFE']);
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest(null);

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a']);

        $this->expectNotToPerformAssertions();
    }

    public function testTagsAreSetOnlyOncePerRequest(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest(null);
        $tsfe = $this->createMock(TypoScriptFrontendController::class);
        $tsfe->expects(self::once())->method('addCacheTags')->with(['tag_a']);
        $GLOBALS['TSFE'] = $tsfe;

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a']);
        $subject->addCacheTags(['tag_b']);
    }

    private function createRequest(?object $collector): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => $name === 'frontend.cache.collector' ? ($collector ?? $default) : $default
        );

        return $request;
    }

    private function createCollector(): object
    {
        return new class {
            /** @var list<CacheTag> */
            public array $received = [];

            public function addCacheTags(CacheTag ...$cacheTags): void
            {
                array_push($this->received, ...$cacheTags);
            }
        };
    }
}
