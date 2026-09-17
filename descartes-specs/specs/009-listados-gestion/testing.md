# Pruebas manuales — 009 Listados (T006)

Ejecutar en **Electron dev** (`npm run dev` en `descartes-gestion`) contra API local (`descartes-api-dev`).

Asegurar migración **011-permisos-listados.sql** aplicada en la BD del cliente (rol ADMIN con módulo `listados`).

## Preparación de roles

En **Mantenimiento → Roles → permisos**:

| Rol de prueba | `listados` ver | `ventas-abc` ver | Uso |
|---------------|----------------|------------------|-----|
| A — Sin listados | No | (da igual) | Caso denegado |
| B — Solo listados | Sí | No | Hub sin tarjeta ABC |
| C — Listados + ABC | Sí | Sí | Flujo completo ≤3 clics |

Asignar cada rol a un usuario de prueba (o cambiar el rol del usuario con el que entras).

---

## 1. Sin permiso `listados` (US1 escenario 3)

1. Iniciar sesión con **rol A**.
2. **Menú:** el botón **Listados** debe aparecer **deshabilitado** (tooltip «sin permiso»).
3. Navegar manualmente a `/listados` (barra de dirección o marcador).
4. **Resultado esperado:** redirección a inicio (`/`) con aviso rojo: no tiene permiso para «Listados».
5. `GET /api/listados/stock` con esa sesión debe responder **403** (si se prueba desde red).

---

## 2. Con `listados`, sin `ventas-abc` (SC-005)

1. Iniciar sesión con **rol B**.
2. Abrir **Listados → Catálogo de informes** (hub).
3. Buscar «abc» en el buscador.
4. **Resultado esperado:** no aparece la tarjeta **ABC de ventas**.
5. Ir a `/ventas/abc` en la URL.
6. **Resultado esperado:** redirección a inicio con aviso de permiso (`ventas-abc`).

---

## 3. Con listados + ABC — ≤3 clics (US1 escenario 1)

1. Iniciar sesión con **rol C** (o ADMIN).
2. Clic en sección **Listados** en el menú lateral.
3. Clic en **Catálogo de informes**.
4. Clic en tarjeta **ABC de ventas** (o buscar «abc» y un solo clic en la tarjeta = también válido).
5. **Resultado esperado:** pantalla **Listado ABC Ventas** (`/ventas/abc`).

Alternativa desde hub sin abrir submenú: si el menú compacto permite ir directo a `/listados` en 1 clic desde icono, contar clics reales en tu resolución (objetivo ≤3 hasta el informe).

---

## 4. Informe propio del módulo `listados`

1. Rol con **`listados` ver**.
2. Hub → **Stock** → **Generar**.
3. **Resultado esperado:** grid con filas o «Sin datos»; Excel e imprimir deshabilitados hasta generar con éxito.

---

## 5. Tarjetas por permiso de acceso

1. Rol con `listados` pero **sin** `facturacion-diario`.
2. En el hub **no** debe aparecer **Diario de facturación**.
3. Rol con `listados` + `facturacion-diario`: tarjeta visible y abre `/facturacion/diario`.

---

## Checklist rápido

- [ ] Rol A: no entra a `/listados`, aviso en inicio
- [ ] Rol B: hub OK, sin ABC, `/ventas/abc` bloqueado
- [ ] Rol C: hub → ABC en ≤3 clics
- [ ] Stock genera y exporta CSV con `;`

Marcar **T006** en `tasks.md` cuando estos casos pasen en tu entorno.
