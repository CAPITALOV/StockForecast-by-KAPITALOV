-- ============================================================
-- StockForecast: добавление Line 2 (synthetic) и нормализация
-- Line 1 / Line 3 (delta + rpo). MySQL 5.7+ / MariaDB 10.3+.
-- ============================================================

-- 1. Гарантируем наличие таблицы history (если её ещё нет)
CREATE TABLE IF NOT EXISTS `history` (
  `exchange`   VARCHAR(20) NOT NULL,
  `ticker`     VARCHAR(20) NOT NULL,
  `trade_date` DATE NOT NULL,
  `open`       DOUBLE NULL,
  `high`       DOUBLE NULL,
  `low`        DOUBLE NULL,
  `close`      DOUBLE NOT NULL,
  `volume`     BIGINT NULL,
  PRIMARY KEY (`exchange`, `ticker`, `trade_date`),
  KEY `idx_history_lookup` (`exchange`, `ticker`, `trade_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Добавляем новые колонки в forecasts
ALTER TABLE `forecasts`
  ADD COLUMN `line_type` VARCHAR(20) NOT NULL DEFAULT 'delta' AFTER `line_num`,
  ADD COLUMN `subtype`   VARCHAR(20) NULL DEFAULT NULL AFTER `line_type`,
  ADD COLUMN `meta`      JSON NULL DEFAULT NULL AFTER `value`,
  ADD KEY `idx_line_lookup` (`exchange`, `ticker`, `line_num`, `forecast_date`);

-- 3. Line 1 — delta, красная, публичная
UPDATE `forecasts`
SET `line_type` = 'delta',
    `subtype`   = NULL,
    `meta`      = JSON_SET(
        COALESCE(`meta`, '{}'),
        '$.label',  'Delta Close — направление тренда Close',
        '$.color',  'red',
        '$.public', CAST(TRUE AS JSON)
    )
WHERE `line_num` = 1;

-- 4. Line 3 — исторически был type='delta' без subtype → переклассифицируем в rpo, синяя
UPDATE `forecasts`
SET `line_type` = 'delta',
    `subtype`   = 'rpo',
    `meta`      = JSON_SET(
        COALESCE(`meta`, '{}'),
        '$.label',  'RPO — Weighted average price',
        '$.color',  'blue',
        '$.public', CAST(TRUE AS JSON)
    )
WHERE `line_num` = 3;

-- 5. Line 2 — synthetic, оранжевая, admin-only
UPDATE `forecasts`
SET `line_type` = 'synthetic',
    `subtype`   = NULL,
    `meta`      = JSON_SET(
        COALESCE(`meta`, '{}'),
        '$.label',  'Synthetic Average (admin-only)',
        '$.color',  'orange',
        '$.public', CAST(FALSE AS JSON)
    )
WHERE `line_num` = 2;

-- 6. Таблица signals
CREATE TABLE IF NOT EXISTS `signals` (
  `exchange`   VARCHAR(20) NOT NULL,
  `ticker`     VARCHAR(20) NOT NULL,
  `trade_date` DATE NOT NULL,
  `z1`         DOUBLE NULL,
  `z3`         DOUBLE NULL,
  `w1`         DOUBLE NULL,
  `w3`         DOUBLE NULL,
  `synthetic`  DOUBLE NULL,
  `dir1`       TINYINT NULL,
  `dir3`       TINYINT NULL,
  `signal`     ENUM('buy','sell','neutral') NOT NULL DEFAULT 'neutral',
  `confidence` DOUBLE NULL,
  PRIMARY KEY (`exchange`, `ticker`, `trade_date`),
  KEY `idx_signal` (`exchange`, `ticker`, `signal`, `trade_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;