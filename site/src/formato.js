// Formato de presentación de precios y tiempos. Solo cambia cómo se ve un
// valor: nunca modifica lo que el backend almacena.

/**
 * Precio de un activo en USDT con decimales adaptativos: los precios chicos
 * (HOT, ONT, ...) necesitan más decimales para que el valor real sea visible.
 *   >= 1000 -> 2 decimales        ($67,000.12 sin separador de miles: $67000.12)
 *   >= 1    -> 4 decimales        ($1.2345)
 *   <  1    -> 4 cifras significativas, mínimo 2 decimales y sin ceros
 *              de relleno ($0.05969, $0.0001235, $0.5)
 */
export function formatoPrecio(valor) {
  const numero = Number(valor)
  if (valor === null || valor === undefined || valor === '' || !Number.isFinite(numero)) {
    return '—'
  }

  const absoluto = Math.abs(numero)
  const signo = numero < 0 ? '−' : ''

  if (absoluto === 0) {
    return '$0.00'
  }

  if (absoluto >= 1000) {
    return `${signo}$${absoluto.toFixed(2)}`
  }

  if (absoluto >= 1) {
    return `${signo}$${absoluto.toFixed(4)}`
  }

  const decimales = Math.min(12, -Math.floor(Math.log10(absoluto)) + 3)
  const texto = absoluto.toFixed(decimales).replace(/0+$/, '')
  const [entero, fraccion = ''] = texto.split('.')

  return `${signo}$${entero}.${fraccion.padEnd(2, '0')}`
}

/**
 * "5h 32m", "3h", "50m" o "0m" a partir de segundos (nunca negativo).
 */
export function formatoDuracion(segundos) {
  const total = Math.max(0, Math.floor(Number(segundos) || 0))
  const horas = Math.floor(total / 3600)
  const minutos = Math.floor((total % 3600) / 60)

  if (horas === 0) {
    return `${minutos}m`
  }

  return minutos === 0 ? `${horas}h` : `${horas}h ${minutos}m`
}

/**
 * Texto del countdown de una posición abierta hasta el cierre automático por
 * max holding. `venceEn` es el timestamp calculado por el backend
 * (opened_at + horas máximas): acá solo se resta contra el reloj.
 * Al llegar a 0 el backend cierra la posición en la próxima evaluación (el
 * scheduler revisa cada vela), no en ese instante exacto.
 */
export function textoMaximoRestante(venceEn, ahoraMs = Date.now()) {
  if (!venceEn) {
    return ''
  }

  const segundos = Math.floor((new Date(venceEn).getTime() - ahoraMs) / 1000)

  return segundos <= 0
    ? 'Máximo alcanzado: cierre en la próxima revisión'
    : `Máximo restante: ${formatoDuracion(segundos)}`
}
