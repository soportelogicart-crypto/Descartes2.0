"""Compara Empresas codigo 3 (legacy) vs 5 (Descartes 2.0)."""
from __future__ import annotations

import subprocess
import sys


def main() -> int:
    sql = (
        "SET NOCOUNT ON;\n"
        "SELECT * FROM Empresas WHERE RTRIM(Codigo) IN ('3','5') ORDER BY RTRIM(Codigo);\n"
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

    by_code: dict[str, dict[str, str]] = {}
    for parts in rows:
        d = {col: (parts[i] if i < len(parts) else "") for i, col in enumerate(header)}
        by_code[d.get("Codigo", "").strip()] = d

    if "3" not in by_code or "5" not in by_code:
        print("MISSING", sorted(by_code.keys()))
        return 1

    a, b = by_code["3"], by_code["5"]
    print("=== 5 es NULL y 3 no ===")
    null_mismatches: list[tuple[str, str, str]] = []
    for col in header:
        if col == "upsize_ts":
            continue
        v3 = a.get(col, "")
        v5 = b.get(col, "")
        is_null3 = v3.strip().upper() == "NULL"
        is_null5 = v5.strip().upper() == "NULL"
        if is_null5 and not is_null3:
            null_mismatches.append((col, v3, v5))
            kind = (
                "0"
                if v3.strip() in ("0", "0.0", "0.00", "0.000", ".0")
                else ("empty" if v3.strip() == "" else "value")
            )
            print(f"{col}: legacy={v3!r}  d20={v5!r}  ({kind})")

    print(f"\nTotal null-mismatches: {len(null_mismatches)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
