<?php

namespace App\Normalizer;

use App\DTO\Item;

class HhItemNormalizer implements ItemNormalizerInterface
{
    public function normalize(array $raw): Item
    {
        $item = new Item();
        $item->id = (string) ($raw['vacancyId'] ?? '');
        $item->source = 'hh';
        $item->raw = $raw;

        // Название вакансии
        $item->title = $raw['name'] ?? 'Без названия';

        // Компания
        $company = $raw['company']['name'] ?? '';
        $item->description = "Компания: $company\n";

        // Зарплата
        $salary = $raw['compensation'] ?? [];
        if (!empty($salary) && isset($salary['from']) && isset($salary['to'])) {
            $item->price = (float) $salary['to'];
            $item->currency = $salary['currencyCode'] ?? 'RUR';
            $item->description .= "Зарплата: {$salary['from']} - {$salary['to']} {$item->currency}\n";
        } elseif (!empty($salary) && isset($salary['from'])) {
            $item->price = (float) $salary['from'];
            $item->currency = $salary['currencyCode'] ?? 'RUR';
            $item->description .= "Зарплата: от {$salary['from']} {$item->currency}\n";
        } else {
            $item->price = 0;
            $item->currency = 'RUR';
            $item->description .= "Зарплата не указана\n";
        }

        // Город
        $area = $raw['area']['name'] ?? '';
        $item->description .= "Город: $area\n";

        // Опыт
        $experience = $raw['workExperience'] ?? '';
        $experienceMap = [
            'noExperience' => 'Нет опыта',
            'between1And3' => '1-3 года',
            'between3And6' => '3-6 лет',
            'moreThan6' => 'более 6 лет',
        ];
        $item->description .= "Опыт: " . ($experienceMap[$experience] ?? $experience) . "\n";

        // Формат работы (удалённо/офис)
        $workFormats = $raw['workFormats'] ?? [];
        $formatLabels = [];
        foreach ($workFormats as $wf) {
            if (isset($wf['workFormatsElement'])) {
                $formatLabels = array_merge($formatLabels, $wf['workFormatsElement']);
            }
        }
        if (in_array('REMOTE', $formatLabels)) {
            $item->description .= "✅ Удалённо\n";
        } elseif (in_array('HYBRID', $formatLabels)) {
            $item->description .= "🔄 Гибрид\n";
        } elseif (in_array('ON_SITE', $formatLabels)) {
            $item->description .= "🏢 В офисе\n";
        }

        // Ссылка на вакансию
        $item->url = $raw['links']['desktop'] ?? '';

        // Логотип
        $logos = $raw['company']['logos']['logo'] ?? [];
        $item->imageUrl = null;
        foreach ($logos as $logo) {
            if (($logo['@type'] ?? '') === 'medium') {
                $item->imageUrl = $logo['@url'] ?? null;
                break;
            }
        }
        if (!$item->imageUrl && !empty($logos)) {
            $item->imageUrl = $logos[0]['@url'] ?? null;
        }

        // Если это относительный путь, добавляем базовый URL
        if ($item->imageUrl && str_starts_with($item->imageUrl, '/')) {
            $item->imageUrl = 'https://hh.ru' . $item->imageUrl;
        }

        return $item;
    }

    public function supports(string $source): bool
    {
        return $source === 'hh';
    }
}
