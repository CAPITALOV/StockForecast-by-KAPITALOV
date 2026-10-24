-- migrations/2026xxxx_synthetic_components.sql
CREATE TABLE IF NOT EXISTS synthetic_components (
  ticker_id   INT      NOT NULL,
  date        DATE     NOT NULL,
  z1          DOUBLE   NULL,
  z3          DOUBLE   NULL,
  w1          DOUBLE   NULL,
  w3          DOUBLE   NULL,
  synthetic   DOUBLE   NULL,
  dir1        TINYINT  NULL,
  dir3        TINYINT  NULL,
  signal      VARCHAR(16) NULL,
  confidence  DOUBLE   NULL,
  computed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ticker_id, date),
  KEY idx_signal (signal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;