# scripts/research/backtest_synthetic_db.py
"""
Research-only бэктест адаптивной Линии 2 на реальной схеме проекта.

Схема БД (фактическая):
  forecast_lines(id, market, ticker, line_num, value_type, content, updated_at)
  content — TEXT, строки "YYYY-MM-DD value", разделитель \n

Сравнивает 4 предиктора:
  x1       — Линия 1 (Delta Close)
  x3       — Линия 3 (RPO)
  old_avg  — текущая Линия 2 = (x1 + x3) / 2
  adaptive — z-score + адаптивные веса

Метрики согласованы с верификацией 2.0:
  sign_hit, hit50, pearson, profit_factor, mean_pnl

Запуск:
  python scripts/research/backtest_synthetic_db.py \
      --market moex --ticker LKOH --horizon 1 \
      --bars-file scripts/research/LKOH_bars.csv

  # или сразу из БД:
  python scripts/research/backtest_synthetic_db.py \
      --market moex --ticker LKOH --horizon 1 \
      --bars-table history_bars --bars-date-col date --bars-close-col close
"""
from __future__ import annotations

import argparse
import os
import re
from dataclasses import dataclass
from datetime import datetime, date
from pathlib import Path
from typing import Optional

import numpy as np
import pandas as pd
import pymysql

DB_CFG = dict(
    host=os.getenv("DB_HOST", "127.0.0.1"),
    port=int(os.getenv("DB_PORT", "3306")),
    user=os.getenv("DB_USER", "root"),
    password=os.getenv("DB_PASSWORD", ""),
    db=os.getenv("DB_NAME", "stockforecast"),
)


# ── парсер content из forecast_lines ──────────────────────────────────────
DATE_LINE = re.compile(r"^\s*(\d{4}-\d{2}-\d{2})\s+(-?\d+(?:\.\d+)?)\s*$")


def parse_content(content: str) -> pd.Series:
    rows: dict[date, float] = {}
    for line in content.splitlines():
        m = DATE_LINE.match(line)
        if not m:
            continue
        try:
            d = datetime.strptime(m.group(1), "%Y-%m-%d").date()
            v = float(m.group(2))
        except ValueError:
            continue
        rows[d] = v  # при дубле — последнее значение
    if not rows:
        return pd.Series(dtype=float)
    s = pd.Series(rows).sort_index()
    s.index = pd.to_datetime(s.index)
    return s


# ── загрузка линий и цен ─────────────────────────────────────────────────
def load_lines(market: str, ticker: str) -> pd.DataFrame:
    conn = pymysql.connect(**DB_CFG, cursorclass=pymysql.cursors.DictCursor)
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT line_num, content, value_type FROM forecast_lines "
                "WHERE market=%s AND ticker=%s AND line_num IN (1,3)",
                (market, ticker),
            )
            rows = cur.fetchall()
    finally:
        conn.close()
    if not rows:
        raise SystemExit(f"no forecast_lines for {market}/{ticker}")

    series: dict[int, pd.Series] = {}
    for r in rows:
        if r["value_type"] != "delta":
            print(f"  warn: line {r['line_num']} has value_type={r['value_type']}, skipping")
            continue
        s = parse_content(r["content"] or "")
        if s.empty:
            print(f"  warn: line {r['line_num']} content is empty / unparsable")
            continue
        series[int(r["line_num"])] = s

    if 1 not in series or 3 not in series:
        raise SystemExit(f"need both line 1 and line 3 for {market}/{ticker}, got {list(series)}")

    df = pd.concat({1: series[1], 3: series[3]}, axis=1).rename(columns={1: "x1", 3: "x3"})
    return df.dropna().sort_index()


def load_bars(table: Optional[str], date_col: str, close_col: str,
              csv_path: Optional[Path]) -> Optional[pd.Series]:
    if csv_path:
        if not csv_path.exists():
            raise SystemExit(f"bars file not found: {csv_path}")
        df = pd.read_csv(csv_path)
        if date_col not in df.columns or close_col not in df.columns:
            raise SystemExit(f"csv must have columns {date_col!r} and {close_col!r}; got {list(df.columns)}")
        df[date_col] = pd.to_datetime(df[date_col])
        return df.set_index(date_col)[close_col].astype(float).sort_index()

    if table:
        conn = pymysql.connect(**DB_CFG, cursorclass=pymysql.cursors.DictCursor)
        try:
            with conn.cursor() as cur:
                cur.execute(f"SELECT {date_col} AS d, {close_col} AS c FROM {table}")
                rows = cur.fetchall()
        finally:
            conn.close()
        if not rows:
            return None
        df = pd.DataFrame(rows)
        df["d"] = pd.to_datetime(df["d"])
        return df.set_index("d")["c"].astype(float).sort_index()

    return None


# ── математика (идентична lib/synthetic.php) ─────────────────────────────
def rolling_zscore(s: pd.Series, w: int) -> pd.Series:
    mu = s.rolling(w, min_periods=w).mean()
    sd = s.rolling(w, min_periods=w).std(ddof=0)
    return ((s - mu) / sd.where(sd != 0)).fillna(0.0)


