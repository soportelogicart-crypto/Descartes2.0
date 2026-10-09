import { api } from '@/api/client'

export type InventarioLinea = {
  articulo: string
  descripcion: string
  congelado: number
  contado: number
  diferencia: number
}

export type InventarioEstado = {
  empresa: string
  almacen: number
  almacenes: { codigo: number; descripcion: string }[]
  lineas: InventarioLinea[]
  resumen: { filas: number; sinContar: number; conDiferencia: number }
  mensaje?: string
  articulo?: string
  contado?: number
  insertadas?: number
}

export type InventarioActualizacion = {
  empresa: string
  almacen: number
  albaran: number | null
  lineas: number
  importe: number
  mensaje: string
}

export async function obtenerRecuento(empresa: string, almacen: number): Promise<InventarioEstado> {
  const { data } = await api.get<InventarioEstado>('/api/inventario/recuento', {
    params: { empresa, almacen: almacen > 0 ? almacen : undefined },
  })
  return data
}

export type FiltroCongelacion = {
  familiaDesde: string
  familiaHasta: string
  subfamiliaDesde: string
  subfamiliaHasta: string
  agrupacionDesde: string
  agrupacionHasta: string
  articuloDesde: string
  articuloHasta: string
  proveedorDesde: string
  proveedorHasta: string
  excluirBajas: boolean
  reservas: 'no' | 'inventariadas'
}

export async function congelarRecuento(
  empresa: string,
  almacen: number,
  reemplazar: boolean,
  filtros: FiltroCongelacion
): Promise<InventarioEstado> {
  const { data } = await api.post<InventarioEstado>('/api/inventario/recuento/congelar', {
    empresa,
    almacen,
    reemplazar,
    ...filtros,
  })
  return data
}

export async function anotarRecuento(
  empresa: string,
  almacen: number,
  articulo: string,
  cantidad: string
): Promise<InventarioEstado> {
  const { data } = await api.post<InventarioEstado>('/api/inventario/recuento/linea', {
    empresa,
    almacen,
    articulo,
    cantidad,
  })
  return data
}

export async function descartarRecuento(empresa: string, almacen: number): Promise<InventarioEstado> {
  const { data } = await api.post<InventarioEstado>('/api/inventario/recuento/descartar', {
    empresa,
    almacen,
  })
  return data
}

export async function actualizarRecuento(
  empresa: string,
  almacen: number
): Promise<InventarioActualizacion> {
  const { data } = await api.post<InventarioActualizacion>('/api/inventario/recuento/actualizar', {
    empresa,
    almacen,
  })
  return data
}
