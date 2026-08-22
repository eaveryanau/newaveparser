<?php

namespace App\Client;

interface ApiClientInterface
{
    /**
     * Возвращает массив объявлений для указанной страницы.
     */
    public function fetchPage(int $page): array;

    /**
     * Возвращает идентификатор источника (например, 'avby', 'another').
     */
    public function getSource(): string;
}
