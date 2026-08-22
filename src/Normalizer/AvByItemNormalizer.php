<?php

namespace App\Normalizer;

use App\DTO\Item;

class AvByItemNormalizer implements ItemNormalizerInterface
{
    public function normalize(array $raw): Item
    {
        $item = new Item();
        $item->id = (string)$raw['id'];
        $item->source = 'avby';
        $item->raw = $raw;

        // Извлекаем все нужные свойства
        $brand = $model = $year = '';
        $item->generation = null;
        $item->generationWithYears = null;
        $item->engineCapacity = null;
        $item->engineType = null;
        $item->transmissionType = null;

        foreach ($raw['properties'] as $prop) {
            switch ($prop['name']) {
                case 'brand': $brand = $prop['value']; break;
                case 'model': $model = $prop['value']; break;
                case 'year': $year = $prop['value']; break;
                case 'generation': $item->generation = $prop['value']; break;
                case 'generation_with_years': $item->generationWithYears = $prop['value']; break;
                case 'engine_capacity': $item->engineCapacity = $prop['value']; break;
                case 'engine_type': $item->engineType = $prop['value']; break;
                case 'transmission_type': $item->transmissionType = $prop['value']; break;
            }
        }

        // Формируем title: "Марка Модель, Год"
        $item->title = trim("$brand $model, $year");

        $item->description = $raw['description'] ?? null;

        // Цена в USD
        $item->price = (float)($raw['price']['usd']['amount'] ?? 0);
        $item->currency = 'USD';

        $item->url = $raw['publicUrl'] ?? '';

        // Фото – medium jpg
        if (!empty($raw['photos'])) {
            $item->imageUrl = $raw['photos'][0]['medium']['url']
                ?? $raw['photos'][0]['small']['url']
                ?? $raw['photos'][0]['big']['url']
                ?? null;
        }

        return $item;
    }

    public function supports(string $source): bool
    {
        return $source === 'avby';
    }
}
