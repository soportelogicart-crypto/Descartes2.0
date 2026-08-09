"""Compara Empresas codigo 3 (legacy) vs 4 (Descartes 2.0)."""
from __future__ import annotations

import subprocess
import sys


def is_null(v: str) -> bool:
    return v.strip().upper() == "NULL"


def is_blank(v: str) -> bool:
    return is_null(v) or v.strip() == ""


def is_zero(v: str) -> bool:
    s = v.strip()
    return s in ("0", "0.0", "0.00", "0.000", ".0", "0.0000")


def main() -> int:
    sql = (
        "SET NOCOUNT ON;\n"
        "SELECT * FROM Empresas WHERE RTRIM(Codigo) IN ('3','4') ORDER BY RTRIM(Codigo);\n"
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

    if "3" not in by_code or "4" not in by_code:
        print("MISSING", sorted(by_code.keys()))
        return 1

    a, b = by_code["3"], by_code["4"]
    print("Nombres:", a.get("Nombre"), "|", b.get("Nombre"))
    print()

    print("=== NULL en 4 y no en 3 ===")
    n_null = 0
    for col in header:
        if col == "upsize_ts":
            continue
        v3, v4 = a.get(col, ""), b.get(col, "")
        if is_null(v4) and not is_null(v3):
            n_null += 1
            print(f"{col}: legacy={v3!r}  d20={v4!r}")
    print(f"total: {n_null}")

    print()
    print("=== NULL en 3 y no en 4 ===")
    n_null3 = 0
    for col in header:
        if col == "upsize_ts":
            continue
        v3, v4 = a.get(col, ""), b.get(col, "")
        if is_null(v3) and not is_null(v4):
            n_null3 += 1
            print(f"{col}: legacy={v3!r}  d20={v4!r}")
    print(f"total: {n_null3}")

    print()
    print("=== Valores distintos (ignorando espacio vs '' y codigo/nombre) ===")
    skip = {"upsize_ts", "Codigo", "Nombre", "NombreFiscal", "Direccion", "NIF", "Telefono1", "EMail"}
    n_diff = 0
    for col in header:
        if col in skip:
            continue
        v3, v4 = a.get(col, ""), b.get(col, "")
        if is_blank(v3) and is_blank(v4):
            continue
        if is_zero(v3) and is_zero(v4):
            continue
        if v3 != v4:
            # nchar pad: treat equal if stripped equal
            if v3.strip() == v4.strip() and not is_null(v3) and not is_null(v4):
                continue
            n_diff += 1
            print(f"{col}: legacy={v3!r}  d20={v4!r}")
    print(f"total: {n_diff}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
