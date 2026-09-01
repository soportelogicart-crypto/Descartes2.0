import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import {
  anularVentaTpv,
  obtenerContextoTpv,
  obtenerPrecioArticuloTpv,
  resolverArticuloTpv,
} from '@/api/tpv'
import {
  actualizarVenta,
  crearVenta,
  finalizarVenta,
  reservarAlbaran,
} from '@/api/ventas'
import { extractApiError } from '@/composables/extractApiError'
import {
  CLIENTE_RAPIDO_TPV,
  type TpvArticuloPrecio,
  type TpvCliente,
  type TpvContexto,
  type TpvLineaBorrador,
  type TpvPrecioPendiente,
  type TpvReservaTicket,
} from '@/types/tpv'
import type { VentaDetalle, VentaLinea, VentaPayload } from '@/types/ventas'

function importeLinea(cantidad: number, precio: number, pjeDto = 0): number {
  const bruto = cantidad * precio
  const dto = bruto * (pjeDto / 100)
  return Math.round((bruto - dto) * 100) / 100
}

function euros(n: number): string {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
}

function codigoRapido(contexto: TpvContexto | null): string {
  const c = String(contexto?.clienteRapido ?? '').trim()
  return c || CLIENTE_RAPIDO_TPV
}

export const useTpvVentaStore = defineStore('tpvVenta', () => {
  const contexto = ref<TpvContexto | null>(null)
  const venta = ref<VentaDetalle | null>(null)
  const reserva = ref<TpvReservaTicket | null>(null)
  const cliente = ref<TpvCliente | null>(null)
  const lineas = ref<TpvLineaBorrador[]>([])
  const loading = ref(false)
  const guardando = ref(false)
  const error = ref<string | null>(null)
  const aviso = ref<string | null>(null)
  const pendientePrecio = ref<TpvPrecioPendiente | null>(null)

  const total = computed(() => lineas.value.reduce((sum, l) => sum + l.importe, 0))

  const totalFormateado = computed(() => euros(total.value))

  /** Código que se graba: el elegido o ZZZZZZZZZ si no hay cliente. */
  const codigoCliente = computed(() => {
    const asignado = String(cliente.value?.codigo ?? '').trim()
    if (asignado) return asignado
    return codigoRapido(contexto.value)
  })

  /** Caja operativa: hay contexto de puesto y sesión, haya ticket o no. */
  const cajaAbierta = computed(() => !!contexto.value)

  /** Caja lista para vender: hay contexto y número de ticket propuesto. */
  const ticketListo = computed(() => !!contexto.value && !!reserva.value)

  /** Número propuesto al entrar; pasa a ser el definitivo al grabar la cabecera. */
  const numeroTicket = computed(() => venta.value?.albaran ?? reserva.value?.albaran ?? null)

  /** La cabecera solo existe en BD a partir de la primera línea. */
  const ventaGrabada = computed(() => !!venta.value && venta.value.albaran > 0)

  function limpiarAvisos() {
    error.value = null
    aviso.value = null
  }

  function lineasParaPayload(): VentaLinea[] {
    return lineas.value.map((l) => ({
      articulo: l.articulo,
      descripcion: l.descripcion,
      cantidad: l.cantidad,
      precio: l.precio,
      pjeDto: l.pjeDto,
      importe: importeLinea(l.cantidad, l.precio, l.pjeDto),
      pjeIva: l.pjeIva,
    }))
  }

  function payloadDesdeVenta(): VentaPayload {
    const v = venta.value!
    return {
      empresa: v.empresa,
      tipo: v.tipo || 'A',
      albaran: v.albaran,
      puesto: v.puesto,
      cliente: codigoCliente.value,
      razonSocial: v.razonSocial,
      razonSocial2: v.razonSocial2,
      nif: v.nif,
      fecha: v.fecha || undefined,
      vendedor: v.vendedor,
      vendedorApertura: v.vendedor,
      representante: v.representante ?? ' ',
      transporte: v.transporte ?? ' ',
      direccionEnvio: v.direccionEnvio,
      poblacionEnvio: v.poblacionEnvio,
      codigoPostalEnvio: v.codigoPostalEnvio,
      provinciaEnvio: v.provinciaEnvio,
      paisEnvio: v.paisEnvio,
      telefono: v.telefono,
      telefono2: v.telefono2,
      fax: v.fax,
      email: v.email,
      almacen: v.almacen,
      pedido: v.pedido,
      referencia1: v.referencia1,
      referencia2: v.referencia2,
      numeroDeSerie: v.numeroDeSerie,
      sujetoPasivo: !!v.sujetoPasivo,
      portes: v.portes,
      pjeIva1: Number(v.pjeIva1) > 0 ? Number(v.pjeIva1) : 21,
      fpago1: v.formasPago?.[0]?.codigo ?? ' ',
      fpago2: v.formasPago?.[1]?.codigo ?? '',
      impFpago1: Number(v.formasPago?.[0]?.importe ?? 0),
      impFpago2: Number(v.formasPago?.[1]?.importe ?? 0),
      tarifa: v.tarifa ?? contexto.value?.tarifa ?? 1,
      facturaTipo: v.facturaTipo ?? 'R',
      empresaFacturacion: v.empresa,
      actividad: 0,
      agente: ' ',
      lineas: lineasParaPayload(),
    }
  }

  /** Cabecera de alta con el número ya propuesto en la reserva. */
  function payloadNuevaVenta(): VentaPayload {
    const c = contexto.value!
    const r = reserva.value!
    const cli = cliente.value
    return {
      empresa: c.empresa,
      tipo: 'A',
      albaran: r.albaran,
      puesto: c.puesto,
      cliente: codigoCliente.value,
      razonSocial: cli?.razonSocial ?? '',
      razonSocial2: cli?.razonSocial2 ?? '',
      nif: cli?.nif ?? '',
      telefono: cli?.telefono ?? '',
      email: cli?.email ?? '',
      tarifa: c.tarifa > 0 ? c.tarifa : 1,
      vendedor: r.vendedor,
      vendedorApertura: r.vendedor,
      almacen: r.almacen,
      representante: ' ',
      transporte: ' ',
      fpago1: ' ',
      fpago2: '',
      actividad: 0,
      agente: ' ',
      facturaTipo: 'R',
      empresaFacturacion: c.empresa,
      pjeIva1: 21,
      lineas: lineasParaPayload(),
    }
  }

  async function persistirLineas() {
    if (!contexto.value || !reserva.value) return
    guardando.value = true
    try {
      if (!venta.value) {
        // Primera línea del ticket: ahora sí se graba la cabecera en BD.
        venta.value = await crearVenta(payloadNuevaVenta())
        return
      }
      venta.value = await actualizarVenta(
        venta.value.empresa,
        venta.value.tipo,
        venta.value.albaran,
        payloadDesdeVenta()
      )
    } finally {
      guardando.value = false
    }
  }

  /** Abre la caja (puesto, sesión, tarifa). No propone número de ticket. */
  async function abrirCaja(empresa: string, puesto: string) {
    loading.value = true
    limpiarAvisos()
    lineas.value = []
    venta.value = null
    reserva.value = null
    cliente.value = null
    pendientePrecio.value = null
    try {
      contexto.value = await obtenerContextoTpv(empresa, puesto)
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo abrir la caja')
      throw e
    } finally {
      loading.value = false
    }
  }

  /**
   * Propone el número del ticket. Solo se llama desde NUEVA VENTA: reservar
   * consume el contador `Empresas.UltAlbaranVen`, así que entrar en la caja o
   * terminar un cobro no deben coger número "por si acaso".
   */
  async function abrirTicket(): Promise<boolean> {
    const c = contexto.value
    if (!c) {
      error.value = 'Caja no abierta'
      return false
    }
    // `guardando` y no `loading`: la pantalla debe seguir montada mientras se
    // pide el número, en vez de parpadear al aviso "Abriendo caja…".
    guardando.value = true
    limpiarAvisos()
    lineas.value = []
    venta.value = null
    cliente.value = null
    pendientePrecio.value = null
    try {
      const r = await reservarAlbaran({ empresa: c.empresa, puesto: c.puesto })
      reserva.value = { albaran: r.albaran, vendedor: r.vendedor, almacen: r.almacen }
      return true
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo abrir el ticket')
      return false
    } finally {
      guardando.value = false
    }
  }

  /** Tras cobrar: la caja se queda sin ticket hasta la siguiente NUEVA VENTA. */
  function cerrarTicket() {
    lineas.value = []
    venta.value = null
    reserva.value = null
    cliente.value = null
    pendientePrecio.value = null
  }

  /** Alta de línea a partir de un artículo ya resuelto (botón táctil, tecleo o pistola). */
  async function anadirResuelto(art: TpvArticuloPrecio, unidades: number) {
    if (art.bloqueado) {
      error.value = `Artículo ${art.codigo} no disponible`
      return
    }

    const pjeIva = art.iva ?? 21
    const existente = lineas.value.find((l) => l.articulo === art.codigo)
    if (existente) {
      existente.cantidad += unidades
      existente.importe = importeLinea(
        existente.cantidad,
        existente.precio,
        existente.pjeDto
      )
      await persistirLineas()
      return
    }

    // Artículo sin PVP en la tarifa: el cajero introduce el precio antes de crear la línea.
    if (art.precio <= 0) {
      pendientePrecio.value = {
        articulo: art.codigo,
        descripcion: art.descripcion,
        cantidad: unidades,
        pjeIva,
      }
      return
    }

    lineas.value.push({
      articulo: art.codigo,
      descripcion: art.descripcion,
      cantidad: unidades,
      precio: art.precio,
      pjeDto: 0,
      importe: importeLinea(unidades, art.precio),
      pjeIva,
    })
    await persistirLineas()
  }

  /** Entrada manual o por pistola: resuelve código, Alternativo o EAN. */
  async function anadirPorCodigo(query: string, cantidad = 1) {
    const q = String(query ?? '').trim()
    if (!q) return
    if (!contexto.value || !reserva.value) {
      error.value = 'Pulse NUEVA VENTA para abrir un ticket'
      return
    }
    limpiarAvisos()
    guardando.value = true
    try {
      const tarifa = contexto.value.tarifa > 0 ? contexto.value.tarifa : 1
      const art = await resolverArticuloTpv(q, { tarifa })
      // Un EAN de paquete aporta varias unidades por lectura.
      const unidades = (cantidad > 0 ? cantidad : 1) * (art.unidadesPaquete || 1)
      await anadirResuelto(art, unidades)
    } catch (e: unknown) {
      error.value = extractApiError(e, `No se pudo añadir el artículo "${q}"`)
    } finally {
      guardando.value = false
    }
  }

  async function anadirArticulo(codigo: string, cantidad = 1) {
    if (!contexto.value || !reserva.value) {
      error.value = 'Pulse NUEVA VENTA para abrir un ticket'
      return
    }
    const unidades = cantidad > 0 ? cantidad : 1
    limpiarAvisos()
    guardando.value = true
    try {
      const tarifa = contexto.value.tarifa > 0 ? contexto.value.tarifa : 1
      const art = await obtenerPrecioArticuloTpv(codigo, {
        tarifa,
        empresa: contexto.value.empresa,
      })
      await anadirResuelto(art, unidades)
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo añadir el artículo')
    } finally {
      guardando.value = false
    }
  }

  async function confirmarPrecioPendiente(precio: number) {
    const pend = pendientePrecio.value
    if (!pend) return
    if (!(precio > 0)) {
      error.value = 'Introduzca un precio mayor que cero'
      return
    }
    pendientePrecio.value = null
    guardando.value = true
    limpiarAvisos()
    try {
      lineas.value.push({
        articulo: pend.articulo,
        descripcion: pend.descripcion,
        cantidad: pend.cantidad,
        precio,
        pjeDto: 0,
        importe: importeLinea(pend.cantidad, precio),
        pjeIva: pend.pjeIva,
      })
      await persistirLineas()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo añadir el artículo')
    } finally {
      guardando.value = false
    }
  }

  function cancelarPrecioPendiente() {
    pendientePrecio.value = null
  }

  async function cambiarPrecioLinea(indice: number, precio: number) {
    const lin = lineas.value[indice]
    if (!lin) return
    if (!(precio > 0)) {
      error.value = 'Introduzca un precio mayor que cero'
      return
    }
    lin.precio = precio
    lin.importe = importeLinea(lin.cantidad, lin.precio, lin.pjeDto)
    guardando.value = true
    limpiarAvisos()
    try {
      await persistirLineas()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo cambiar el precio')
    } finally {
      guardando.value = false
    }
  }

  async function cambiarCantidad(indice: number, delta: number) {
    const lin = lineas.value[indice]
    if (!lin) return
    const nueva = lin.cantidad + delta
    // Bajar de 1 no borra: para eliminar está la tecla BORRAR LINEA.
    if (nueva < 1) {
      aviso.value = 'Cantidad mínima 1. Use BORRAR LINEA para eliminarla'
      return
    }
    lin.cantidad = nueva
    lin.importe = importeLinea(lin.cantidad, lin.precio, lin.pjeDto)
    guardando.value = true
    limpiarAvisos()
    try {
      await persistirLineas()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo actualizar la línea')
    } finally {
      guardando.value = false
    }
  }

  async function cambiarDescuentoLinea(indice: number, pjeDto: number) {
    const lin = lineas.value[indice]
    if (!lin) return
    if (!Number.isFinite(pjeDto) || pjeDto < 0 || pjeDto > 100) {
      error.value = 'El descuento debe estar entre 0 y 100'
      return
    }

    lin.pjeDto = Math.round(pjeDto * 100) / 100
    lin.importe = importeLinea(lin.cantidad, lin.precio, lin.pjeDto)
    guardando.value = true
    limpiarAvisos()
    try {
      await persistirLineas()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo aplicar el descuento')
    } finally {
      guardando.value = false
    }
  }

  /**
   * Asigna (o quita, con `null`) el cliente del ticket. Si la cabecera aún no
   * existe queda pendiente y se graba al crearla con la primera línea.
   */
  async function asignarCliente(cli: TpvCliente | null) {
    if (!contexto.value) {
      error.value = 'Caja no abierta'
      return
    }
    cliente.value = cli
    limpiarAvisos()

    // El precio ya calculado no se recalcula: avisamos si la tarifa no coincide.
    const tarifaPuesto = contexto.value.tarifa > 0 ? contexto.value.tarifa : 1
    if (cli && cli.tarifa > 0 && cli.tarifa !== tarifaPuesto && lineas.value.length > 0) {
      aviso.value = `El cliente tiene tarifa ${cli.tarifa} y la caja aplica la ${tarifaPuesto}: revise los precios`
    }

    if (!venta.value) return

    guardando.value = true
    try {
      const v = venta.value
      const payload = payloadDesdeVenta()
      payload.cliente = codigoCliente.value
      payload.razonSocial = cli?.razonSocial ?? ''
      payload.razonSocial2 = cli?.razonSocial2 ?? ''
      payload.nif = cli?.nif ?? ''
      payload.telefono = cli?.telefono ?? ''
      payload.email = cli?.email ?? ''
      venta.value = await actualizarVenta(v.empresa, v.tipo, v.albaran, payload)
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo asignar el cliente')
    } finally {
      guardando.value = false
    }
  }

  /** Finaliza como Ticket, Albarán, Presupuesto o Factura. */
  async function cobrar(
    tipoDocumento: string,
    formaPago = ''
  ): Promise<VentaDetalle | null> {
    const tipo = String(tipoDocumento ?? '').trim().toUpperCase()
    if (!['T', 'A', 'P', 'F'].includes(tipo)) {
      error.value = 'Seleccione el tipo de documento'
      return null
    }
    const fpago = String(formaPago ?? '').trim()
    const requierePago = tipo === 'T' || tipo === 'F'
    if (requierePago && !fpago) {
      error.value = 'Seleccione la forma de pago'
      return null
    }
    if (!venta.value || lineas.value.length === 0) {
      error.value = 'El ticket no tiene líneas'
      return null
    }

    guardando.value = true
    limpiarAvisos()
    try {
      const v = venta.value
      const payload = payloadDesdeVenta()
      if (requierePago) {
        payload.fpago1 = fpago
        payload.impFpago1 = total.value
      }
      venta.value = await actualizarVenta(v.empresa, v.tipo, v.albaran, payload)

      const cerrada = await finalizarVenta(
        v.empresa,
        v.tipo,
        v.albaran,
        tipo,
        requierePago ? fpago : undefined
      )
      venta.value = cerrada
      return cerrada
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo finalizar la venta')
      return null
    } finally {
      guardando.value = false
    }
  }

  async function quitarLinea(indice: number) {
    if (indice < 0 || indice >= lineas.value.length) return
    lineas.value.splice(indice, 1)
    if (lineas.value.length === 0 && venta.value) {
      await vaciarTicket()
      return
    }
    guardando.value = true
    limpiarAvisos()
    try {
      await persistirLineas()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo quitar la línea')
    } finally {
      guardando.value = false
    }
  }

  /**
   * El ticket se queda sin líneas al quitar la última: se borra la cabecera
   * para no dejar el documento huérfano, pero el ticket sigue abierto: quitar
   * el último artículo no equivale a cerrar la venta.
   *
   * Se reutiliza el mismo número en lugar de pedir otro: `reservarAlbaran`
   * consume contador (`Empresas.UltAlbaranVen`) y, al haberse borrado la
   * cabecera, ese número vuelve a estar libre. Así no se abren huecos en la
   * numeración ni cambia el ticket que el cajero tiene delante.
   *
   * El cliente asignado también se conserva: quitar el último artículo no
   * significa que el cliente se haya ido.
   */
  async function vaciarTicket(): Promise<boolean> {
    const cli = cliente.value
    const r = reserva.value
    if (!(await anularVentaActual())) return false
    reserva.value = r
    cliente.value = cli
    return true
  }

  /**
   * Cancela el ticket en curso. Si la primera línea ya creó la cabecera,
   * elimina el documento completo en el backend antes de limpiar la pantalla.
   */
  async function anularVentaActual(): Promise<boolean> {
    guardando.value = true
    limpiarAvisos()
    try {
      const v = venta.value
      if (v) {
        await anularVentaTpv(v.empresa, v.tipo, v.albaran)
      }
      lineas.value = []
      venta.value = null
      reserva.value = null
      cliente.value = null
      pendientePrecio.value = null
      return true
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo anular la venta')
      return false
    } finally {
      guardando.value = false
    }
  }

  return {
    contexto,
    venta,
    reserva,
    cliente,
    codigoCliente,
    lineas,
    loading,
    guardando,
    error,
    aviso,
    pendientePrecio,
    total,
    totalFormateado,
    cajaAbierta,
    ticketListo,
    numeroTicket,
    ventaGrabada,
    abrirCaja,
    abrirTicket,
    cerrarTicket,
    anadirArticulo,
    anadirPorCodigo,
    confirmarPrecioPendiente,
    cancelarPrecioPendiente,
    cambiarPrecioLinea,
    cambiarCantidad,
    cambiarDescuentoLinea,
    quitarLinea,
    anularVentaActual,
    asignarCliente,
    cobrar,
  }
})
