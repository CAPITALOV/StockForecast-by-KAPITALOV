<?php
declare(strict_types=1);
require __DIR__ . '/../../lib/db.php';   // ваш существующий PDO-коннект

$st = $pdo->prepare(
    "SELECT id, line_num, value_type, content, LENGTH(content) AS bytes
     FROM forecast_lines
     WHERE market = :m AND ticker = :t AND line_num IN (1,3)
     ORDER BY line_num"
);
$st->execute([':m' => 'moex', ':t' => 'LKOH']);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    fwrite(STDERR, "LKOH not found\n");
    exit(1);
}

$series = [];
foreach ($rows as $r) {
    $ln = (int)$r['line_num'];
    $s = [];
    foreach (preg_split('/\R/', (string)$r['content']) as $line) {
        if (!preg_match('/^\s*(\d{4}-\d{2}-\d{2})\s+(-?\d+(?:\.\d+)?)\s*$/', $line, $m)) {
            continue;
        }
        $s[$m[1]] = (float)$m[2];
    }
    ksort($s);
    $series[$ln] = $s;
    $keys = array_keys($s);
    if (!$keys) {
        printf("line %d: id=%d  <parsed 0 rows — проверьте content>\n", $ln, $r['id']);
        continue;
    }
    printf("line %d: id=%d  type=%s  bytes=%d  parsed=%d  range %s .. %s  first=%.5f  last=%.5f\n",
        $ln, $r['id'], $r['value_type'], $r['bytes'], count($s),
        $keys[0], end($keys), $s[$keys[0]], $s[end($keys)]);
}

if (count($series) === 2) {
    $common = array_values(array_intersect(array_keys($series[1]), array_keys($series[3])));
    sort($common);
    printf("\noverlap: %d дат  (%s .. %s)\n",
        count($common), $common[0] ?? '-', end($common) ?: '-');
    printf("only line1: %d   only line3: %d\n",
        count(array_diff(array_keys($series[1]), array_keys($series[3]))),
        count(array_diff(array_keys($series[3]), array_keys($series[1]))));
}