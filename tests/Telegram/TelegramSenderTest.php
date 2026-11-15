<?php

namespace App\Tests\Telegram;

use App\DTO\Item;
use App\Telegram\TelegramSender;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TelegramSenderTest extends TestCase
{
    public function testSendItemWithImageUsesSendPhoto(): void
    {
        $history = [];
        $http = $this->httpWithResponses([new Response(200, [], '{"ok":true}')], $history);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $result = $sender->sendItem($this->item());

        self::assertTrue($result->success);

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/botTOKEN/sendPhoto', $request->getUri()->getPath());

        parse_str((string) $request->getBody(), $form);
        self::assertSame('@cars', $form['chat_id']);
        self::assertSame('https://img.av.by/medium.jpg', $form['photo']);
        self::assertSame('HTML', $form['parse_mode']);
        self::assertSame('1', $form['disable_notification']);
        self::assertStringContainsString('<b>Mercedes-Benz C-MAX, 2007</b>', $form['caption']);
        self::assertStringContainsString("</b>\n\nC-MAX (2003-2007)", $form['caption']);
        self::assertStringContainsString("</a></b>\n\nNice car\n\n#MercedesBenzCMAX", $form['caption']);
        self::assertStringContainsString('580 000 км', $form['caption']);
        self::assertStringContainsString('#MercedesBenzCMAX', $form['caption']);
    }

    public function testSendItemWithoutImageUsesSendMessage(): void
    {
        $history = [];
        $http = $this->httpWithResponses([new Response(200, [], '{"ok":true}')], $history);
        $sender = new TelegramSender($http, 'TOKEN', '@jobs', new NullLogger(), 'hh');

        $item = $this->item();
        $item->imageUrl = null;
        $item->title = 'PHP Developer';
        $item->description = 'Tech Corp';

        $result = $sender->sendItem($item);

        self::assertTrue($result->success);

        $request = $history[0]['request'];
        self::assertSame('/botTOKEN/sendMessage', $request->getUri()->getPath());

        parse_str((string) $request->getBody(), $form);
        self::assertSame('@jobs', $form['chat_id']);
        self::assertSame('1', $form['disable_web_page_preview']);
        self::assertStringContainsString('Tech Corp', $form['text']);
        self::assertStringContainsString('<a href="https://cars.av.by/item/1">', $form['text']);
    }

    public function testServerErrorIsRetryable(): void
    {
        $http = $this->httpWithResponses([
            new Response(500, [], '{"ok":false,"description":"Internal error"}'),
        ]);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $result = $sender->sendItem($this->item());

        self::assertFalse($result->success);
        self::assertTrue($result->retryable);
        self::assertSame('Internal error', $result->error);
    }

    public function testClientErrorIsPermanent(): void
    {
        $http = $this->httpWithResponses([
            new Response(400, [], '{"ok":false,"description":"Bad Request: chat not found"}'),
        ]);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $result = $sender->sendItem($this->item());

        self::assertFalse($result->success);
        self::assertFalse($result->retryable);
        self::assertSame('Bad Request: chat not found', $result->error);
    }

    public function testRateLimitReturnsRetryAfter(): void
    {
        $http = $this->httpWithResponses([
            new Response(429, [], '{"ok":false,"description":"Too Many Requests","parameters":{"retry_after":7}}'),
        ]);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $result = $sender->sendItem($this->item());

        self::assertFalse($result->success);
        self::assertTrue($result->retryable);
        self::assertSame(7, $result->retryAfter);
    }

    public function testNetworkErrorDoesNotLeakToken(): void
    {
        $http = $this->httpWithResponses([
            new ConnectException(
                'cURL error 28: timeout for https://api.telegram.org/botTOKEN/sendPhoto',
                new Request('POST', 'https://api.telegram.org/botTOKEN/sendPhoto')
            ),
        ]);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $result = $sender->sendItem($this->item());

        self::assertTrue($result->retryable);
        self::assertStringNotContainsString('TOKEN', (string) $result->error);
        self::assertStringContainsString('[REDACTED]', (string) $result->error);
    }

    public function testHtmlInContentIsEscaped(): void
    {
        $history = [];
        $http = $this->httpWithResponses([new Response(200, [], '{"ok":true}')], $history);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $item = $this->item();
        $item->title = '<script>alert(1)</script>';
        $item->description = 'A & B <b>bold</b>';

        self::assertTrue($sender->sendItem($item)->success);

        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertStringNotContainsString('<script>', $form['caption']);
        self::assertStringContainsString('&lt;script&gt;', $form['caption']);
        self::assertStringContainsString('A &amp; B &lt;b&gt;bold&lt;/b&gt;', $form['caption']);
    }

    public function testPhotoCaptionIsTruncatedToTelegramLimit(): void
    {
        $history = [];
        $http = $this->httpWithResponses([new Response(200, [], '{"ok":true}')], $history);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $item = $this->item();
        $item->description = str_repeat('x', 5000);

        self::assertTrue($sender->sendItem($item)->success);

        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertLessThanOrEqual(1024, $this->visibleLength($form['caption']));
        self::assertStringContainsString('…', $form['caption']);
    }

    public function testMessageTextIsTruncatedToTelegramLimit(): void
    {
        $history = [];
        $http = $this->httpWithResponses([new Response(200, [], '{"ok":true}')], $history);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $item = $this->item();
        $item->imageUrl = null;
        $item->description = str_repeat('x', 5000);

        self::assertTrue($sender->sendItem($item)->success);

        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertLessThanOrEqual(4096, $this->visibleLength($form['text']));
        self::assertStringContainsString('…', $form['text']);
    }

    public function testCaptionLimitCountsUtf16Units(): void
    {
        $history = [];
        $http = $this->httpWithResponses([new Response(200, [], '{"ok":true}')], $history);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        $item = $this->item();
        $item->description = str_repeat('😀', 1000);

        self::assertTrue($sender->sendItem($item)->success);

        parse_str((string) $history[0]['request']->getBody(), $form);
        self::assertLessThanOrEqual(1024, $this->utf16Length($form['caption']));
        self::assertStringContainsString('…', $form['caption']);
    }

    public function testGetSource(): void
    {
        $http = $this->httpWithResponses([]);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        self::assertSame('avby', $sender->getSource());
    }

    private function visibleLength(string $html): int
    {
        return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    private function utf16Length(string $html): int
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function httpWithResponses(array $responses, ?array &$history = null): Client
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client(['handler' => $stack, 'base_uri' => 'https://api.telegram.org']);
    }

    private function item(): Item
    {
        $item = new Item();
        $item->id = '1';
        $item->source = 'avby';
        $item->title = 'Mercedes-Benz C-MAX, 2007';
        $item->description = 'Nice car';
        $item->price = 10000.0;
        $item->currency = 'USD';
        $item->url = 'https://cars.av.by/item/1';
        $item->imageUrl = 'https://img.av.by/medium.jpg';
        $item->generationWithYears = 'C-MAX (2003-2007)';
        $item->engineCapacity = '2.0';
        $item->engineType = 'Дизель';
        $item->transmissionType = 'Автомат';
        $item->mileage = 580000;

        return $item;
    }
}
