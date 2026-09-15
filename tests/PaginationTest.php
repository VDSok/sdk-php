<?php

declare(strict_types=1);

namespace Vdsok\Sdk\Tests;

use Vdsok\Sdk\Page;

final class PaginationTest extends TestCase
{
    public function testIterateWalksAllPagesPassingTheCursorBack(): void
    {
        $this->respond(200, ['data' => [['id' => 1], ['id' => 2]], 'next_cursor' => 'c2']);
        $this->respond(200, ['data' => [['id' => 3]], 'next_cursor' => 'c3']);
        $this->respond(200, ['data' => [['id' => 4]], 'next_cursor' => null]);

        $ids = [];
        foreach ($this->client()->servers->iterate(['status' => 'active', 'limit' => 2]) as $server) {
            $ids[] = $server['id'];
        }

        self::assertSame([1, 2, 3, 4], $ids);
        $queries = array_map(static fn ($r) => $r->getUri()->getQuery(), $this->requests());
        self::assertSame([
            'status=active&limit=2',
            'cursor=c2&status=active&limit=2',
            'cursor=c3&status=active&limit=2',
        ], $queries);
    }

    public function testPagesYieldsPageObjects(): void
    {
        $this->respond(200, ['data' => [['id' => 1]], 'next_cursor' => 'x'], ['X-Request-ID' => 'req_p1']);
        $this->respond(200, ['data' => [], 'next_cursor' => null], ['X-Request-ID' => 'req_p2']);

        $pages = iterator_to_array($this->client()->invoices->iterate()->pages(), false);

        self::assertCount(2, $pages);
        self::assertInstanceOf(Page::class, $pages[0]);
        self::assertSame('x', $pages[0]->nextCursor);
        self::assertTrue($pages[0]->hasMore());
        self::assertSame('req_p1', $pages[0]->requestId);
        self::assertFalse($pages[1]->hasMore());
        self::assertTrue($pages[1]->isEmpty());
        self::assertNull($pages[1]->first());
    }

    public function testTakeStopsEarlyWithoutFetchingMorePages(): void
    {
        $this->respond(200, ['data' => [['id' => 1], ['id' => 2]], 'next_cursor' => 'c2']);
        $this->respond(200, ['data' => [['id' => 3], ['id' => 4]], 'next_cursor' => 'c3']);
        $this->respond(200, ['data' => [['id' => 5]], 'next_cursor' => null]);

        $items = $this->client()->domains->iterate()->take(3)->toArray();

        self::assertSame([1, 2, 3], array_column($items, 'id'));
        self::assertCount(2, $this->requests());
    }

    public function testBreakingOutOfForeachDoesNotFetchTheNextPage(): void
    {
        $this->respond(200, ['data' => [['id' => 1]], 'next_cursor' => 'c2']);
        $this->respond(200, ['data' => [['id' => 2]], 'next_cursor' => null]);

        foreach ($this->client()->servers->orders->iterate() as $order) {
            self::assertSame(1, $order['id']);
            break;
        }
        self::assertCount(1, $this->requests());
    }

    public function testRepeatedCursorStopsTheLoop(): void
    {
        $this->respond(200, ['data' => [['id' => 1]], 'next_cursor' => 'same']);
        $this->respond(200, ['data' => [['id' => 2]], 'next_cursor' => 'same']);
        $this->respond(200, ['data' => [['id' => 3]], 'next_cursor' => 'same']);

        $items = $this->client()->balance->iterateTransactions()->toArray();

        self::assertSame([1, 2], array_column($items, 'id'));
        self::assertCount(2, $this->requests());
    }

    public function testPageHelpers(): void
    {
        $this->respond(200, ['data' => [['id' => 7, 'name' => 'a'], ['id' => 8, 'name' => 'b']], 'next_cursor' => '']);
        $page = $this->client()->servers->list(['limit' => 2]);

        self::assertSame([['id' => 7, 'name' => 'a'], ['id' => 8, 'name' => 'b']], $page->items());
        self::assertSame(['id' => 7, 'name' => 'a'], $page->first());
        self::assertCount(2, $page);
        self::assertNull($page->nextCursor, 'empty string cursor means no more pages');
        self::assertFalse($page->hasMore());
        self::assertSame([7, 8], array_column(iterator_to_array($page), 'id'));
        self::assertSame('limit=2', $this->lastRequest()->getUri()->getQuery());
    }

    public function testListsWithoutCursorAreStillPages(): void
    {
        $this->respond(200, ['data' => [['id' => 12, 'name' => 'VDS-1']]]);
        $page = $this->client()->catalog->tariffs();

        self::assertInstanceOf(Page::class, $page);
        self::assertNull($page->nextCursor);
        self::assertSame('VDS-1', $page->first()['name']);
    }

    public function testIteratorCanBeWalkedTwice(): void
    {
        $this->respond(200, ['data' => [['id' => 1]], 'next_cursor' => null]);
        $this->respond(200, ['data' => [['id' => 1]], 'next_cursor' => null]);

        $paginator = $this->client()->servers->iterate();
        self::assertCount(1, $paginator->toArray());
        self::assertCount(1, $paginator->toArray());
        self::assertCount(2, $this->requests(), 'each walk starts from the first page again');
    }

    public function testFindByIp(): void
    {
        $this->respond(200, ['data' => [['id' => 2001, 'ip' => '203.0.113.10']], 'next_cursor' => null]);
        $server = $this->client()->servers->findByIp('203.0.113.10');

        self::assertSame(2001, $server['id']);
        self::assertSame('ip=203.0.113.10&limit=1', $this->lastRequest()->getUri()->getQuery());
    }
}
