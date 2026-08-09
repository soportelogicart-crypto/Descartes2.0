"""Diff completo articulos legacy vs 2.0 (todos los campos con valor distinto)."""
from __future__ import annotations

import subprocess
import sys

LEG = "0010032962"
D20 = "0020032041"


def main() -> int:
    sql = (
        "SET NOCOUNT ON;\n"
        f"SELECT * FROM Articulos WHERE RTRIM(Codigo) IN ('{LEG}','{D20}') ORDER BY RTRIM(Codigo);\n"
    )
    raw = subprocess.check_output(
        ["sqlcmd", "-S", "localhost", "-d", "larasa", "-E", "-W", "-s", "\t", "-Q", sql],
        stderr=subprocess.STDOUT,
    )
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
    by = {}
    for parts in rows:
        d = {c: (parts[i] if i < len(parts) else "") for i, c in enumerate(header)}
        by[d["Codigo"].strip()] = d
    a, b = by[LEG], by[D20]
    skip = {"upsize_ts", "Codigo", "Descripcion", "Familia", "Subfamilia", "UltProveedor", "Impuesto", "NumEtiElectronica", "Agrupacion"}
    print("=== Todos los diffs estructurales ===")
    for col in header:
        if col in skip:
            continue
        v3, v4 = a[col], b[col]
        if v3 == v4:
            continue
        if v3.strip() == v4.strip() and v3.strip().upper() != "NULL":
            continue
        # blank vs blank
        if (v3.strip() == "" or v3.strip().upper() == "NULL") and (
            v4.strip() == "" or v4.strip().upper() == "NULL"
        ):
            continue
        print(f"{col}: L={v3!r} D={v4!r}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
