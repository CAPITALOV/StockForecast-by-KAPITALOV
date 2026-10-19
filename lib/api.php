<?php
function http_get($url, $timeout = 8, $with_ua = false) {
    $headers = "Accept: application/json\r\n";
    if ($with_ua) $headers .= "User-Agent: Mozilla/5.0\r\n";
    $ctx = stream_context_create(['http' => [
        'timeout' => $timeout,
        'ignore_errors' => true,
        'header' => $headers,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw === false ? null : $raw;
}

function moex_shares() {
    $cache = __DIR__ . '/../data/moex_shares.json';
    $stale = null;
    if (is_file($cache)) {
        $j = json_decode(file_get_contents($cache), true);
        if (is_array($j) && !empty($j)) {
            $stale = $j;
            if (time() - filemtime($cache) < MOEX_CACHE_TTL) return $j;
        }
    }
    $url = 'https://iss.moex.com/iss/engines/stock/markets/shares/boards/TQBR/securities.json'
         . '?iss.meta=off&iss.only=securities&securities.columns=SECID,SHORTNAME,SECNAME';
    $raw = http_get($url, 8);
    if (!$raw) return $stale ?: [];
    $j = json_decode($raw, true);
    if (!isset($j['securities']['data'])) return $stale ?: [];
    $cols = $j['securities']['columns'];
    $out = [];
    foreach ($j['securities']['data'] as $row) {
        $rec = array_combine($cols, $row);
        if (!empty($rec['SECID'])) {
            $out[] = [
                'ticker' => $rec['SECID'],
                'name'   => $rec['SHORTNAME'] ?: $rec['SECID'],
            ];
        }
    }
    if (empty($out)) return $stale ?: [];
    usort($out, function ($a, $b) { return strcmp($a['ticker'], $b['ticker']); });
    @file_put_contents($cache, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

function moex_is_index($ticker) {
    static $known = [
        'IMOEX','RTSI','MOEXOG','MOEXEU','MOEXFN','MOEXIT','MOEXMM','MOEXCN',
        'MOEXBMI','MOEXSC','MOEX10','MOEXTP','RGBI','RGBITR','MCFTR','MCFTRR',
    ];
    return in_array(strtoupper($ticker), $known, true);
}

function moex_history($ticker, $days = DEFAULT_DAYS) {
    $from = date('Y-m-d', time() - $days * 86400);
    $till = date('Y-m-d');
    $deadline = time() + 30;

    if (moex_is_index($ticker)) {
        $urls = [
            'https://iss.moex.com/iss/history/engines/stock/markets/index/securities/'
                . rawurlencode($ticker) . '.json',
        ];
    } else {
        $urls = [
            'https://iss.moex.com/iss/history/engines/stock/markets/shares/boards/TQBR/securities/'
                . rawurlencode($ticker) . '.json',
            'https://iss.moex.com/iss/history/engines/stock/markets/index/securities/'
                . rawurlencode($ticker) . '.json',
            'https://iss.moex.com/iss/history/engines/stock/markets/shares/boards/TQTF/securities/'
                . rawurlencode($ticker) . '.json',
        ];
    }
    foreach ($urls as $base) {
        if (time() > $deadline) break;
        $data = moex_history_fetch($base, $from, $till, $deadline);
        if (!empty($data)) return $data;
    }
    return [];
}

function moex_history_fetch($base, $from, $till, $deadline) {
    $url = $base . '?from=' . $from . '&till=' . $till
         . '&iss.meta=off&iss.only=history&history.columns=TRADEDATE,CLOSE';
    $out   = [];
    $start = 0;
    $limit = 100;
    $guard = 0;
    while ($guard++ < 100) {
        if (time() > $deadline) break;
        $raw = http_get($url . '&start=' . $start . '&limit=' . $limit, 4);
        if (!$raw) break;
        $j = json_decode($raw, true);
        if (!isset($j['history']['data'])) break;
        $rows = $j['history']['data'];
        if (empty($rows)) break;
        foreach ($rows as $row) {
            if ($row[1] === null) continue;
            $out[] = ['time' => (string)$row[0], 'value' => (float)$row[1]];
        }
        if (count($rows) < $limit) break;
        $start += $limit;
    }
    return $out;
}

function us_stocks_list() {
    return [
        ['ticker' => 'AAPL',  'name' => 'Apple Inc.'],
        ['ticker' => 'MSFT',  'name' => 'Microsoft Corp.'],
        ['ticker' => 'GOOGL', 'name' => 'Alphabet Inc.'],
        ['ticker' => 'AMZN',  'name' => 'Amazon.com Inc.'],
        ['ticker' => 'TSLA',  'name' => 'Tesla Inc.'],
        ['ticker' => 'META',  'name' => 'Meta Platforms'],
        ['ticker' => 'NVDA',  'name' => 'NVIDIA Corp.'],
        ['ticker' => 'NFLX',  'name' => 'Netflix Inc.'],
        ['ticker' => 'JPM',   'name' => 'JPMorgan Chase'],
        ['ticker' => 'V',     'name' => 'Visa Inc.'],
        ['ticker' => 'BAC',   'name' => 'Bank of America'],
        ['ticker' => 'XOM',   'name' => 'Exxon Mobil'],
        ['ticker' => 'WMT',   'name' => 'Walmart Inc.'],
        ['ticker' => 'DIS',   'name' => 'Walt Disney'],
        ['ticker' => 'AMD',   'name' => 'Advanced Micro Devices'],
        ['ticker' => 'INTC',  'name' => 'Intel Corp.'],
        ['ticker' => 'CSCO',  'name' => 'Cisco Systems'],
        ['ticker' => 'PFE',   'name' => 'Pfizer Inc.'],
        ['ticker' => 'KO',    'name' => 'Coca-Cola Co.'],
        ['ticker' => 'PEP',   'name' => 'PepsiCo Inc.'],
    ];
}

function us_history($ticker, $days = DEFAULT_DAYS) {
    $p1 = time() - $days * 86400;
    $p2 = time();
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($ticker)
         . '?period1=' . $p1 . '&period2=' . $p2 . '&interval=1d';
    $raw = http_get($url, 10, true);
    if ($raw) {
        $j = json_decode($raw, true);
        if (isset($j['chart']['result'][0])) {
            $res = $j['chart']['result'][0];
            $out = [];
            $closes = isset($res['indicators']['quote'][0]['close'])
                    ? $res['indicators']['quote'][0]['close'] : [];
            foreach ((isset($res['timestamp']) ? $res['timestamp'] : []) as $i => $ts) {
                $c = isset($closes[$i]) ? $closes[$i] : null;
                if ($c === null) continue;
                $out[] = ['time' => date('Y-m-d', (int)$ts), 'value' => (float)$c];
            }
            if (!empty($out)) return $out;
        }
    }
    $url = 'https://stooq.com/q/d/l/?s=' . strtolower($ticker) . '.us&i=d';
    $raw = http_get($url, 15, true);
    if (!$raw) return [];
    $out = [];
    $cutoff = date('Y-m-d', time() - $days * 86400);
    $lines = preg_split('/\r?\n/', trim($raw));
    foreach (array_slice($lines, 1) as $line) {
        $p = explode(',', $line);
        if (count($p) < 5) continue;
        if ($p[0] < $cutoff) continue;
        $out[] = ['time' => $p[0], 'value' => (float)$p[4]];
    }
    return $out;
}