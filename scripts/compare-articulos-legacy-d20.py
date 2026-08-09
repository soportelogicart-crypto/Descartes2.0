"""Compara Articulos 0010032962 (legacy) vs 0020032041 (Descartes 2.0)."""
from __future__ import annotations

import subprocess
import sys


def is_null(v: str) -> bool:
    return v.strip().upper() == "NULL"


def is_blank(v: str) -> bool:
    return is_null(v) or v.strip() == ""


def is_zero(v: str) -> bool:
    return v.strip() in ("0", "0.0", "0.00", "0.000", ".0", "0.0000")


def main() -> int:
    sql = (
        "SET NOCOUNT ON;\n"
        "SELECT * FROM Articulos WHERE RTRIM(Codigo) IN ('0010032962','0020032041') "
        "ORDER BY RTRIM(Codigo);\n"
    )
    cmd = ["sqlcmd", "-S", "localhost", "-d", "larasa", "-E", "-W", "-s", "\t", "-Q", sql]
    raw = subprocess.check_output(cmd, stderr=subprocess.STDOUT)
    text = raw.decode("cp1252", errors="replace")
    lines = [ln for ln in text.splitlines() if ln.strip()]

    header = None
    rows: list[list[str]] = []
    for ln in lines:
        if header is None:
            if "Codigo" in ln and "\t" in ln:
                header = ln.split("\t")
            continue
        if set(ln.replace("\t", "")) <= {"-"}:
            continue
        parts = ln.split("\t")
        if len(parts) >= 2:
            rows.append(parts)

    if not header or len(rows) < 2:
        print("PARSE_FAIL")
        print("\n".join(lines[:40]))
        return 1

    by: dict[str, dict[str, str]] = {}
    for parts in rows:
        d = {col: (parts[i] if i < len(parts) else "") for i, col in enumerate(header)}
        by[d.get("Codigo", "").strip()] = d

    leg = by.get("0010032962")
    d20 = by.get("0020032041")
    if not leg or not d20:
        print("MISSING", sorted(by.keys()))
        return 1

    print("=== Legacy valor / 2.0 NULL ===")
    n1 = 0
    for col in header:
        if col == "upsize_ts":
            continue
        v3, v4 = leg.get(col, ""), d20.get(col, "")
        if is_null(v4) and not is_null(v3):
            n1 += 1
            print(f"{col}: legacy={v3!r}  d20={v4!r}")
    print(f"total: {n1}")

    print("\n=== 2.0 valor / Legacy NULL ===")
    n2 = 0
    for col in header:
        if col == "upsize_ts":
            continue
        v3, v4 = leg.get(col, ""), d20.get(col, "")
        if is_null(v3) and not is_null(v4):
            n2 += 1
            print(f"{col}: legacy={v3!r}  d20={v4!r}")
    print(f"total: {n2}")

    print("\n=== Valores distintos (no blank-vs-blank, no codigo) ===")
    n3 = 0
    for col in header:
        if col in ("upsize_ts", "Codigo"):
            continue
        v3, v4 = leg.get(col, ""), d20.get(col, "")
        if is_blank(v3) and is_blank(v4):
            continue
        if is_zero(v3) and is_zero(v4):
            continue
        if v3.strip() == v4.strip() and not is_null(v3) and not is_null(v4):
            continue
        if v3 != v4:
            n3 += 1
            print(f"{col}: legacy={v3!r}  d20={v4!r}")
    print(f"total: {n3}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
