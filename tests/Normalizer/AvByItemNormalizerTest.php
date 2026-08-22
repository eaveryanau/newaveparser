<?php

namespace App\Tests\Normalizer;

use App\DTO\Item;
use App\Normalizer\AvByItemNormalizer;
use PHPUnit\Framework\TestCase;

class AvByItemNormalizerTest extends TestCase
{
    private AvByItemNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AvByItemNormalizer();
    }

    public function testSupportsAvby(): void
    {
        self::assertTrue($this->normalizer->supports('avby'));
        self::assertFalse($this->normalizer->supports('hh'));
    }

    public function testNormalizeFullCarListing(): void
    {
        $raw = [
            'id' => 12345,
            'properties' => [
                ['name' => 'brand', 'value' => 'BMW'],
                ['name' => 'model', 'value' => 'X5'],
                ['name' => 'year', 'value' => '2020'],
                ['name' => 'generation', 'value' => 'G05'],
                ['name' => 'generation_with_years', 'value' => 'G05 (2018-)'],
                ['name' => 'engine_capacity', 'value' => '3.0'],
                ['name' => 'engine_type', 'value' => 'Дизель'],
                ['name' => 'transmission_type', 'value' => 'Автомат'],
            ],
            'price' => ['usd' => ['amount' => 35000]],
            'publicUrl' => 'https://cars.av.by/bmw-x5/12345',
            'description' => 'Отличное состояние',
            'photos' => [
                ['medium' => ['url' => 'https://img.av.by/medium.jpg']],
            ],
        ];

        $item = $this->normalizer->normalize($raw);

        self::assertInstanceOf(Item::class, $item);
        self::assertSame('12345', $item->id);
        self::assertSame('avby', $item->source);
        self::assertSame('BMW X5, 2020', $item->title);
        self::assertSame('Отличное состояние', $item->description);
        self::assertSame(35000.0, $item->price);
        self::assertSame('USD', $item->currency);
        self::assertSame('https://cars.av.by/bmw-x5/12345', $item->url);
        self::assertSame('https://img.av.by/medium.jpg', $item->imageUrl);
        self::assertSame('G05', $item->generation);
        self::assertSame('G05 (2018-)', $item->generationWithYears);
        self::assertSame('3.0', $item->engineCapacity);
        self::assertSame('Дизель', $item->engineType);
        self::assertSame('Автомат', $item->transmissionType);
    }

    public function testNormalizeMinimalData(): void
    {
        $raw = [
            'id' => 67890,
            'properties' => [
                ['name' => 'brand', 'value' => 'Audi'],
                ['name' => 'model', 'value' => 'A4'],
                ['name' => 'year', 'value' => '2018'],
            ],
            'price' => ['usd' => ['amount' => 15000]],
            'publicUrl' => 'https://cars.av.by/audi-a4/67890',
        ];

        $item = $this->normalizer->normalize($raw);

        self::assertSame('67890', $item->id);
        self::assertSame('Audi A4, 2018', $item->title);
        self::assertSame(15000.0, $item->price);
        self::assertNull($item->description);
        self::assertNull($item->imageUrl);
        self::assertNull($item->generation);
        self::assertNull($item->engineCapacity);
    }
}