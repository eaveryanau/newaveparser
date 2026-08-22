<?php

namespace App\Normalizer;

use App\DTO\Item;

class AnotherItemNormalizer implements ItemNormalizerInterface
{
    public function normalize(array $raw): Item
    {
        $item = new Item();
        $item->id = (string)($raw['id'] ?? '');
        $item->source = 'another';
        $item->title = $raw['title'] ?? 'Без названия';
        $item->generation = $raw['generation'] ?? null;
        $item->generationWithYears = $raw['generation_with_years'] ?? null;
        $item->engineCapacity = $raw['engine_capacity'] ?? null;
        $item->engineType = $raw['engine_type'] ?? null;
        $item->transmissionType = $raw['transmission_type'] ?? null;
        $item->description = $raw['description'] ?? null;
        $item->price = (float)($raw['price'] ?? 0);
        $item->currency = $raw['currency'] ?? 'USD';
        $item->url = $raw['url'] ?? '';
        $item->imageUrl = $raw['image'] ?? null;
        $item->raw = $raw;
        return $item;
    }

    public function supports(string $source): bool
    {
        return $source === 'another';
    }
}
