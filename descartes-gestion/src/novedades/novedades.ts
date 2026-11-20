/**
 * Único sitio del listado de novedades.
 * Solo opciones nuevas o mejoras que nota quien usa el programa.
 * Para ampliarlo, añade una entrada aquí (fecha ISO y texto en ca, es y en).
 */
export type IdiomaNovedad = 'ca' | 'es' | 'en'

export type Novedad = {
  fecha: string
  titulo: Record<IdiomaNovedad, string>
  texto: Record<IdiomaNovedad, string>
}

export const novedades: Novedad[] = [
  {
    fecha: '2026-11-19',
    titulo: {
      ca: 'Comissionistes a les comandes',
      es: 'Comisionistas en pedidos',
      en: 'Agents on orders',
    },
    texto: {
      ca: 'A la capçalera de la comanda hi ha una pestanya Comissions (no totes les comandes en porten). S\'hi pot indicar un proveïdor amb import i fins a dos treballadors amb percentatge; l\'import del treballador es calcula al costat de les seves dades. Hi ha llistats de comissions de proveïdor i de treballadors.',
      es: 'En la cabecera del pedido hay una pestaña Comisiones (no todos los pedidos la llevan). Se puede indicar un proveedor con importe y hasta dos trabajadores con porcentaje; el importe del trabajador se calcula al lado de sus datos. Hay listados de comisiones de proveedor y de trabajadores.',
      en: 'The order header has a Commissions tab (not every order has one). You can enter a supplier with an amount and up to two employees with a percentage; the employee amount is calculated next to their details. There are listings for supplier commissions and for employee commissions.',
    },
  },
  {
    fecha: '2026-11-19',
    titulo: {
      ca: 'Línies al manteniment de comandes i albarans',
      es: 'Líneas en el mantenimiento de pedidos y albaranes',
      en: 'Lines when maintaining orders and delivery notes',
    },
    texto: {
      ca: 'Es poden afegir i modificar línies, entrar línies NO i, a les comandes, ordenar per zona. Als albarans també s\'insereixen i es mouen línies i es marquen línies NO.',
      es: 'Se pueden añadir y modificar líneas, entrar líneas NO y, en pedidos, ordenar por zona. En albaranes también se insertan y se mueven líneas y se marcan líneas NO.',
      en: 'You can add and edit lines, enter NO lines and, on orders, sort by zone. On delivery notes you can also insert and move lines, and mark lines as NO.',
    },
  },
]
