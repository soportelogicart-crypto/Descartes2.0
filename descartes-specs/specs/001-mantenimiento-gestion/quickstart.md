# Quickstart: 001-mantenimiento-gestion

**Feature**: Modulo de Mantenimiento (Gestion)  
**Branch**: `001-mantenimiento-gestion`

## Prerequisites

- Windows con XAMPP (PHP 8.1+ recomendado, Apache)
- SQL Server Developer/Express local con BD `larasa` restaurada desde `db/script.sql`
- Node.js 20+ y npm
- Extension PHP `sqlsrv` o `pdo_sqlsrv` habilitada en `php.ini`
- Composer 2.x

## Repository layout (target)

```text
c:\descartes-2.0\
├── db\
│   ├── script.sql
│   └── migrations\001-mantenimiento-extensiones.sql
├── descartes-api\          # PHP Slim
├── descartes-gestion\      # Vue 3 + Vite
└── descartes-specs\        # Spec Kit (este repo de specs)
```

## 1. Base de datos

```powershell
sqlcmd -S localhost\SQLEXPRESS -d larasa -i c:\descartes-2.0\db\migrations\001-mantenimiento-extensiones.sql
```

Verificar: tablas `Roles`, `RolPermisos` y columnas `Baja`/`Rol` anadidas. **No** debe existir tabla `Tiendas`.

Confirmar fila central: `SELECT * FROM Empresas WHERE Central = 1` (datos fiscales del cliente).

## 2. API (descartes-api)

```powershell
cd c:\descartes-2.0\descartes-api
composer install
copy .env.example .env
# Editar .env: DB_SERVER, DB_NAME=larasa, DB_USER, DB_PASSWORD
```

Probar:

```powershell
curl -X POST http://localhost/descartes-api/public/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"usuario":"ADM","password":"admin123"}' `
  -c cookies.txt

# Datos fiscales del cliente (singleton, fila Central=1)
curl http://localhost/descartes-api/public/api/mantenimiento/empresas -b cookies.txt

# Tiendas (tabla legacy Empresas)
curl http://localhost/descartes-api/public/api/mantenimiento/tiendas -b cookies.txt
```

## 3. Frontend (descartes-gestion)

```powershell
cd c:\descartes-2.0\descartes-gestion
npm install
npm run dev
```

Abrir `http://localhost:5173` → login → Mantenimiento.

## 4. Flujo de validacion manual (SC-001)

1. Login como administrador.
2. Mantenimiento → **Empresa** (singleton) → revisar/editar datos fiscales de la fila central.
3. Mantenimiento → **Tiendas** → Alta de sucursal (nueva fila en `[Empresas]`).
4. Mantenimiento → Roles → Crear rol "Solo lectura articulos".
5. Mantenimiento → Usuarios → Alta usuario con ese rol.
6. Logout → Login con nuevo usuario → verificar que no puede editar articulos.

## 5. Validacion permisos (SC-002)

Con 3 roles de prueba: intentos de edicion no autorizada → HTTP 403; botones ocultos en UI.

## 6. Validacion automatica (Phase 8)

```powershell
powershell -File c:\descartes-2.0\descartes-api\scripts\validate-phase8.ps1
```

Comprueba login, empresa singleton, paginacion de listados y formato de errores JSON en espanol.

## Referencias

- [spec.md](./spec.md)
- [data-model.md](./data-model.md) — mapeo Tiendas = `[Empresas]`
- [contracts/mantenimiento-api.openapi.yaml](./contracts/mantenimiento-api.openapi.yaml)
- [research.md](./research.md)