def directional_accuracy(s: pd.Series, w: int, k: int, h: int) -> pd.Series:
    past = np.sign(s - s.shift(k))
    future = np.sign(s.shift(-h) - s)
    hits = pd.Series(
        np.where((past == 0) | (future == 0), 0.5,
                 (past == future).astype(float)),
        index=s.index,
    )
    return hits.shift(h).rolling(w, min_periods=w).mean().fillna(0.5)


def adaptive_synthetic(x1, x3, zw=60, ww=60, k=3, h=1, beta=0.9,
                       wmin=0.2, wmax=0.8) -> pd.Series:
    z1 = rolling_zscore(x1, zw)
    z3 = rolling_zscore(x3, zw)
    a1 = directional_accuracy(x1, ww, k, h)
    a3 = directional_accuracy(x3, ww, k, h)
    r1 = (a1 - 0.5).clip(lower=0) + 1e-6
    r3 = (a3 - 0.5).clip(lower=0) + 1e-6
    w1 = (r1 / (r1 + r3)).ewm(alpha=1 - beta, adjust=False).mean().clip(wmin, wmax)
    w3 = 1.0 - w1
    return (w1 * z1 + w3 * z3).rename("adaptive")


# ── метрики ──────────────────────────────────────────────────────────────
@dataclass
class Metrics:
    n: int
    sign_hit: float
    hit50: float
    pearson: float
    pf: float
    mean_pnl: float

    def __str__(self) -> str:
        return (f"n={self.n:4d}  sign_hit={self.sign_hit:.3f}  "
                f"hit50={self.hit50:.3f}  pearson={self.pearson:+.3f}  "
                f"pf={self.pf:.2f}  mean_pnl={self.mean_pnl*100:+.3f}%")


def evaluate(pred: pd.Series, actual: pd.Series) -> Optional[Metrics]:
    df = pd.concat([pred.rename("p"), actual.rename("a")], axis=1).dropna()
    sd_p = df["p"].std(ddof=0)
    sd_a = df["a"].std(ddof=0)
    if not sd_p or not sd_a or df.empty:
        return None
    p_scaled = df["p"] / sd_p * sd_a
    sp, sa = np.sign(df["p"]), np.sign(df["a"])
    sign_hit = float((sp == sa).mean())
    hit50 = float(((sp == sa) & (df["a"].abs() >= 0.5 * p_scaled.abs())).mean())
    pearson = float(df["p"].corr(df["a"]))
    pnl = sa * p_scaled
    gains = float(pnl[pnl > 0].sum())
    losses = -float(pnl[pnl < 0].sum())
    pf = gains / losses if losses > 0 else float("inf")
    return Metrics(len(df), sign_hit, hit50, pearson, pf, float(pnl.mean()))


# ── main ─────────────────────────────────────────────────────────────────
def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--market", required=True, choices=["moex", "us"])
    ap.add_argument("--ticker", required=True)
    ap.add_argument("--horizon", type=int, default=1)
    ap.add_argument("--min-rows", type=int, default=120)
    ap.add_argument("--bars-file", type=Path, default=None,
                    help="CSV с колонками date,close (приоритетнее --bars-table)")
    ap.add_argument("--bars-table", default=None,
                    help="например history_bars")
    ap.add_argument("--bars-date-col", default="date")
    ap.add_argument("--bars-close-col", default="close")
    ap.add_argument("--dump", type=Path, default=None,
                    help="сохранить объединённый датафрейм в CSV")
    args = ap.parse_args()

    df = load_lines(args.market, args.ticker)
    print(f"lines: {len(df)} rows, {df.index[0].date()} .. {df.index[-1].date()}")
    if len(df) < args.min_rows:
        raise SystemExit(f"need >= {args.min_rows} overlapping rows, got {len(df)}")

    df["old_avg"]  = (df["x1"] + df["x3"]) / 2.0
    df["adaptive"] = adaptive_synthetic(df["x1"], df["x3"])

    bars = load_bars(args.bars_table, args.bars_date_col,
                     args.bars_close_col, args.bars_file)

    if bars is None:
        # fallback: фактический ход = следующая дельта Линии 1.
        # НЕ совпадает с verify.php по масштабу, но позволяет проверить,
        # что пайплайн работает, до подключения истории цен.
        print("!! no bars source provided — using next line1 delta as 'actual' (research only)")
        actual = df["x1"].shift(-args.horizon)
        metric_label = "actual = next x1 delta"
    else:
        close = bars.reindex(df.index, method="ffill")
        actual = close.pct_change().shift(-args.horizon)
        metric_label = "actual = pct_change(close).shift(-h)"

    print(f"metrics on: {metric_label}")
    print("-" * 100)
    for col in ("x1", "x3", "old_avg", "adaptive"):
        m = evaluate(df[col], actual)
        print(f"{col:10s} {m}" if m else f"{col:10s} <no data>")
    print("-" * 100)

    if args.dump:
        out = df.copy()
        if bars is not None:
            out["close"] = bars.reindex(df.index, method="ffill")
        out["actual"] = actual
        out.to_csv(args.dump)
        print(f"dumped → {args.dump}")


if __name__ == "__main__":
    main()