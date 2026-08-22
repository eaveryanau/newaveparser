<?php

namespace App\Client;

use GuzzleHttp\ClientInterface;

class HhClient implements ApiClientInterface
{
    public function __construct(
        private ClientInterface $http,
        private string $query = 'Frontend',
        private string $areas = '16',
    ) {}

    public function fetchPage(int $page): array
    {
        // В HH.ru пагинация начинается с 0
        $pageParam = $page - 1;

        $response = $this->http->request('GET', '/shards/vacancy/search?area=1002&area=16', [
            'query' => [
                'ored_clusters' => 'true',
                'text' => $this->query,
                'search_field' => ['name', 'company_name', 'description'],
                'enable_snippets' => 'false',
                'page' => $pageParam,
                'area' => explode(',', $this->areas),
                // Можно добавить другие параметры, если нужно
            ],
            'headers' => [
                'accept' => 'application/json',
                'accept-language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'cache-control' => 'no-cache',
                'pragma' => 'no-cache',
                'referer' => 'https://hh.ru/search/vacancy',
                'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
                'x-requested-with' => 'XMLHttpRequest',
                // Остальные заголовки можно добавить, но обычно хватает этих
            ]
        ]);

        $data = json_decode($response->getBody(), true);

        // Извлекаем вакансии и мета-информацию
        $vacancies = $data['vacancySearchResult']['vacancies'] ?? [];
        $total = $data['vacancySearchResult']['totalResults'] ?? 0;
        $pageCount = (int) ceil($total / 50); // 50 на страницу, но можно взять из paging

        return [
            'count' => $total,
            'pageCount' => $pageCount,
            'adverts' => $vacancies, // массив сырых вакансий
        ];
    }

    public function getSource(): string
    {
        return 'hh';
    }
}
