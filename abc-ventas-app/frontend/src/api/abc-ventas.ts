import type { AbcVentasFiltros, AbcVentasResponse } from '@/types/abc-ventas'
import { getAbcBridge } from '@/api/httpBridge'

export async function obtenerAbcVentas(filtros: AbcVentasFiltros): Promise<AbcVentasResponse> {
  return getAbcBridge().obtenerAbc(filtros) as Promise<AbcVentasResponse>
}
