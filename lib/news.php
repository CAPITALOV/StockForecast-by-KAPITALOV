<?php
/**
 * Финансовые новости из FreeNewsApi.ai и RSS (ПРАЙМ, Банки.ру).
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
 * Новости из FreeNewsApi.ai (для US-тикеров).
 */
function get_yahoo_news(string $ticker = '', int $limit = 5): array {
    if ($ticker === '') return [];
    $cacheKey = 'freenews_' . strtoupper($ticker) . '_' . $limit;
    if ($cached = news_cache_get($cacheKey)) return $cached;

    $url = 'https://freenewsapi.ai/v1/search?q=' . urlencode($ticker)
         . '&size=' . $limit . '&sort=date';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $httpCode !== 200) return [];

    $json = json_decode($raw, true);
    $articles = $json['results'] ?? [];

    $out = [];
    foreach ($articles as $a) {
        $out[] = [
            'title' => $a['title'] ?? 'Без заголовка',
            'url'   => $a['url'] ?? '#',
            'date'  => $a['published_at'] ?? null,
            'source'=> $a['sitename'] ?? 'Yahoo Finance',
        ];
    }

    news_cache_set($cacheKey, $out);
    return $out;
}

/**
 * Новости с финансовых RSS-лент (ПРАЙМ, Банки.ру) для MOEX.
 * Если по тикеру ничего нет — возвращает последние общие новости.
 */
function get_moex_news(string $ticker = '', int $limit = 5): array {
    $cacheKey = 'moex_' . strtoupper($ticker) . '_' . $limit;
    if ($cached = news_cache_get($cacheKey)) return $cached;

    $feeds = [
        'https://1prime.ru/export/rss2/index.xml' => 'ПРАЙМ',
        'https://www.banki.ru/xml/news.rss' => 'Банки.ру',
    ];

    $allItems = [];
    $tickerItems = [];

    foreach ($feeds as $url => $sourceName) {
        $rss = @simplexml_load_file($url, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR);
        if ($rss === false || !isset($rss->channel->item)) continue;

        foreach ($rss->channel->item as $item) {
            $title = (string)$item->title;
            $link  = (string)$item->link;
            $date  = (string)$item->pubDate;

            $entry = [
                'title' => $title ?: 'Без заголовка',
                'url'   => $link ?: '#',
                'date'  => $date ? date('c', strtotime($date)) : null,
                'source'=> $sourceName,
            ];

            $allItems[] = $entry;

            // Если тикер указан и упоминается в заголовке — добавляем в приоритетные
            if ($ticker !== '' && stripos($title, $ticker) !== false) {
                $tickerItems[] = $entry;
            }
        }
    }

    // Сортируем обе коллекции по дате (новые сверху)
    $sortFn = function ($a, $b) {
        $ta = $a['date'] ? strtotime($a['date']) : 0;
        $tb = $b['date'] ? strtotime($b['date']) : 0;
        return $tb <=> $ta;
    };
    usort($allItems, $sortFn);
    usort($tickerItems, $sortFn);

    // Если есть новости по тикеру — берём их, иначе — общие
    $result = !empty($tickerItems) ? $tickerItems : $allItems;
    $result = array_slice($result, 0, $limit);

    news_cache_set($cacheKey, $result);
    return $result;
}

/**
 * Объединяет новости Yahoo и MOEX.
 */
function get_financial_news(string $ticker = '', int $limit = 5): array {
    $yahoo = get_yahoo_news($ticker, $limit);
    $moex = get_moex_news($ticker, $limit);
    $all = array_merge($yahoo, $moex);
    
    usort($all, function ($a, $b) {
        $ta = $a['date'] ? strtotime($a['date']) : 0;
        $tb = $b['date'] ? strtotime($b['date']) : 0;
        return $tb <=> $ta;
    });

    return array_slice($all, 0, $limit);
}