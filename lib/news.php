<?php
/**
 * Финансовые новости из FreeNewsApi.ai (Yahoo Finance) и RSS (Интерфакс, Профинанс).
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
 * Новости из FreeNewsApi.ai (для US-тикеров и общих финансовых новостей).
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
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
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
 * Новости с финансовых RSS-лент (Интерфакс, Профинанс) для MOEX.
 */
function get_moex_news(string $ticker = '', int $limit = 5): array {
    $cacheKey = 'moex_' . strtoupper($ticker) . '_' . $limit;
    if ($cached = news_cache_get($cacheKey)) return $cached;

    $feeds = [
        'https://www.interfax.ru/rss.asp' => 'Интерфакс',
        'https://www.profinance.ru/fond.xml' => 'Профинанс',
    ];

    $out = [];
    foreach ($feeds as $url => $sourceName) {
        $rss = @simplexml_load_file($url, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR);
        if ($rss === false || !isset($rss->channel->item)) continue;

        foreach ($rss->channel->item as $item) {
            $title = (string)$item->title;
            $link  = (string)$item->link;
            $date  = (string)$item->pubDate;

            if ($ticker !== '' && stripos($title, $ticker) === false) continue;

            $out[] = [
                'title' => $title ?: 'Без заголовка',
                'url'   => $link ?: '#',
                'date'  => $date ? date('c', strtotime($date)) : null,
                'source'=> $sourceName,
            ];
        }
    }

    usort($out, function ($a, $b) {
        $ta = $a['date'] ? strtotime($a['date']) : 0;
        $tb = $b['date'] ? strtotime($b['date']) : 0;
        return $tb <=> $ta;
    });

    $out = array_slice($out, 0, $limit);
    news_cache_set($cacheKey, $out);
    return $out;
}

/**
 * Объединяет новости Yahoo и MOEX (если тикер российский).
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