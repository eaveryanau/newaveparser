<?php

namespace App\Client;

use GuzzleHttp\ClientInterface;

class AvByClient implements ApiClientInterface
{
    public function __construct(private ClientInterface $http) {}

    public function fetchPage(int $page = 1): array
    {
        $payload = [
            'page' => $page,
            'properties' => [
                ['name' => 'price_currency', 'value' => 2],
                ['name' => 'median_price_range_type', 'value' => [1]]
            ],
            'sorting' => 4
        ];

        $response = $this->http->post('/offer-types/cars/filters/main/apply', [
            'json' => $payload,
            'headers' => [
                'Accept' => '*/*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control' => 'no-cache',
                'Origin' => 'https://cars.av.by',
                'Referer' => 'https://cars.av.by/',
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
                'X-Device-Type' => 'web.desktop',
                'Content-Type' => 'application/json',
                'Pragma' => 'no-cache',
                'Priority' => 'u=1, i',
                'Sec-Ch-Ua' => '"Not;A=Brand";v="8", "Chromium";v="150", "Google Chrome";v="150"',
                'Sec-Ch-Ua-Mobile' => '?0',
                'Sec-Ch-Ua-Platform' => '"macOS"',
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-site',
            ]
        ]);

        return json_decode($response->getBody(), true);
    }

    public function getSource(): string
    {
        return 'avby';
    }
}
