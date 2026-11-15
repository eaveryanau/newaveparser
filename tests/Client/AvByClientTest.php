<?php

namespace App\Tests\Client;

use App\Client\AvByClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AvByClientTest extends TestCase
{
    public function testFetchPageSendsExpectedRequest(): void
    {
        $fixture = file_get_contents(__DIR__ . '/../fixtures/avby/page.json');
        self::assertIsString($fixture);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $fixture),
        ]));
        $stack->push(Middleware::history($history));

        $client = new AvByClient(new Client(['handler' => $stack]));
        $result = $client->fetchPage(2);

        self::assertSame(json_decode($fixture, true), $result);
        self::assertCount(1, $history);

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/offer-types/cars/filters/main/apply', $request->getUri()->getPath());
        self::assertSame('https://cars.av.by', $request->getHeaderLine('Origin'));
        self::assertSame('https://cars.av.by/', $request->getHeaderLine('Referer'));
        self::assertSame('web.desktop', $request->getHeaderLine('X-Device-Type'));
        self::assertStringContainsString('Mozilla/5.0', $request->getHeaderLine('User-Agent'));

        $payload = json_decode((string) $request->getBody(), true);
        self::assertSame(2, $payload['page']);
        self::assertSame(4, $payload['sorting']);
        self::assertSame('price_currency', $payload['properties'][0]['name']);
    }

    public function testGetSource(): void
    {
        $client = new AvByClient(new Client(['handler' => HandlerStack::create(
            new MockHandler()
        )]));

        self::assertSame('avby', $client->getSource());
    }
}
