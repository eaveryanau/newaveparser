<?php

namespace App\Tests\Client;

use App\Client\HhClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class HhClientTest extends TestCase
{
    public function testFetchPageUsesZeroBasedPaginationAndConfiguredAreas(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../fixtures/hh/page.json');
        self::assertIsString($fixture);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $fixture),
        ]));
        $stack->push(Middleware::history($history));

        $http = new Client(['handler' => $stack, 'base_uri' => 'https://hh.ru']);
        $client = new HhClient($http, 'PHP', '1002,16');

        $result = $client->fetchPage(1);

        self::assertSame(120, $result['count']);
        self::assertSame(3, $result['pageCount']);
        self::assertSame('PHP Developer', $result['adverts'][0]['name']);

        $request = $history[0]['request'];
        self::assertSame('/shards/vacancy/search', $request->getUri()->getPath());
        self::assertSame('hh.ru', $request->getUri()->getHost());
        self::assertSame('XMLHttpRequest', $request->getHeaderLine('x-requested-with'));

        $query = Query::parse($request->getUri()->getQuery());
        self::assertSame('0', $query['page']);
        self::assertSame('PHP', $query['text']);
        self::assertSame('1002', $query['area[0]']);
        self::assertSame('16', $query['area[1]']);
        self::assertSame('name', $query['search_field[0]']);
        self::assertSame('company_name', $query['search_field[1]']);
        self::assertSame('description', $query['search_field[2]']);
    }

    public function testFetchPageIncrementsZeroBasedPage(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"vacancySearchResult":{"totalResults":0,"vacancies":[]}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"vacancySearchResult":{"totalResults":0,"vacancies":[]}}'),
        ]));
        $stack->push(Middleware::history($history));

        $client = new HhClient(new Client(['handler' => $stack]), 'PHP', '16');
        $client->fetchPage(2);

        $query = Query::parse($history[0]['request']->getUri()->getQuery());
        self::assertSame('1', $query['page']);
    }

    public function testGetSource(): void
    {
        $client = new HhClient(new Client(['handler' => HandlerStack::create(
            new MockHandler()
        )]));

        self::assertSame('hh', $client->getSource());
    }
}
