<?php

namespace App\Tests\Normalizer;

use App\DTO\Item;
use App\Normalizer\HhItemNormalizer;
use PHPUnit\Framework\TestCase;

class HhItemNormalizerTest extends TestCase
{
    private HhItemNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new HhItemNormalizer();
    }

    public function testSupportsHh(): void
    {
        self::assertTrue($this->normalizer->supports('hh'));
        self::assertFalse($this->normalizer->supports('avby'));
    }

    public function testNormalizeVacancyWithFullSalary(): void
    {
        $raw = [
            'vacancyId' => 98765,
            'name' => 'PHP Developer',
            'company' => [
                'name' => 'Tech Corp',
                'logos' => [
                    'logo' => [
                        ['@type' => 'medium', '@url' => '/logos/logo.png'],
                    ],
                ],
            ],
            'compensation' => [
                'from' => 3000,
                'to' => 5000,
                'currencyCode' => 'USD',
            ],
            'area' => ['name' => 'Минск'],
            'workExperience' => 'between1And3',
            'workFormats' => [
                ['workFormatsElement' => ['REMOTE']],
            ],
            'links' => ['desktop' => 'https://hh.ru/vacancy/98765'],
        ];

        $item = $this->normalizer->normalize($raw);

        self::assertInstanceOf(Item::class, $item);
        self::assertSame('98765', $item->id);
        self::assertSame('hh', $item->source);
        self::assertSame('PHP Developer', $item->title);
        self::assertSame(5000.0, $item->price);
        self::assertSame('USD', $item->currency);
        self::assertStringContainsString('Tech Corp', $item->description);
        self::assertStringContainsString('3000', $item->description);
        self::assertStringContainsString('Минск', $item->description);
        self::assertStringContainsString('1-3 года', $item->description);
        self::assertStringContainsString('✅ Удалённо', $item->description);
        self::assertSame('https://hh.ru/vacancy/98765', $item->url);
        self::assertSame('https://hh.ru/logos/logo.png', $item->imageUrl);
    }

    public function testNormalizeVacancyWithoutSalary(): void
    {
        $raw = [
            'vacancyId' => 111,
            'name' => 'Junior Developer',
            'company' => ['name' => 'Startup'],
            'area' => ['name' => 'Москва'],
            'links' => ['desktop' => 'https://hh.ru/vacancy/111'],
        ];

        $item = $this->normalizer->normalize($raw);

        self::assertSame('111', $item->id);
        self::assertSame('Junior Developer', $item->title);
        self::assertSame(0.0, $item->price);
        self::assertSame('RUR', $item->currency);
        self::assertStringContainsString('Зарплата не указана', $item->description);
        self::assertNull($item->imageUrl);
    }
}