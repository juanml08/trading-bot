// La API entrega timestamps en UTC (ISO 8601). El bot y la persistencia
// trabajan en UTC; la conversión a hora de Colombia ocurre únicamente aquí,
// en la capa de presentación — nunca asumiendo la zona horaria del
// navegador (que puede no ser America/Bogota).
const ZONA_BOGOTA = 'America/Bogota'

/**
 * Descompone un timestamp ISO en sus componentes de fecha/hora ya
 * convertidos a America/Bogota, listos para formatear sin volver a tocar
 * timezone.
 */
export function partesEnBogota(iso) {
  const fecha = new Date(iso)
  const partes = new Intl.DateTimeFormat('es-CO', {
    timeZone: ZONA_BOGOTA,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(fecha)

  const valor = (tipo) => partes.find((p) => p.type === tipo)?.value ?? '00'

  return {
    anio: valor('year'),
    mes: valor('month'),
    dia: valor('day'),
    hora: valor('hour'),
    minuto: valor('minute'),
  }
}
