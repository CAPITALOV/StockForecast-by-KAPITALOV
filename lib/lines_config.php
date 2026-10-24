<?php
/**
 * lib/lines_config.php — единая точка правды о трёх линиях.
 * Используется в lib/forecast.php, pages/stock.php, pages/admin.php.
 */

function lines_config(): array {
    return [
        1 => [
            'line_num'   => 1,
            'line_type'  => 'delta',
            'subtype'    => null,
            'name'       => 'Delta Close',
            'label'      => 'Delta Close — направление тренда Close',
            'color'      => '#e11d48',   // red
            'value_type' => 'delta',
            'public'     => true,
            'computed'   => false,
        ],
        2 => [
            'line_num'   => 2,
            'line_type'  => 'synthetic',
            'subtype'    => null,
            'name'       => 'Synthetic Average',
            'label'      => 'Synthetic Average (admin-only)',
            'color'      => '#f59e0b',   // orange
            'value_type' => 'delta',
            'public'     => false,
            'computed'   => true,
        ],
        3 => [
            'line_num'   => 3,
            'line_type'  => 'delta',
            'subtype'    => 'rpo',
            'name'       => 'RPO',
            'label'      => 'RPO — Weighted average price',
            'color'      => '#2563eb',   // blue
            'value_type' => 'delta',
            'public'     => true,
            'computed'   => false,
        ],
    ];
}

function public_lines(): array {
    return array_filter(lines_config(), fn($l) => $l['public']);
}