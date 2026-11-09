<?php

declare(strict_types=1);

namespace Cpsit\CpsUtility\Tests\Unit\Traits;

use Cpsit\CpsUtility\Traits\FeCacheTagsTrait;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheTag;

final class FeCacheTagsTraitTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    public function testCacheCollectorIsUsedWhenRequestHasCollectorAttribute(): void
    {
        $collector = $this->createCollector();
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest($collector);

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a', 'tag_b']);

        self::assertSame(['tag_a', 'tag_b'], array_map(static fn(CacheTag $tag): string => $tag->name, $collector->received));
        self::assertSame(PHP_INT_MAX, $collector->received[0]->lifetime);
    }

    public function testNothingIsTaggedWithoutCollectorAttribute(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest(null);

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a']);

        $this->expectNotToPerformAssertions();
    }

    public function testNothingIsTaggedWithoutRequest(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a']);

        $this->expectNotToPerformAssertions();
    }

    public function testTagsAreSetOnlyOncePerRequest(): void
    {
        $collector = $this->createCollector();
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest($collector);

        $subject = new class {
            use FeCacheTagsTrait;
        };
        $subject->addCacheTags(['tag_a']);
        $subject->addCacheTags(['tag_b']);

        self::assertSame(['tag_a'], array_map(static fn(CacheTag $tag): string => $tag->name, $collector->received));
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
