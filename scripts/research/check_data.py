#!/usr/bin/env python3
"""Проверка: парсим LKOH line 1 и line 3 из forecast_lines, печатаем сводку."""
import os, re
from datetime import datetime, date
import pymysql

DATE_LINE = re.compile(r"^\s*(\d{4}-\d{2}-\d{2})\s+(-?\d+(?:\.\d+)?)\s*$")

def parse_content(content: str) -> dict[date, float]:
    out: dict[date, float] = {}
    for line in (content or "").splitlines():
        m = DATE_LINE.match(line)
        if not m:
            continue
        try:
            d = datetime.strptime(m.group(1), "%Y-%m-%d").date()
        except ValueError:
            continue
        out[d] = float(m.group(2))
    return out

def main() -> None:
    cfg = dict(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "3306")),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASSWORD", ""),
        db=os.getenv("DB_NAME", "stockforecast"),
        cursorclass=pymysql.cursors.DictCursor,
    )
    conn = pymysql.connect(**cfg)
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, line_num, value_type, LENGTH(content) AS bytes, "
                "       CHAR_LENGTH(content) AS chars, content "
                "FROM forecast_lines "
                "WHERE market=%s AND ticker=%s AND line_num IN (1,3) "
                "ORDER BY line_num",
                ("moex", "LKOH"),
            )
            rows = cur.fetchall()
    finally:
        conn.close()

    if not rows:
        raise SystemExit("LKOH not found")

    series: dict[int, dict[date, float]] = {}
    for r in rows:
        s = parse_content(r["content"])
        series[int(r["line_num"])] = s
        if s:
            keys = sorted(s)
            print(f"line {r['line_num']}: id={r['id']}  type={r['value_type']}  "
                  f"bytes={r['bytes']}  chars={r['chars']}  parsed={len(s)}  "
                  f"range {keys[0]} .. {keys[-1]}  "
                  f"first={s[keys[0]]:.5f}  last={s[keys[-1]]:.5f}")
        else:
            print(f"line {r['line_num']}: id={r['id']}  <parsed 0 rows> — проверьте формат content")

    if len(series) == 2:
        common = sorted(set(series[1]) & set(series[3]))
        print(f"\noverlap: {len(common)} дат  "
              f"({common[0]} .. {common[-1]})" if common else "\noverlap: 0 — линии не пересекаются по датам")
        extra1 = set(series[1]) - set(series[3])
        extra3 = set(series[3]) - set(series[1])
        print(f"only line1: {len(extra1)}   only line3: {len(extra3)}")

if __name__ == "__main__":
    main()