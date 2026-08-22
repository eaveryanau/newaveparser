<?php

namespace App\Client;

use GuzzleHttp\ClientInterface;

class AnotherClient implements ApiClientInterface
{
    public function __construct(private ClientInterface $http) {}

    public function fetchPage(int $page): array
    {
        // Здесь будет реальная логика для другого API.
        // Сейчас возвращаем пустой результат для примера.
        return [
            'count' => 0,
            'pageCount' => 0,
            'adverts' => []
        ];
    }

    public function getSource(): string
    {
        return 'another';
    }
}
