# scripts/research/backtest_synthetic.py
"""
Research-only бэктест адаптивной Линии 2.
Сравниваем:
  - line1 = Delta Close
  - line3 = RPO
  - old_avg  = (line1 + line3) / 2          (текущая Линия 2)
  - adaptive = z-score + adaptive weights   (новая Линия 2)

Метрики согласованы с верификацией 2.0:
  - sign hit-rate
  - "Правило 50%" (с масштабированием прогноза к std(actual_return))
  - Pearson correlation
  - profit factor на горизонте
"""
from __future__ import annotations
import argparse, math
from datetime import datetime
from pathlib import Path
import numpy as np
import pandas as pd


def parse_txt(path: Path) -> pd.Series:
    rows = []
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 2:
            continue
        try:
            d = datetime.strptime(parts[0], "%m/%d/%Y").date()
            v = float(parts[1])
        except ValueError:
            continue
        rows.append((d, v))
    if not rows:
        raise ValueError(f"no data in {path}")
    s = pd.Series({pd.Timestamp(d): v for d, v in rows}).sort_index()
    return s


def rolling_zscore(s: pd.Series, window: int) -> pd.Series:
    mu = s.rolling(window, min_periods=window).mean()
    sd = s.rolling(window, min_periods=window).std(ddof=0)
    return ((s - mu) / sd.where(sd != 0)).fillna(0.0)


def directional_accuracy(s: pd.Series, w: int, k: int, h: int) -> pd.Series:
    past = np.sign(s - s.shift(k))
    future = np.sign(s.shift(-h) - s)
    hits = np.where((past == 0) | (future == 0), 0.5,
                    (past == future).astype(float))
    hits = pd.Series(hits, index=s.index)
    return hits.shift(h).rolling(w, min_periods=w).mean().fillna(0.5)


def adaptive_synthetic(x1: pd.Series, x3: pd.Series,
                       zw=60, ww=60, k=3, h=1, beta=0.9,
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


def metrics(pred: pd.Series, actual: pd.Series, thr: float = 0.0) -> dict:
    df = pd.concat([pred.rename("p"), actual.rename("a")], axis=1).dropna()
    df = df[df["p"].abs() > thr]
    if df.empty:
        return {}
    sp, sa = np.sign(df["p"]), np.sign(df["a"])
    sign_hit = float((sp == sa).mean())

    # масштабируем прогноз к std(actual), чтобы 50% rule и PF были в одних единицах
    sd = float(df["a"].std(ddof=0)) or 1.0
    sp_scaled = df["p"] / float(df["p"].std(ddof=0)) * sd
    hit50 = float(((np.sign(sp_scaled) == sa) &
                   (df["a"].abs() >= 0.5 * sp_scaled.abs())).mean())
    pearson = float(df["p"].corr(df["a"]))
    pnl = np.sign(sp_scaled) * df["a"]
    gains  = float(pnl[pnl > 0].sum())
    losses = -float(pnl[pnl < 0].sum())
    pf = gains / losses if losses > 0 else float("inf")
    return dict(n=len(df), sign_hit=sign_hit, hit50=hit50,
                pearson=pearson, profit_factor=pf,
                mean_pnl=float(pnl.mean()))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--line1", required=True, type=Path)
    ap.add_argument("--line3", required=True, type=Path)
    args = ap.parse_args()

    x1 = parse_txt(args.line1)
    x3 = parse_txt(args.line3)
    df = pd.concat([x1.rename("x1"), x3.rename("x3")], axis=1).dropna()
    if len(df) < 120:
        raise SystemExit("need >=120 overlapping rows")

    df["old_avg"]  = (df["x1"] + df["x3"]) / 2.0
    df["adaptive"] = adaptive_synthetic(df["x1"], df["x3"])

    # фактический ход = дельта следующего периода (в тех же единицах, что и вход)
    actual = df["x1"].shift(-1)  # горизонт h=1; при желании заменить на return из истории
    # Если есть actual_return (доходность цены) — подать её вместо actual,
    # см. lib/verify.php: там сравнение идёт с actual_return.

    print(f"rows: {len(df)}   period: {df.index[0].date()} .. {df.index[-1].date()}")
    for name in ("x1", "x3", "old_avg", "adaptive"):
        m = metrics(df[name], actual)
        print(f"{name:10s} " + "  ".join(f"{k}={v:.4f}" for k, v in m.items()))


if __name__ == "__main__":
    main()