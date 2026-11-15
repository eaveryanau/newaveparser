<?php

namespace App\Tests\Telegram;

use App\DTO\Item;
use App\Telegram\TelegramSender;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
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

        self::assertTrue($sender->sendItem($this->item()));

        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/botTOKEN/sendPhoto', $request->getUri()->getPath());

        parse_str((string) $request->getBody(), $form);
        self::assertSame('@cars', $form['chat_id']);
        self::assertSame('https://img.av.by/medium.jpg', $form['photo']);
        self::assertSame('HTML', $form['parse_mode']);
        self::assertSame('1', $form['disable_notification']);
        self::assertStringContainsString('<b>Mercedes-Benz C-MAX, 2007</b>', $form['caption']);
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

        self::assertTrue($sender->sendItem($item));

        $request = $history[0]['request'];
        self::assertSame('/botTOKEN/sendMessage', $request->getUri()->getPath());

        parse_str((string) $request->getBody(), $form);
        self::assertSame('@jobs', $form['chat_id']);
        self::assertSame('1', $form['disable_web_page_preview']);
        self::assertStringContainsString('Tech Corp', $form['text']);
        self::assertStringContainsString('<a href="https://cars.av.by/item/1">', $form['text']);
    }

    public function testSendItemReturnsFalseOnApiError(): void
    {
        $http = $this->httpWithResponses([
            new Response(500, [], '{"ok":false,"description":"Internal error"}'),
        ]);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        self::assertFalse($sender->sendItem($this->item()));
    }

    public function testGetSource(): void
    {
        $http = $this->httpWithResponses([]);
        $sender = new TelegramSender($http, 'TOKEN', '@cars', new NullLogger(), 'avby');

        self::assertSame('avby', $sender->getSource());
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

        return $item;
    }
}
