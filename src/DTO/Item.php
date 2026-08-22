<?php

namespace App\DTO;

class Item
{
    public string $source;
    public string $id;
    public string $title;
    public ?string $description = null;
    public float $price = 0.0;
    public string $currency = 'USD';
    public string $url = '';
    public ?string $imageUrl = null;
    public array $raw = [];

    // Поля, специфичные для av.by (но могут отсутствовать)
    public ?string $generation = null;
    public ?string $generationWithYears = null;
    public ?string $engineCapacity = null;
    public ?string $engineType = null;
    public ?string $transmissionType = null;
    public ?string $brand = null;
    public ?string $model = null;
}
