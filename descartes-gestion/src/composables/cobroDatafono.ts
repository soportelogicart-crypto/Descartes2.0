import {
  cancelarDatafonoDispositivo,
  cobrarDatafonoDispositivo,
} from '@/api/ventas'
import {
  getDescartesBridge,
  type PaymentTerminalPayload,
  type PaymentTerminalResult,
} from '@/bridge/electron'
import type { TpvContexto } from '@/types/tpv'

export type DevolucionDatafonoContexto = {
  pedidoOriginal?: string
  rtsOriginal?: string
  /** Código AUT del ticket original (legacy / Redsys). */
  codigoAutorizacion?: string
  /** Cuatro últimos dígitos de la tarjeta (CLR). */
  clr?: string
  devolucionSinOriginal?: boolean
  /** Si true, usa ComContableTrj (tarjeta en pinpad). Legacy usa OperComContable vía BD. */
  devolucionPinpad?: boolean
  /** Devolución Larasa: OPERCOMCONTABLE con RTS (sin pedido). */
  modoLegacyRts?: boolean
}

export type OperacionCobroDatafono = {
  operationId: string
  ejecutar: () => Promise<PaymentTerminalResult>
  cancelar: () => Promise<PaymentTerminalResult>
}

function operationId(referencia: string): string {
  return referencia.replace(/[^a-zA-Z0-9_-]/g, '-')
}

/**
 * Una operación estable permite que la UI cancele exactamente el cobro que
 * inició. El driver/proveedor solo existe en Electron; Vue maneja el contrato.
 */
export function crearOperacionCobroDatafono(
  contexto: TpvContexto,
  importe: number,
  referencia: string,
  devolucion: DevolucionDatafonoContexto = {}
): OperacionCobroDatafono {
  const id = operationId(referencia)
  const esDevolucion = importe < -0.005
  const importeAbs = Math.abs(importe)
  const payloadBase: PaymentTerminalPayload = {
    driver: contexto.datafono,
    terminal: contexto.terminalDatafono,
    operationId: id,
    amountCents: Math.round(importeAbs * 100),
    tipoOperacion: esDevolucion ? 'DEVOLUCION' : 'PAGO',
    pedidoOriginal: String(devolucion.pedidoOriginal ?? '').trim() || undefined,
    rtsOriginal: String(devolucion.rtsOriginal ?? '').trim() || undefined,
    codigoAutorizacion: String(devolucion.codigoAutorizacion ?? '').trim() || undefined,
    clr: String(devolucion.clr ?? '').trim() || undefined,
    factura: esDevolucion
      ? String(devolucion.codigoAutorizacion ?? referencia).slice(0, 40)
      : referencia.slice(0, 40),
    devolucionSinOriginal:
      esDevolucion &&
      !devolucion.pedidoOriginal &&
      !!devolucion.devolucionSinOriginal,
    devolucionPinpad: esDevolucion && !!devolucion.devolucionPinpad,
    modoLegacyRts:
      esDevolucion &&
      !!String(devolucion.rtsOriginal ?? '').trim() &&
      !String(devolucion.pedidoOriginal ?? '').trim(),
    currency: 'EUR',
    reference: referencia,
    timeoutMs: 120000,
  }
  let payloadActivo = payloadBase

  return {
    operationId: id,
    async ejecutar() {
      const bridge = getDescartesBridge()
      if (bridge?.paymentTerminalCharge) {
        const local = await bridge.getEquipoConfig()
        if (!local.datafono.activo) {
          return {
            ok: false,
            approved: false,
            code: 'DATAFONO_NO_CONFIGURADO',
            message: 'El datáfono no está activado en Configuración → Datáfono',
          }
        }
        payloadActivo = {
          ...payloadBase,
          driver: local.datafono.driver,
          terminal: local.datafono.terminal,
          timeoutMs: local.datafono.timeoutMs,
          configuracion: local.datafono,
        }
        return bridge.paymentTerminalCharge(payloadActivo)
      }
      return cobrarDatafonoDispositivo(contexto.puesto, payloadBase)
    },
    async cancelar() {
      const bridge = getDescartesBridge()
      return bridge?.paymentTerminalCancel
        ? bridge.paymentTerminalCancel(payloadActivo)
        : cancelarDatafonoDispositivo(contexto.puesto, { operationId: id })
    },
  }
}
