import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import {
  anularVentaTpv,
  buscarArticulosTpv,
  obtenerContextoTpv,
  obtenerPrecioArticuloTpv,
  obtenerTicketsEsperaTpv,
  ponerTicketEnEsperaTpv,
  recuperarTicketEsperaTpv,
  resolverArticuloTpv,
} from '@/api/tpv'
import {
  actualizarVenta,
  crearVenta,
  finalizarVenta,
  ofertasLinea,
  porcentajesOfertaLinea,
  reservarAlbaran,
  type OfertaLinea,
} from '@/api/ventas'
import { extractApiError, isApiNotFound } from '@/composables/extractApiError'
import {
  CLIENTE_RAPIDO_TPV,
  type TpvArticuloPrecio,
  type TpvCliente,
  type TpvContexto,
  type TpvLineaBorrador,
  type TpvPrecioPendiente,
  type TpvReservaTicket,
  type TpvTicketEspera,
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
  const cargandoTicketsEspera = ref(false)
  const error = ref<string | null>(null)
  const aviso = ref<string | null>(null)
  const pendientePrecio = ref<TpvPrecioPendiente | null>(null)
  const ticketsEspera = ref<TpvTicketEspera[]>([])
  /** Documento abierto solo para consultarlo: no se graba ni se anula. */
  const enConsulta = ref(false)

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

  function rechazarConsulta(): boolean {
    if (!enConsulta.value) return false
    error.value = 'Está consultando un documento. Ciérrelo antes de vender.'
    return true
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
      vendedor: r.vendedor || c.vendedor,
      vendedorApertura: r.vendedor || c.vendedor,
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

  function cargarVentaRecuperada(detalle: VentaDetalle) {
    venta.value = detalle
    reserva.value = {
      albaran: detalle.albaran,
      vendedor: detalle.vendedor,
      almacen: detalle.almacen ?? null,
    }
    lineas.value = (detalle.lineas ?? []).map((l) => ({
      articulo: String(l.articulo ?? '').trim(),
      descripcion: String(l.descripcion ?? '').trim(),
      cantidad: Number(l.cantidad) || 0,
      precio: Number(l.precio) || 0,
      pjeDto: Number(l.pjeDto) || 0,
      importe: Number(l.importe) || 0,
      pjeIva: Number(l.pjeIva) || 0,
    }))

    const codigo = String(detalle.cliente ?? '').trim()
    cliente.value =
      !codigo || codigo === codigoRapido(contexto.value)
        ? null
        : {
            codigo,
            razonSocial: String(detalle.razonSocial ?? ''),
            razonSocial2: String(detalle.razonSocial2 ?? ''),
            nif: String(detalle.nif ?? ''),
            direccion: String(detalle.direccionEnvio ?? ''),
            poblacion: String(detalle.poblacionEnvio ?? ''),
            codigoPostal: String(detalle.codigoPostalEnvio ?? ''),
            provincia: String(detalle.provinciaEnvio ?? ''),
            pais: String(detalle.paisEnvio ?? ''),
            telefono: String(detalle.telefono ?? ''),
            email: String(detalle.email ?? ''),
            formaPago: String(detalle.formasPago?.[0]?.codigo ?? ''),
            tarifa: Number(detalle.tarifa ?? contexto.value?.tarifa ?? 0),
          }
    pendientePrecio.value = null
  }

  function mostrarConsulta(detalle: VentaDetalle): boolean {
    if (!enConsulta.value && (lineas.value.length > 0 || ventaGrabada.value)) {
      error.value = 'Ponga primero la venta actual en espera o anúlela'
      return false
    }
    cargarVentaRecuperada(detalle)
    enConsulta.value = true
    aviso.value = null
    return true
  }

  function cerrarConsulta() {
    lineas.value = []
    venta.value = null
    reserva.value = null
    cliente.value = null
    pendientePrecio.value = null
    enConsulta.value = false
  }

  /**
   * Copia el documento en consulta a un ticket nuevo. El original no se
   * modifica: se reserva otro albarán y se graban las mismas líneas y el
   * mismo cliente para poder cambiarlas y cobrarlas.
   */
  async function repetirVentaConsultada(): Promise<boolean> {
    if (!enConsulta.value || !venta.value) return false
    const detalle = venta.value
    const copia = lineas.value
      .filter((l) => {
        const art = l.articulo.trim()
        if (art.toUpperCase() === 'NO') return l.descripcion.trim() !== ''
        return art !== '' && l.cantidad !== 0
      })
      .map((l) => ({ ...l }))
    if (!copia.length) {
      error.value = 'No hay líneas para repetir'
      return false
    }
    const cli = cliente.value ? { ...cliente.value } : null
    cerrarConsulta()
    const abierto = await abrirTicket()
    if (!abierto) {
      mostrarConsulta(detalle)
      return false
    }
    cliente.value = cli
    lineas.value = copia
    try {
      await persistirLineas()
      aviso.value = 'Venta repetida. Puede modificarla y cobrarla.'
      return true
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo repetir la venta')
      return false
    }
  }

  async function cargarTicketsEspera(): Promise<boolean> {
    const c = contexto.value
    if (!c) return false
    cargandoTicketsEspera.value = true
    limpiarAvisos()
    try {
      ticketsEspera.value = await obtenerTicketsEsperaTpv(c.empresa, c.puesto)
      return true
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudieron cargar los tickets en espera')
      return false
    } finally {
      cargandoTicketsEspera.value = false
    }
  }

  async function ponerEnEspera(): Promise<boolean> {
    if (rechazarConsulta()) return false
    const c = contexto.value
    const v = venta.value
    if (!c || !v || lineas.value.length === 0) {
      error.value = 'El ticket no tiene líneas para poner en espera'
      return false
    }
    guardando.value = true
    limpiarAvisos()
    try {
      await ponerTicketEnEsperaTpv(v.empresa, v.tipo, v.albaran, c.puesto)
      cerrarTicket()
      aviso.value = `Ticket ${v.albaran} guardado en espera`
      return true
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo poner el ticket en espera')
      return false
    } finally {
      guardando.value = false
    }
  }

  async function recuperarEnEspera(ticket: TpvTicketEspera): Promise<boolean> {
    const c = contexto.value
    if (!c) return false
    if (venta.value && lineas.value.length > 0) {
      error.value = 'Ponga primero el ticket actual en espera o anúlelo'
      return false
    }
    guardando.value = true
    limpiarAvisos()
    try {
      const detalle = await recuperarTicketEsperaTpv(
        ticket.empresa,
        ticket.tipo,
        ticket.albaran,
        c.puesto
      )
      cargarVentaRecuperada(detalle)
      ticketsEspera.value = ticketsEspera.value.filter(
        (t) => !(t.empresa === ticket.empresa && t.tipo === ticket.tipo && t.albaran === ticket.albaran)
      )
      aviso.value = `Ticket ${ticket.albaran} recuperado`
      return true
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo recuperar el ticket')
      return false
    } finally {
      guardando.value = false
    }
  }

  async function ofertaArticulo(articulo: string): Promise<OfertaLinea> {
    const sinOferta: OfertaLinea = { pjeDto: 0, regalo: null }
    const empresa = String(contexto.value?.empresa ?? '').trim()
    const cli = codigoCliente.value
    if (!empresa || !cli || cli === CLIENTE_RAPIDO_TPV) return sinOferta
    try {
      const map = await ofertasLinea({
        cliente: cli,
        empresa,
        articulos: [articulo],
      })
      return map[articulo.trim()] ?? sinOferta
    } catch {
      return sinOferta
    }
  }

  /** Artículo regalo de la oferta: va al precio de tarifa con el 100 % de descuento. */
  async function anadirRegalo(regalo: OfertaLinea['regalo']) {
    if (!regalo || !contexto.value) return
    const existente = lineas.value.find((l) => l.regalo && l.articulo === regalo.articulo)
    if (existente) {
      existente.cantidad += regalo.cantidad
      return
    }
    try {
      const tarifa = contexto.value.tarifa > 0 ? contexto.value.tarifa : 1
      const art = await obtenerPrecioArticuloTpv(regalo.articulo, {
        tarifa,
        empresa: contexto.value.empresa,
      })
      lineas.value.push({
        articulo: art.codigo,
        descripcion: art.descripcion,
        cantidad: regalo.cantidad,
        precio: art.precio,
        pjeDto: 100,
        importe: 0,
        pjeIva: art.iva ?? 21,
        regalo: true,
      })
    } catch (e: unknown) {
      error.value = extractApiError(e, `No se pudo añadir el artículo regalo ${regalo.articulo}`)
    }
  }

  async function aplicarOfertasEnLineas() {
    const empresa = String(contexto.value?.empresa ?? '').trim()
    const arts = lineas.value.map((l) => l.articulo).filter((c) => c.trim() !== '')
    if (!empresa || arts.length === 0) return
    try {
      const map = await porcentajesOfertaLinea({
        cliente: codigoCliente.value,
        empresa,
        articulos: arts,
      })
      for (const linea of lineas.value) {
        const codigo = linea.articulo.trim()
        // pjeDto 100: regalo de un ticket recuperado (la marca regalo no se guarda).
        if (!codigo || linea.regalo || linea.pjeDto >= 100 || !(codigo in map)) continue
        linea.pjeDto = map[codigo]
        linea.importe = importeLinea(linea.cantidad, linea.precio, linea.pjeDto)
      }
    } catch {
      /* la línea se añade igual, sin el porcentaje de la oferta */
    }
  }

  /** Alta de línea a partir de un artículo ya resuelto (botón táctil, tecleo o pistola). */
  async function anadirResuelto(art: TpvArticuloPrecio, unidades: number) {
    if (art.bloqueado) {
      error.value = `Artículo ${art.codigo} no disponible`
      return
    }

    const pjeIva = art.iva ?? 21

    // Sin PVP cada pulsación pide el precio y crea otra línea: no se reutiliza el anterior.
    if (art.precio <= 0) {
      pendientePrecio.value = {
        articulo: art.codigo,
        descripcion: art.descripcion,
        cantidad: unidades,
        pjeIva,
      }
      return
    }

    const existente = lineas.value.find((l) => !l.regalo && l.articulo === art.codigo)
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

    const oferta = await ofertaArticulo(art.codigo)
    lineas.value.push({
      articulo: art.codigo,
      descripcion: art.descripcion,
      cantidad: unidades,
      precio: art.precio,
      pjeDto: oferta.pjeDto,
      importe: importeLinea(unidades, art.precio, oferta.pjeDto),
      pjeIva,
    })
    await anadirRegalo(oferta.regalo)
    await persistirLineas()
  }

  /**
   * Línea de nota (artículo NO): no lleva precio, no mueve stock y cada una
   * es una línea distinta. El texto se escribe después con DESCRIP.
   */
  async function anadirNota(): Promise<boolean> {
    if (rechazarConsulta()) return false
    if (!contexto.value || !reserva.value) {
      error.value = 'Pulse NUEVA VENTA para abrir un ticket'
      return false
    }
    limpiarAvisos()
    lineas.value.push({
      articulo: 'NO',
      descripcion: '',
      cantidad: 0,
      precio: 0,
      pjeDto: 0,
      importe: 0,
      pjeIva: 0,
    })
    try {
      await persistirLineas()
      return true
    } catch (e: unknown) {
      lineas.value.pop()
      error.value = extractApiError(e, 'No se pudo añadir la nota')
      return false
    }
  }

  /** Un código de barras no encontrado no debe buscarse como texto. */
  function pareceCodigoBarras(q: string): boolean {
    return /^\d{8,}$/.test(q)
  }

  /**
   * Entrada manual o por pistola. Primero código, Alternativo o EAN.
   * Si no existe y el texto no es un código de barras, busca por descripción.
   */
  async function anadirPorCodigo(
    query: string,
    cantidad = 1
  ): Promise<'anadido' | 'nota' | 'elegir' | 'error'> {
    if (rechazarConsulta()) return 'error'
    const q = String(query ?? '').trim()
    if (!q) return 'error'
    if (!contexto.value || !reserva.value) {
      error.value = 'Pulse NUEVA VENTA para abrir un ticket'
      return 'error'
    }
    if (q.toUpperCase() === 'NO') {
      return (await anadirNota()) ? 'nota' : 'error'
    }
    limpiarAvisos()
    guardando.value = true
    const tarifa = contexto.value.tarifa > 0 ? contexto.value.tarifa : 1
    try {
      const art = await resolverArticuloTpv(q, { tarifa })
      const unidades = (cantidad > 0 ? cantidad : 1) * (art.unidadesPaquete || 1)
      await anadirResuelto(art, unidades)
      return 'anadido'
    } catch (e: unknown) {
      if (!isApiNotFound(e) || pareceCodigoBarras(q) || q.length < 2) {
        error.value = extractApiError(e, `No se pudo añadir el artículo "${q}"`)
        return 'error'
      }
    } finally {
      guardando.value = false
    }

    try {
      const items = await buscarArticulosTpv(q, { tarifa, limite: 30, ambito: 'articulo' })
      if (items.length === 1) {
        await anadirArticulo(items[0].codigo, cantidad > 0 ? cantidad : 1)
        return 'anadido'
      }
      if (items.length > 1) return 'elegir'
      error.value = `No se ha encontrado ningún artículo con "${q}"`
      return 'error'
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo buscar el artículo')
      return 'error'
    }
  }

  async function anadirArticulo(codigo: string, cantidad = 1) {
    if (rechazarConsulta()) return
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
    if (rechazarConsulta()) return
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
      const oferta = await ofertaArticulo(pend.articulo)
      lineas.value.push({
        articulo: pend.articulo,
        descripcion: pend.descripcion,
        cantidad: pend.cantidad,
        precio,
        pjeDto: oferta.pjeDto,
        importe: importeLinea(pend.cantidad, precio, oferta.pjeDto),
        pjeIva: pend.pjeIva,
      })
      await anadirRegalo(oferta.regalo)
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
    if (rechazarConsulta()) return
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
    if (rechazarConsulta()) return
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

  async function cambiarDescripcionLinea(indice: number, descripcion: string) {
    if (rechazarConsulta()) return
    const lin = lineas.value[indice]
    if (!lin) return
    lin.descripcion = descripcion.trim().slice(0, 50)
    guardando.value = true
    limpiarAvisos()
    try {
      await persistirLineas()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo cambiar la descripción')
    } finally {
      guardando.value = false
    }
  }

  async function cambiarDescuentoLinea(indice: number, pjeDto: number) {
    if (rechazarConsulta()) return
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
    if (rechazarConsulta()) return
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

    await aplicarOfertasEnLineas()
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
    formaPago = '',
    opciones: { aplicarValeFidelizacion?: boolean; aplicarPuntosFidelizacion?: boolean } = {}
  ): Promise<VentaDetalle | null> {
    if (rechazarConsulta()) return null
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
        requierePago ? fpago : undefined,
        opciones
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
    if (rechazarConsulta()) return
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
    if (enConsulta.value) {
      cerrarConsulta()
      return true
    }
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
    cargandoTicketsEspera,
    error,
    aviso,
    pendientePrecio,
    ticketsEspera,
    total,
    totalFormateado,
    cajaAbierta,
    ticketListo,
    numeroTicket,
    ventaGrabada,
    enConsulta,
    abrirCaja,
    abrirTicket,
    cerrarTicket,
    cargarTicketsEspera,
    ponerEnEspera,
    recuperarEnEspera,
    cargarVentaRecuperada,
    mostrarConsulta,
    cerrarConsulta,
    repetirVentaConsultada,
    anadirArticulo,
    anadirNota,
    anadirPorCodigo,
    confirmarPrecioPendiente,
    cancelarPrecioPendiente,
    cambiarPrecioLinea,
    cambiarDescripcionLinea,
    cambiarCantidad,
    cambiarDescuentoLinea,
    quitarLinea,
    anularVentaActual,
    asignarCliente,
    cobrar,
  }
})
