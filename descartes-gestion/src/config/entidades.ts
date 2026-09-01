export interface CampoEntidad {
  key: string
  label: string
  type?: 'text' | 'number' | 'checkbox' | 'email' | 'select' | 'password' | 'textarea' | 'time'
  required?: boolean
  readOnly?: boolean
  onlyCreate?: boolean
  maxLength?: number
  options?: { value: string; label: string }[]
  optionsSource?: 'roles' | 'almacenes' | 'impuestos' | 'proveedores' | 'tiendas' | 'trabajadores' | 'usuarios'
}

export interface EntidadConfig {
  titulo: string
  modulo: string
  listado: boolean
  singleton?: boolean
  campos: CampoEntidad[]
}

export const entidades: Record<string, EntidadConfig> = {
  empresas: {
    titulo: 'Empresa',
    modulo: 'empresas',
    listado: false,
    singleton: true,
    campos: [
      { key: 'nif', label: 'NIF', required: true },
      { key: 'razonSocial', label: 'Razon social', required: true },
      { key: 'direccion', label: 'Direccion' },
      { key: 'poblacion', label: 'Poblacion' },
      { key: 'codigoPostal', label: 'Codigo postal' },
      { key: 'provincia', label: 'Provincia' },
      { key: 'pais', label: 'Pais' },
      { key: 'email', label: 'Email', type: 'email' },
      { key: 'divisa', label: 'Divisa' },
      {
        key: 'regimenFiscal',
        label: 'Regimen fiscal',
        type: 'select',
        options: [
          { value: 'comun', label: 'Regimen comun (Veri*Factu)' },
          { value: 'ticketbai', label: 'Pais Vasco (TicketBAI)' },
        ],
      },
      { key: 'ticketSITerritorio', label: 'Territorio TicketBAI' },
      { key: 'ticketSICertificado', label: 'Certificado TicketBAI' },
      { key: 'facturaLaCentral', label: 'Factura la central', type: 'checkbox' },
    ],
  },
  tiendas: {
    titulo: 'Tiendas',
    modulo: 'tiendas',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'nombre', label: 'Nombre', required: true },
      { key: 'nombreFiscal', label: 'Nombre fiscal' },
      { key: 'nif', label: 'NIF' },
      { key: 'direccion', label: 'Direccion' },
      { key: 'poblacion', label: 'Poblacion' },
      { key: 'codigoPostal', label: 'Codigo postal' },
      { key: 'telefono1', label: 'Telefono' },
      { key: 'email', label: 'Email', type: 'email' },
      {
        key: 'almacenCodigo',
        label: 'Almacen principal',
        type: 'select',
        optionsSource: 'almacenes',
      },
      { key: 'esCentral', label: 'Tienda central (sede)', type: 'checkbox', readOnly: true },
      { key: 'facturaLaCentral', label: 'Factura la central', type: 'checkbox' },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  almacenes: {
    titulo: 'Almacenes',
    modulo: 'almacenes',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true, type: 'number' },
      { key: 'descripcion', label: 'Descripcion', required: true },
      { key: 'externo', label: 'Externo', type: 'checkbox' },
      { key: 'reservaDirecta', label: 'Reserva', type: 'checkbox' },
      { key: 'central', label: 'Central', type: 'checkbox' },
      { key: 'traspasoAutomatico', label: 'Auto', type: 'checkbox' },
      { key: 'consolidaStockWeb', label: 'Stock Web', type: 'checkbox' },
      { key: 'centroCoste', label: 'Centro de Coste' },
      { key: 'gastos', label: 'Gastos', type: 'checkbox' },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  articulos: {
    titulo: 'Articulos',
    modulo: 'articulos',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'descripcion', label: 'Descripcion', required: true },
      { key: 'familia', label: 'Familia', required: true },
      { key: 'precioVenta', label: 'Precio venta', type: 'number' },
      { key: 'impuestoCodigo', label: 'Impuesto', type: 'select', optionsSource: 'impuestos', required: true },
      { key: 'proveedorHabitual', label: 'Proveedor habitual', type: 'select', optionsSource: 'proveedores', required: true },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  clientes: {
    titulo: 'Clientes',
    modulo: 'clientes',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'tiendaCodigo', label: 'Tienda', type: 'select', optionsSource: 'tiendas' },
      { key: 'nombre', label: 'Razon social', required: true },
      { key: 'nif', label: 'NIF' },
      { key: 'direccion', label: 'Direccion' },
      { key: 'poblacion', label: 'Poblacion' },
      { key: 'codigoPostal', label: 'Codigo postal' },
      { key: 'email', label: 'Email', type: 'email' },
      { key: 'telefono1', label: 'Telefono' },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  'tipos-calculo-fidelizacion': {
    titulo: 'Tipos calculo fidelizacion',
    modulo: 'clientes',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true, maxLength: 20 },
      { key: 'nombre', label: 'Nombre', required: true, maxLength: 50 },
      {
        key: 'motor',
        label: 'Motor',
        type: 'select',
        required: true,
        options: [
          { value: 'EUROS', label: 'Saldo en euros (%)' },
          { value: 'PUNTOS', label: 'Puntos por euro' },
          { value: 'NINGUNO', label: 'Sin acumulacion' },
        ],
      },
      { key: 'factor', label: 'Factor', type: 'number', required: true },
      {
        key: 'configuracion',
        label: 'Configuracion adicional (JSON)',
        type: 'textarea',
      },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  proveedores: {
    titulo: 'Proveedores',
    modulo: 'proveedores',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'nombre', label: 'Razon social', required: true },
      { key: 'nif', label: 'NIF' },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  trabajadores: {
    titulo: 'Trabajadores',
    modulo: 'trabajadores',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'nombre', label: 'Nombre', required: true },
      { key: 'comision', label: 'Comision', type: 'number' },
      { key: 'agente', label: 'Agente', type: 'checkbox' },
      { key: 'vendedor', label: 'Vendedor', type: 'checkbox' },
      { key: 'operario', label: 'Operario', type: 'checkbox' },
      { key: 'tecnico', label: 'Tecnico', type: 'checkbox' },
      { key: 'observaciones', label: 'Observaciones', type: 'textarea' },
      { key: 'usuarioCodigo', label: 'Usuario', type: 'select', optionsSource: 'usuarios' },
      { key: 'password', label: 'Password', type: 'password', onlyCreate: true },
      { key: 'tarjeta', label: 'Tarjeta', type: 'number' },
      { key: 'conceptoDescuadre', label: 'Con.Des', maxLength: 2 },
      { key: 'horaInicio', label: 'Hora inicio', type: 'time' },
      { key: 'horaFinal', label: 'Hora final', type: 'time' },
      { key: 'horaInicio2', label: 'Hora inicio 2', type: 'time' },
      { key: 'horaFinal2', label: 'Hora final 2', type: 'time' },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  'puestos-trabajo': {
    titulo: 'Puestos de trabajo',
    modulo: 'puestos-trabajo',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'descripcion', label: 'Descripcion', required: true },
      { key: 'tiendaCodigo', label: 'Tienda arqueo', type: 'select', optionsSource: 'tiendas' },
      { key: 'trabajadorCodigo', label: 'Vendedor', type: 'select', optionsSource: 'trabajadores' },
      { key: 'usuarioCodigo', label: 'Usuario', type: 'select', optionsSource: 'usuarios' },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  impuestos: {
    titulo: 'Impuestos',
    modulo: 'impuestos',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'descripcion', label: 'Descripcion', required: true },
      { key: 'porcentajeIVA', label: '% IVA', type: 'number', required: true },
      { key: 'porcentajeRec', label: '% Rec', type: 'number' },
      { key: 'cuentaCtb', label: 'Cuenta Ctb.', type: 'number' },
      { key: 'cuentaCtbSoportadoIntra', label: 'Intr. Soport.', type: 'number' },
      { key: 'cuentaCtbRepercutidoIntra', label: 'Intr. Reper.', type: 'number' },
      { key: 'idWeb', label: 'Id WEB', type: 'number' },
      { key: 'regimenEspecialAGYP', label: 'R.E. A.G Y P.', type: 'checkbox' },
      { key: 'ivaExento', label: 'IVA EX.', type: 'checkbox' },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  'formas-pago': {
    titulo: 'Formas de pago',
    modulo: 'formas-pago',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true, maxLength: 2 },
      { key: 'descripcion', label: 'Descripcion', required: true, maxLength: 40 },
      { key: 'cobroDeArqueo', label: 'Cuenta para arqueo', type: 'checkbox' },
      { key: 'cobroPago', label: 'Cobro/Pago (C/P/A)', maxLength: 1 },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  roles: {
    titulo: 'Roles',
    modulo: 'roles',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'nombre', label: 'Nombre', required: true },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
  usuarios: {
    titulo: 'Usuarios',
    modulo: 'usuarios',
    listado: true,
    campos: [
      { key: 'codigo', label: 'Codigo', required: true },
      { key: 'nombre', label: 'Nombre', required: true },
      { key: 'rolCodigo', label: 'Rol', type: 'select', optionsSource: 'roles', required: true },
      { key: 'password', label: 'Contrasena', type: 'password', required: true },
      { key: 'activo', label: 'Activo', type: 'checkbox' },
    ],
  },
}

export function getEntidadConfig(slug: string): EntidadConfig | undefined {
  return entidades[slug]
}
