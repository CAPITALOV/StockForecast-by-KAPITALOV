<?php
/**
 * Финансовые новости из Yahoo Finance (через FreeNewsApi.ai) и Eulerpool.
 * Кэширование в data/news_cache.json на 15 минут.
 */

function news_cache_get(string $key, int $ttl = 900): ?array {
    $file = __DIR__ . '/../data/news_cache.json';
    if (!is_file($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || !isset($data[$key])) return null;
    if (time() - ($data[$key]['ts'] ?? 0) > $ttl) return null;
    return $data[$key]['items'] ?? null;
}

function news_cache_set(string $key, array $items): void {
    $file = __DIR__ . '/../data/news_cache.json';
    $data = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $data[$key] = ['ts' => time(), 'items' => $items];
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
}

/**
 * Новости Yahoo Finance через FreeNewsApi.ai.
 * @param string $ticker Тикер для поиска (например, SBER, AAPL).
 * @param int $limit Количество новостей.
 */
function get_yahoo_news(string $ticker = '', int $limit = 5): array {
    $cacheKey = 'yahoo_' . strtoupper($ticker) . '_' . $limit;
    if ($cached = news_cache_get($cacheKey)) return $cached;

    $base = 'https://freenewsapi.ai/v1/search?host=www.wsj.com&size=' . $limit;
    if ($ticker !== '') {
        $base .= '&q=' . urlencode($ticker);
    }
    $base .= '&fields=title,url,published_at';

    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'StockForecast/1.0',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    if (!$raw) return [];
    $json = json_decode($raw, true);
    $articles = $json['articles'] ?? $json['data'] ?? [];

    $out = [];
    foreach ($articles as $a) {
        $out[] = [
            'title' => $a['title'] ?? 'Без заголовка',
            'url'   => $a['url'] ?? '#',
            'date'  => $a['published_at'] ?? null,
            'source'=> 'Yahoo Finance',
        ];
    }

    news_cache_set($cacheKey, $out);
    return $out;
}

/**
 * Новости Eulerpool (требуется EULERPOOL_KEY в config.php).
 * Если ключа нет — возвращает пустой массив.
 */
function get_eulerpool_news(string $ticker = '', int $limit = 5): array {
    if (!defined('EULERPOOL_KEY') || EULERPOOL_KEY === '') return [];

    $cacheKey = 'euler_' . strtoupper($ticker) . '_' . $limit;
    if ($cached = news_cache_get($cacheKey)) return $cached;

    $url = 'https://api.eulerpool.com/api/1/research/news/' . urlencode($ticker ?: 'market');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . EULERPOOL_KEY],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    if (!$raw) return [];
    $json = json_decode($raw, true);
    $articles = $json['data'] ?? $json['news'] ?? [];

    $out = [];
    foreach (array_slice($articles, 0, $limit) as $a) {
        $out[] = [
            'title' => $a['title'] ?? 'Без заголовка',
            'url'   => $a['url'] ?? '#',
            'date'  => $a['published_at'] ?? $a['date'] ?? null,
            'source'=> 'Eulerpool',
        ];
    }

    news_cache_set($cacheKey, $out);
    return $out;
}

/**
 * Объединяет новости из двух источников, сортирует по дате (новые сверху).
 */
function get_financial_news(string $ticker = '', int $limit = 5): array {
    $yahoo = get_yahoo_news($ticker, $limit);
    $euler = get_eulerpool_news($ticker, $limit);

    $all = array_merge($yahoo, $euler);
    usort($all, function ($a, $b) {
        $ta = $a['date'] ? strtotime($a['date']) : 0;
        $tb = $b['date'] ? strtotime($b['date']) : 0;
        return $tb <=> $ta;
    });

    return array_slice($all, 0, $limit);
}