<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const API_BASE_URL = 'http://127.0.0.1:8000'

// Refresco periódico del centro de control: estado automático, actividad y
// operaciones. El bot corre en el scheduler de Laravel (fuera de esta
// pestaña), así que el panel necesita re-consultar para seguir "vivo".
const REFRESCO_MS = 60000

// Parámetros de búsqueda fijos para esta primera versión: el único campo
// editable por el usuario es `capital` (ver `capitalATrabajar` más abajo).
// Fáciles de convertir en controles de UI más adelante.
const BUSQUEDA_CONFIG = {
  symbol: 'BTCUSDT',
  timeframe: '1h',
  from: '2026-08-01T00:00:00',
  to: '2026-09-01T00:00:00',
}

// Saldo de cuenta: viene exclusivamente de Binance Demo vía
// GET /api/binance/balance. Laravel es la única fuente de verdad; Vue solo
// lo muestra, nunca mantiene un saldo propio.
// idle | loading | success | error
const estadoSaldo = ref('idle')
const saldoBinance = ref(null)

// Capital a trabajar: lo que el usuario decide arriesgar en esta búsqueda,
// siempre como string (el backend trabaja en decimales de precisión con
// strings, no floats).
const capitalATrabajar = ref('500')

const modo = ref('Trial')

// idle | loading | success | error
const estadoBusqueda = ref('idle')
const errorBusqueda = ref('')
const resultado = ref(null)

// Modo de selección de estrategia: Manual (el usuario busca y aplica a
// mano) o Automático (el bot busca, selecciona y aplica solo, sin
// confirmación). No confundir con `modo` (Trial/Real), que es el modo de
// cuenta/capital y aplica a ambos por igual.
const modoSeleccion = ref('manual')

// Estado del centro de control: todo viene de GET /api/automatic/status, que
// es la fuente de verdad persistida en Laravel (App\Models\ActiveStrategy +
// Trade). Nada de esto se calcula ni se reinicia localmente en el frontend.
const estrategiaActiva = ref(null)
const cycleProfitLoss = ref(null)
const nextReview = ref(null)
const openPosition = ref(null)
const eventos = ref([])
const trades = ref([])
const automaticoSeleccion = ref(null)

// idle | loading | error
const estadoAccionAutomatica = ref('idle')
const errorAccionAutomatica = ref('')

// idle | loading | error
const estadoAccionAutomaticoSeleccion = ref('idle')
const errorAccionAutomaticoSeleccion = ref('')

// idle | loading | error
const estadoReseteoActividad = ref('idle')

// Reloj compartido para los contadores en vivo (próxima revisión, tiempo con
// la posición abierta): se recalculan solos, nunca se congelan al recargar.
const ahora = ref(Date.now())
let refrescoIntervalId = null

const minutosRestantes = computed(() => {
  if (!nextReview.value?.nextDueAt) {
    return null
  }

  const restanteMs = new Date(nextReview.value.nextDueAt).getTime() - ahora.value

  return Math.max(0, Math.ceil(restanteMs / 60000))
})

const textoRestante = computed(() => {
  if (minutosRestantes.value === null) {
    return ''
  }

  return minutosRestantes.value <= 0 ? 'Revisión pendiente' : `Faltan ${minutosRestantes.value} min`
})

const tiempoAbierta = computed(() => {
  if (!openPosition.value) {
    return ''
  }

  const totalMinutos = Math.max(0, Math.floor((ahora.value - new Date(openPosition.value.openedAt).getTime()) / 60000))
  const horas = Math.floor(totalMinutos / 60)
  const minutos = totalMinutos % 60

  return horas > 0 ? `${horas}h ${minutos}m` : `${minutos}m`
})

function formatoDinero(valor) {
  const numero = Number(valor)
  return Number.isFinite(numero) ? `$${numero.toFixed(2)}` : '—'
}

function formatoPL(valor) {
  const numero = Number(valor)
  if (!Number.isFinite(numero)) {
    return '—'
  }

  const signo = numero > 0 ? '+' : numero < 0 ? '−' : ''
  return `${signo}${Math.abs(numero).toFixed(2)} USDT`
}

// Porcentajes ya vienen en escala 0-100 desde el backend (StrategyEvaluator).
// `conSigno` antepone +/- para métricas que pueden ser negativas (P/L); el
// resto (win rate, drawdown) no lo necesita porque nunca son negativas.
function formatoPorcentaje(valor, conSigno = false) {
  const numero = Number(valor)
  if (!Number.isFinite(numero)) {
    return '—'
  }

  const texto = Math.abs(numero).toFixed(2)
  if (!conSigno) {
    return `${texto}%`
  }

  const signo = numero > 0 ? '+' : numero < 0 ? '−' : ''
  return `${signo}${texto}%`
}

// Profit factor es 'INF' (string) cuando no hay operaciones perdedoras.
function formatoProfitFactor(valor) {
  if (valor === 'INF') {
    return '∞'
  }

  const numero = Number(valor)
  return Number.isFinite(numero) ? numero.toFixed(2) : '—'
}

function formatoHora(iso) {
  if (!iso) {
    return '—'
  }

  const fecha = new Date(iso)
  return `${String(fecha.getHours()).padStart(2, '0')}:${String(fecha.getMinutes()).padStart(2, '0')}`
}

function formatoFecha(iso) {
  if (!iso) {
    return '—'
  }

  const fecha = new Date(iso)
  const dia = String(fecha.getDate()).padStart(2, '0')
  const mes = String(fecha.getMonth() + 1).padStart(2, '0')
  return `${dia}/${mes} ${formatoHora(iso)}`
}

function capitalizar(texto) {
  return texto ? texto.charAt(0).toUpperCase() + texto.slice(1) : ''
}

// Traduce el status de un activo del último ciclo (ver
// RunAutomaticSearchAction::reviewFor) a la etiqueta que ve el usuario.
// Puramente informativo: no es una señal BUY/SELL ni un juicio de
// rentabilidad, solo el resultado que ya calculó el Strategy Pipeline.
function textoEstadoActivo(activo) {
  if (activo.status === 'candidate_found') {
    return 'Candidata encontrada'
  }

  if (activo.status === 'discarded') {
    return activo.reason ? `Descartada (${activo.reason})` : 'Descartada'
  }

  return 'Sin oportunidad'
}

function claseEstadoActivo(status) {
  if (status === 'candidate_found') {
    return 'text-emerald-400'
  }

  if (status === 'discarded') {
    return 'text-red-400'
  }

  return 'text-neutral-400'
}

// Símbolo del activo actualmente expandido en "Mercado analizado (último
// ciclo)", para mostrar el detalle por estrategia (ver
// RunAutomaticSearchAction::strategyDiagnostics). null = nada expandido.
const activoExpandido = ref(null)

function alternarActivoExpandido(symbol) {
  activoExpandido.value = activoExpandido.value === symbol ? null : symbol
}

// Traduce el status de una estrategia dentro de un activo (ver
// RunAutomaticSearchAction::strategyStatus) a icono + etiqueta corta.
// Puramente informativo: no es una señal BUY/SELL.
function textoEstadoEstrategia(status) {
  switch (status) {
    case 'selected':
      return { icono: '✅', texto: 'Seleccionada' }
    case 'validated_not_selected':
      return { icono: '➖', texto: 'Validada, sin ganador claro' }
    case 'discarded_in_validation':
      return { icono: '❌', texto: 'Descartada en Validation' }
    default:
      return { icono: '❌', texto: 'Descartada en Discovery' }
  }
}

// Etiqueta corta y compacta con el motivo/métrica más relevante para
// explicar el resultado de una estrategia, reutilizando exclusivamente las
// métricas que ya trae `strategy.discovery.metrics` / `strategy.validation.metrics`
// (ver StrategyEvaluation). No recalcula nada.
function metricaClaveEstrategia(strategy) {
  const etapa = strategy.validation ?? strategy.discovery
  const metricas = etapa.metrics

  const criterio = etapa.failedCriteria[0]

  switch (criterio) {
    case 'minimumTrades':
      return `${metricas.totalTrades} trade(s)`
    case 'minimumWinRate':
      return `win rate ${formatoPorcentaje(metricas.winRate)}`
    case 'maximumDrawdown':
      return `drawdown ${formatoPorcentaje(metricas.maxDrawdownPercentage)}`
    case 'minimumProfitLoss':
      return `P/L ${formatoPL(metricas.profitLoss)}`
    default:
      return `win rate ${formatoPorcentaje(metricas.winRate)} · P/L ${formatoPL(metricas.profitLoss)}`
  }
}

async function cargarSaldoBinance() {
  estadoSaldo.value = 'loading'

  try {
    const response = await fetch(`${API_BASE_URL}/api/binance/balance`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      throw new Error('request-failed')
    }

    const datos = await response.json()
    const usdt = (datos.balances ?? []).find((balance) => balance.asset === 'USDT')

    saldoBinance.value = usdt ? usdt.free : '0'
    estadoSaldo.value = 'success'
  } catch {
    saldoBinance.value = null
    estadoSaldo.value = 'error'
  }
}

async function buscarEstrategia() {
  if (estadoBusqueda.value === 'loading' || !puedeBuscar()) {
    return
  }

  estadoBusqueda.value = 'loading'
  errorBusqueda.value = ''
  resultado.value = null

  try {
    const response = await fetch(`${API_BASE_URL}/api/strategies/search`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        ...BUSQUEDA_CONFIG,
        capital: capitalATrabajar.value,
        mode: modo.value.toLowerCase(),
      }),
    })

    if (!response.ok) {
      const datos = await response.json().catch(() => null)
      throw new Error(datos?.errors?.capital?.[0] ?? datos?.errors?.mode?.[0] ?? 'request-failed')
    }

    resultado.value = await response.json()
    estadoBusqueda.value = 'success'
  } catch (error) {
    estadoBusqueda.value = 'error'
    errorBusqueda.value = error.message !== 'request-failed' ? error.message : 'No se pudo buscar la estrategia.'
  }
}

// "Seleccionada" es la que StrategySelector eligió (resultado.selectedCandidate);
// no debe confundirse con "Validada" (pasó VALIDATION pero no fue la elegida).
function esSeleccionada(strategyName) {
  return resultado.value?.selectedCandidate?.strategyName === strategyName
}

// Validación de UX: Laravel es la autoridad final (ver
// SearchStrategiesRequest), esto solo evita una llamada innecesaria y da
// feedback inmediato.
function puedeBuscar() {
  if (modo.value === 'Real') {
    return false
  }

  if (estadoSaldo.value !== 'success') {
    return false
  }

  return Number(capitalATrabajar.value) <= Number(saldoBinance.value)
}

async function cargarEstadoAutomatico() {
  try {
    const response = await fetch(`${API_BASE_URL}/api/automatic/status`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      return
    }

    const datos = await response.json()
    estrategiaActiva.value = datos.activeStrategy
    cycleProfitLoss.value = datos.cycleProfitLoss
    nextReview.value = datos.nextReview
    openPosition.value = datos.openPosition
    automaticoSeleccion.value = datos.automaticSearch

    // Si el modo automático ya está corriendo en el backend (por ejemplo
    // tras recargar la página), reflejarlo en la UI sin que el usuario
    // tenga que volver a tocar el selector.
    if (datos.automaticSearch?.status === 'running') {
      modoSeleccion.value = 'automatico'
    }
  } catch {
    // El estado se reintenta en el próximo refresco; no bloquea el resto de la UI.
  }
}

async function cargarEventos() {
  try {
    const response = await fetch(`${API_BASE_URL}/api/automatic/events`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      return
    }

    eventos.value = (await response.json()).events
  } catch {
    // idem cargarEstadoAutomatico
  }
}

// Solo borra App\Models\BotEvent (el log de Activity); no toca trades,
// estrategias ni el estado del modo automático — ver ResetActivityAction.
async function resetearActividad() {
  if (estadoReseteoActividad.value === 'loading') {
    return
  }

  if (!window.confirm('¿Seguro que quieres borrar toda la actividad?')) {
    return
  }

  estadoReseteoActividad.value = 'loading'

  try {
    const response = await fetch(`${API_BASE_URL}/api/automatic/events`, {
      method: 'DELETE',
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      throw new Error('request-failed')
    }

    estadoReseteoActividad.value = 'idle'
    await cargarEventos()
  } catch {
    estadoReseteoActividad.value = 'error'
  }
}

async function cargarTrades() {
  try {
    const response = await fetch(`${API_BASE_URL}/api/automatic/trades`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      return
    }

    trades.value = (await response.json()).trades
  } catch {
    // idem cargarEstadoAutomatico
  }
}

function refrescarCentroDeControl() {
  ahora.value = Date.now()
  cargarEstadoAutomatico()
  cargarEventos()
  cargarTrades()
}

async function aplicarEstrategia() {
  if (!resultado.value?.selectedCandidate) {
    return
  }

  estadoAccionAutomatica.value = 'loading'
  errorAccionAutomatica.value = ''

  try {
    const response = await fetch(`${API_BASE_URL}/api/strategies/activate`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        strategy_name: resultado.value.selectedCandidate.strategyName,
        symbol: BUSQUEDA_CONFIG.symbol,
        timeframe: BUSQUEDA_CONFIG.timeframe,
        capital: capitalATrabajar.value,
        mode: modo.value.toLowerCase(),
      }),
    })

    const datos = await response.json().catch(() => null)

    if (!response.ok) {
      throw new Error(datos?.errors?.capital?.[0] ?? datos?.errors?.strategy_name?.[0] ?? datos?.message ?? 'request-failed')
    }

    estadoAccionAutomatica.value = 'idle'
    refrescarCentroDeControl()
  } catch (error) {
    estadoAccionAutomatica.value = 'error'
    errorAccionAutomatica.value = error.message !== 'request-failed' ? error.message : 'No se pudo aplicar la estrategia.'
  }
}

async function iniciarAutomatico() {
  await ejecutarAccionAutomatica('/api/automatic/start')
}

async function detenerAutomatico() {
  await ejecutarAccionAutomatica('/api/automatic/stop')
}

async function ejecutarAccionAutomatica(ruta) {
  estadoAccionAutomatica.value = 'loading'
  errorAccionAutomatica.value = ''

  try {
    const response = await fetch(`${API_BASE_URL}${ruta}`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    })

    const datos = await response.json().catch(() => null)

    if (!response.ok) {
      throw new Error(datos?.message ?? 'request-failed')
    }

    estadoAccionAutomatica.value = 'idle'
    refrescarCentroDeControl()
  } catch (error) {
    estadoAccionAutomatica.value = 'error'
    errorAccionAutomatica.value = error.message !== 'request-failed' ? error.message : 'No se pudo completar la acción.'
  }
}

// "Iniciar automático" en Modo Automático: a diferencia de Manual, esto
// dispara todo el ciclo Buscar -> Seleccionar -> Aplicar sin pasos
// intermedios (ver StartAutomaticSearchModeController). No requiere que ya
// exista una estrategia aplicada.
async function iniciarAutomaticoSeleccion() {
  if (!puedeBuscar()) {
    return
  }

  estadoAccionAutomaticoSeleccion.value = 'loading'
  errorAccionAutomaticoSeleccion.value = ''

  try {
    const response = await fetch(`${API_BASE_URL}/api/automatic-search/start`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        ...BUSQUEDA_CONFIG,
        capital: capitalATrabajar.value,
        mode: modo.value.toLowerCase(),
      }),
    })

    const datos = await response.json().catch(() => null)

    if (!response.ok) {
      throw new Error(datos?.errors?.capital?.[0] ?? datos?.errors?.mode?.[0] ?? datos?.message ?? 'request-failed')
    }

    estadoAccionAutomaticoSeleccion.value = 'idle'
    refrescarCentroDeControl()
  } catch (error) {
    estadoAccionAutomaticoSeleccion.value = 'error'
    errorAccionAutomaticoSeleccion.value = error.message !== 'request-failed' ? error.message : 'No se pudo iniciar el modo automático.'
  }
}

async function detenerAutomaticoSeleccion() {
  estadoAccionAutomaticoSeleccion.value = 'loading'
  errorAccionAutomaticoSeleccion.value = ''

  try {
    const response = await fetch(`${API_BASE_URL}/api/automatic-search/stop`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    })

    const datos = await response.json().catch(() => null)

    if (!response.ok) {
      throw new Error(datos?.message ?? 'request-failed')
    }

    estadoAccionAutomaticoSeleccion.value = 'idle'
    refrescarCentroDeControl()
  } catch (error) {
    estadoAccionAutomaticoSeleccion.value = 'error'
    errorAccionAutomaticoSeleccion.value = error.message !== 'request-failed' ? error.message : 'No se pudo detener el modo automático.'
  }
}

onMounted(() => {
  cargarSaldoBinance()
  refrescarCentroDeControl()
  refrescoIntervalId = setInterval(refrescarCentroDeControl, REFRESCO_MS)
})

onUnmounted(() => {
  clearInterval(refrescoIntervalId)
})
</script>

<template>
  <main class="min-h-svh bg-neutral-950 text-neutral-100 flex justify-center px-4 py-10">
    <div class="w-full max-w-6xl flex flex-col gap-6">
      <header class="text-center">
        <h1 class="text-2xl font-semibold">Trading Bot</h1>
        <p class="text-sm text-neutral-500 mt-1">Centro de control — Binance Demo</p>

        <div class="mt-4 flex flex-col items-center gap-1">
          <p
            class="text-lg font-semibold"
            :class="estrategiaActiva?.status === 'running' ? 'text-emerald-400' : 'text-neutral-400'"
          >
            🤖 {{ estrategiaActiva?.status === 'running' ? 'Ejecutando' : 'Detenido' }}
          </p>
          <p class="text-sm text-neutral-500">Modo: {{ estrategiaActiva ? capitalizar(estrategiaActiva.mode) : modo }}</p>
          <template v-if="estrategiaActiva">
            <p class="font-medium">{{ estrategiaActiva.strategy?.name }}</p>
            <p class="text-sm text-neutral-400">{{ estrategiaActiva.symbol }} · {{ estrategiaActiva.timeframe }}</p>
          </template>
          <p v-else class="text-sm text-neutral-500">Ninguna estrategia aplicada todavía.</p>
        </div>
      </header>

      <section class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-4">
        <p class="text-sm text-neutral-500 mb-3 text-center">Selección de estrategia</p>
        <div class="grid grid-cols-2 gap-3 max-w-md mx-auto">
          <button
            type="button"
            class="rounded-xl py-3 font-medium transition-colors"
            :class="modoSeleccion === 'manual' ? 'bg-neutral-100 text-neutral-900' : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700'"
            @click="modoSeleccion = 'manual'"
          >
            Manual
          </button>
          <button
            type="button"
            class="rounded-xl py-3 font-medium transition-colors"
            :class="modoSeleccion === 'automatico' ? 'bg-neutral-100 text-neutral-900' : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700'"
            @click="modoSeleccion = 'automatico'"
          >
            Automático
          </button>
        </div>
      </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <section class="lg:col-span-2 grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 text-center">
          <p class="text-sm text-neutral-500 mb-2">Saldo Binance Demo</p>
          <p v-if="estadoSaldo === 'loading'" class="text-sm text-neutral-400">Consultando...</p>
          <p v-else-if="estadoSaldo === 'error'" class="text-sm text-red-400">No se pudo consultar.</p>
          <p v-else class="text-2xl font-semibold tabular-nums">{{ formatoDinero(saldoBinance) }}</p>
        </div>

        <div class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 text-center">
          <p class="text-sm text-neutral-500 mb-2">Capital autorizado</p>
          <p v-if="estrategiaActiva" class="text-2xl font-semibold tabular-nums">{{ formatoDinero(estrategiaActiva.capital) }}</p>
          <p v-else class="text-sm text-neutral-500">Sin estrategia aplicada</p>
        </div>

        <div class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 text-center">
          <p class="text-sm text-neutral-500 mb-2">Próxima revisión</p>
          <p v-if="!estrategiaActiva" class="text-sm text-neutral-500">Sin estrategia aplicada</p>
          <p v-else-if="!nextReview?.nextDueAt" class="text-sm text-neutral-500">
            Esperando la primera vela: el bot todavía no evaluó ninguna.
          </p>
          <template v-else>
            <p class="text-xs text-neutral-500">Última: {{ formatoHora(nextReview.lastEvaluatedAt) }}</p>
            <p class="text-xs text-neutral-500">Próxima: {{ formatoHora(nextReview.nextDueAt) }}</p>
            <p class="font-semibold mt-1">⏳ {{ textoRestante }}</p>
          </template>
        </div>

        <div class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 text-center">
          <p class="text-sm text-neutral-500 mb-2">P/L acumulado del ciclo</p>
          <p v-if="cycleProfitLoss === null" class="text-sm text-neutral-500">Sin ciclo activo</p>
          <p
            v-else
            class="text-2xl font-semibold tabular-nums"
            :class="Number(cycleProfitLoss) > 0 ? 'text-emerald-400' : Number(cycleProfitLoss) < 0 ? 'text-red-400' : 'text-neutral-100'"
          >
            {{ formatoPL(cycleProfitLoss) }}
          </p>
        </div>
      </section>

      <section class="lg:col-span-2 rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 text-center">
        <template v-if="openPosition">
          <p class="text-sm text-neutral-500 mb-3">📈 Posición abierta — {{ openPosition.symbol }}</p>
          <div class="grid grid-cols-3 gap-4">
            <div>
              <p class="text-xs text-neutral-500">Precio entrada</p>
              <p class="font-medium tabular-nums">{{ formatoDinero(openPosition.entryPrice) }}</p>
            </div>
            <div>
              <p class="text-xs text-neutral-500">Capital usado</p>
              <p class="font-medium tabular-nums">{{ formatoDinero(openPosition.capitalUsed) }}</p>
            </div>
            <div>
              <p class="text-xs text-neutral-500">Tiempo abierta</p>
              <p class="font-medium tabular-nums">{{ tiempoAbierta }}</p>
            </div>
          </div>
        </template>
        <p v-else class="text-sm text-neutral-500">Sin posición abierta</p>
      </section>

      <section class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6">
        <div class="flex items-center justify-between mb-3">
          <p class="text-sm text-neutral-500">Actividad</p>
          <button
            type="button"
            class="text-xs text-neutral-500 hover:text-red-400 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="estadoReseteoActividad === 'loading'"
            @click="resetearActividad"
          >
            Resetear actividad
          </button>
        </div>
        <p v-if="estadoReseteoActividad === 'error'" class="text-center text-xs text-red-400 mb-2">
          No se pudo borrar la actividad.
        </p>
        <p v-if="eventos.length === 0" class="text-center text-sm text-neutral-500">Sin eventos todavía.</p>
        <ul v-else class="flex flex-col gap-1 font-mono text-sm max-h-64 overflow-y-auto">
          <li v-for="evento in eventos" :key="evento.id" class="text-neutral-300">
            <span class="text-neutral-500">{{ formatoHora(evento.created_at) }}</span> {{ evento.message }}
          </li>
        </ul>
      </section>

      <section class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6">
        <p class="text-sm text-neutral-500 mb-3 text-center">Historial de operaciones</p>
        <p v-if="trades.length === 0" class="text-center text-sm text-neutral-500">Sin operaciones todavía.</p>
        <ul v-else class="flex flex-col gap-2">
          <li v-for="trade in trades" :key="trade.id" class="rounded-xl bg-neutral-800/60 p-3 text-sm flex flex-col gap-2">
            <div class="flex items-center justify-between">
              <span class="font-medium text-emerald-400">BUY {{ trade.asset?.symbol }}</span>
              <span class="text-neutral-400">{{ formatoDinero(trade.entry_price) }} · {{ formatoFecha(trade.opened_at) }}</span>
            </div>
            <template v-if="trade.status === 'closed'">
              <div class="flex items-center justify-between">
                <span class="font-medium text-red-400">SELL {{ trade.asset?.symbol }}</span>
                <span class="text-neutral-400">{{ formatoDinero(trade.exit_price) }} · {{ formatoFecha(trade.closed_at) }}</span>
              </div>
              <div class="flex items-center justify-between border-t border-neutral-700/60 pt-2">
                <span class="text-neutral-500">Resultado</span>
                <span class="font-semibold" :class="Number(trade.profit_loss) >= 0 ? 'text-emerald-400' : 'text-red-400'">
                  {{ formatoPL(trade.profit_loss) }}
                </span>
              </div>
            </template>
            <p v-else class="text-xs text-neutral-500">Posición abierta</p>
          </li>
        </ul>
      </section>

      <section class="lg:col-span-2 rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6">
        <p class="text-sm text-neutral-500 mb-3 text-center">Capital y modo de cuenta</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="rounded-xl bg-neutral-800/40 p-4">
            <label for="capital" class="text-sm text-neutral-500 mb-3 block text-center">Capital a trabajar</label>
            <div class="flex items-center justify-center gap-2">
              <span class="text-neutral-500">$</span>
              <input
                id="capital"
                v-model="capitalATrabajar"
                type="number"
                min="0"
                step="0.01"
                class="w-32 rounded-xl bg-neutral-800 text-neutral-100 text-center py-2 outline-none focus:ring-2 focus:ring-emerald-500"
              />
            </div>
          </div>

          <div class="rounded-xl bg-neutral-800/40 p-4">
            <p class="text-sm text-neutral-500 mb-3 text-center">Modo</p>
            <div class="grid grid-cols-2 gap-3">
              <button
                type="button"
                class="rounded-xl py-3 font-medium transition-colors"
                :class="modo === 'Trial' ? 'bg-neutral-100 text-neutral-900' : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700'"
                @click="modo = 'Trial'"
              >
                🧪 Trial
              </button>
              <button
                type="button"
                class="rounded-xl py-3 font-medium transition-colors"
                :class="modo === 'Real' ? 'bg-neutral-100 text-neutral-900' : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700'"
                @click="modo = 'Real'"
              >
                Real — Próximamente
              </button>
            </div>
          </div>
        </div>

        <p v-if="modo === 'Real'" class="text-sm text-neutral-500 text-center mt-4">
          El modo Real todavía no está disponible.
        </p>
        <p
          v-else-if="estadoSaldo === 'success' && Number(capitalATrabajar) > Number(saldoBinance)"
          class="text-sm text-red-400 text-center mt-4"
        >
          El capital a trabajar supera el saldo disponible en Binance Demo.
        </p>
      </section>

      <section v-if="modoSeleccion === 'manual'" class="lg:col-span-2 rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6">
        <p class="text-sm text-neutral-500 mb-3 text-center">Modo: Manual</p>

        <button
          type="button"
          class="w-full rounded-xl bg-emerald-500 text-neutral-950 font-semibold py-4 text-lg hover:bg-emerald-400 transition-colors disabled:opacity-60 disabled:cursor-not-allowed mt-4"
          :disabled="estadoBusqueda === 'loading' || !puedeBuscar()"
          @click="buscarEstrategia"
        >
          {{ estadoBusqueda === 'loading' ? 'Buscando...' : 'Buscar estrategia' }}
        </button>

        <div class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 mt-4">
          <p class="text-sm text-neutral-500 mb-3 text-center">Resultado</p>

          <p v-if="estadoBusqueda === 'idle'" class="text-center text-neutral-400">
            Aún no se ha realizado una búsqueda.
          </p>

          <p v-else-if="estadoBusqueda === 'loading'" class="text-center text-neutral-400">
            Buscando...
          </p>

          <p v-else-if="estadoBusqueda === 'error'" class="text-center text-red-400">
            {{ errorBusqueda }}
          </p>

          <div v-else-if="estadoBusqueda === 'success'" class="flex flex-col gap-4">
            <div class="text-center">
              <p class="text-sm text-neutral-500">Estrategia seleccionada</p>
              <p v-if="resultado.selectedCandidate" class="font-medium">{{ resultado.selectedCandidate.strategyName }}</p>
              <template v-else>
                <p class="font-medium">No se seleccionó una estrategia.</p>
                <p class="text-sm text-neutral-400 mt-2">
                  Varias estrategias pueden haber superado la validación,
                  pero ninguna cumplió las condiciones para ser seleccionada
                  de forma única.
                </p>
              </template>
            </div>

            <div v-if="resultado.validationResults.length" class="text-center">
              <p class="text-sm text-neutral-500">Capital evaluado</p>
              <p class="font-medium">{{ formatoDinero(resultado.validationResults[0].validationEvaluation.initialCapital) }}</p>
            </div>

            <div>
              <p class="text-sm text-neutral-500 mb-2 text-center">Resultados de validación</p>
              <ul class="flex flex-col gap-2">
                <li
                  v-for="validationResult in resultado.validationResults"
                  :key="validationResult.candidate.strategyName"
                  class="rounded-xl bg-neutral-800/60 p-3 text-sm"
                >
                  <p class="font-medium flex items-center justify-between">
                    <span>{{ validationResult.candidate.strategyName }}</span>
                    <span
                      :class="
                        esSeleccionada(validationResult.candidate.strategyName)
                          ? 'text-emerald-400'
                          : validationResult.passed
                            ? 'text-sky-400'
                            : 'text-red-400'
                      "
                    >
                      {{ esSeleccionada(validationResult.candidate.strategyName) ? 'Seleccionada' : validationResult.passed ? 'Validada' : 'No validada' }}
                    </span>
                  </p>
                  <p class="text-neutral-400 mt-1">
                    P/L: {{ formatoPorcentaje(validationResult.validationEvaluation.profitLossPercentage, true) }} ·
                    Win rate: {{ formatoPorcentaje(validationResult.validationEvaluation.winRate) }} ·
                    Drawdown: {{ formatoPorcentaje(validationResult.validationEvaluation.maxDrawdownPercentage) }} ·
                    Profit factor: {{ formatoProfitFactor(validationResult.validationEvaluation.profitFactor) }}
                  </p>
                </li>
              </ul>
            </div>

            <button
              v-if="resultado.selectedCandidate"
              type="button"
              class="w-full rounded-xl bg-neutral-100 text-neutral-900 font-semibold py-3 hover:bg-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
              :disabled="estadoAccionAutomatica === 'loading'"
              @click="aplicarEstrategia"
            >
              {{ estadoAccionAutomatica === 'loading' ? 'Aplicando...' : 'Aplicar' }}
            </button>
          </div>
        </div>
      </section>

      <section v-if="modoSeleccion === 'manual' && estrategiaActiva" class="lg:col-span-2 rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 flex flex-col gap-4">
        <p class="text-sm text-neutral-500 text-center">Control de ejecución</p>

        <p v-if="estadoAccionAutomatica === 'error'" class="text-sm text-red-400 text-center">
          {{ errorAccionAutomatica }}
        </p>

        <button
          v-if="estrategiaActiva.status !== 'running'"
          type="button"
          class="w-full rounded-xl bg-emerald-500 text-neutral-950 font-semibold py-3 hover:bg-emerald-400 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
          :disabled="estadoAccionAutomatica === 'loading'"
          @click="iniciarAutomatico"
        >
          Iniciar ejecución
        </button>
        <button
          v-else
          type="button"
          class="w-full rounded-xl bg-red-500 text-neutral-950 font-semibold py-3 hover:bg-red-400 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
          :disabled="estadoAccionAutomatica === 'loading'"
          @click="detenerAutomatico"
        >
          Detener
        </button>
      </section>

      <section v-if="modoSeleccion === 'automatico'" class="lg:col-span-2 rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 flex flex-col gap-4">
        <p class="text-sm text-neutral-500 text-center">Modo: Automático</p>

        <template v-if="automaticoSeleccion?.status === 'running' || estrategiaActiva?.status === 'running'">
          <p class="text-lg font-semibold text-emerald-400 text-center">🤖 Bot automático</p>
          <p class="text-sm text-neutral-500 text-center">Estado: Ejecutando</p>

          <template v-if="estrategiaActiva?.status === 'running'">
            <p class="text-center mt-2">
              Estrategia seleccionada automáticamente:
              <span class="font-medium block">{{ estrategiaActiva.strategy?.name }}</span>
            </p>
            <p class="text-sm text-neutral-500 text-center">No requiere confirmación.</p>
          </template>
          <template v-else>
            <p class="text-center text-neutral-400 mt-2">
              No se encontró una estrategia seleccionable.
            </p>
            <p class="text-sm text-neutral-500 text-center">
              El bot volverá a buscar automáticamente en el próximo ciclo.
            </p>
            <p v-if="automaticoSeleccion?.nextSearchAt" class="text-sm text-neutral-500 text-center">
              Próxima búsqueda: {{ formatoHora(automaticoSeleccion.nextSearchAt) }}
            </p>
          </template>

          <div v-if="automaticoSeleccion?.lastCycle" class="rounded-xl bg-neutral-800/40 p-4 mt-2">
            <p class="text-sm text-neutral-500 mb-3 text-center">Mercado analizado (último ciclo)</p>

            <p v-if="automaticoSeleccion.lastCycle.assets.length === 0" class="text-center text-sm text-neutral-500">
              Sin activos revisados en el último ciclo.
            </p>
            <ul v-else class="flex flex-col gap-2 text-sm">
              <li
                v-for="activo in automaticoSeleccion.lastCycle.assets"
                :key="activo.symbol"
                class="rounded-lg bg-neutral-900/60 px-3 py-2"
              >
                <button
                  type="button"
                  class="flex w-full items-center justify-between text-left"
                  :disabled="!activo.strategies?.length"
                  @click="alternarActivoExpandido(activo.symbol)"
                >
                  <span class="font-medium">
                    <span v-if="activo.strategies?.length" class="text-neutral-500 mr-1">
                      {{ activoExpandido === activo.symbol ? '▾' : '▸' }}
                    </span>
                    {{ activo.symbol }}
                  </span>
                  <span class="text-right">
                    <span :class="claseEstadoActivo(activo.status)">{{ textoEstadoActivo(activo) }}</span>
                    <span class="block text-xs text-neutral-500">{{ formatoHora(activo.evaluatedAt) }}</span>
                  </span>
                </button>

                <ul
                  v-if="activoExpandido === activo.symbol && activo.strategies?.length"
                  class="mt-2 flex flex-col gap-1 border-t border-neutral-800 pt-2"
                >
                  <li
                    v-for="estrategia in activo.strategies"
                    :key="estrategia.name"
                    class="flex items-center justify-between text-xs"
                  >
                    <span class="text-neutral-300">{{ estrategia.name }}</span>
                    <span class="text-neutral-400">
                      {{ textoEstadoEstrategia(estrategia.status).icono }}
                      {{ textoEstadoEstrategia(estrategia.status).texto }}
                      · {{ metricaClaveEstrategia(estrategia) }}
                    </span>
                  </li>
                </ul>
              </li>
            </ul>

            <div class="grid grid-cols-2 gap-3 mt-4 text-center">
              <div>
                <p class="text-xs text-neutral-500">Activos revisados</p>
                <p class="font-semibold tabular-nums">{{ automaticoSeleccion.lastCycle.assetsReviewed }}</p>
              </div>
              <div>
                <p class="text-xs text-neutral-500">Candidatos encontrados</p>
                <p class="font-semibold tabular-nums">{{ automaticoSeleccion.lastCycle.candidatesFound }}</p>
              </div>
            </div>
          </div>

          <p v-if="estadoAccionAutomaticoSeleccion === 'error'" class="text-sm text-red-400 text-center">
            {{ errorAccionAutomaticoSeleccion }}
          </p>

          <button
            type="button"
            class="w-full rounded-xl bg-red-500 text-neutral-950 font-semibold py-3 hover:bg-red-400 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="estadoAccionAutomaticoSeleccion === 'loading'"
            @click="detenerAutomaticoSeleccion"
          >
            Detener
          </button>
        </template>

        <template v-else>
          <p v-if="modo === 'Real'" class="text-sm text-neutral-500 text-center">
            El modo Real todavía no está disponible.
          </p>
          <p
            v-else-if="estadoSaldo === 'success' && Number(capitalATrabajar) > Number(saldoBinance)"
            class="text-sm text-red-400 text-center"
          >
            El capital a trabajar supera el saldo disponible en Binance Demo.
          </p>
          <p v-if="estadoAccionAutomaticoSeleccion === 'error'" class="text-sm text-red-400 text-center">
            {{ errorAccionAutomaticoSeleccion }}
          </p>

          <button
            type="button"
            class="w-full rounded-xl bg-emerald-500 text-neutral-950 font-semibold py-4 text-lg hover:bg-emerald-400 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="estadoAccionAutomaticoSeleccion === 'loading' || !puedeBuscar()"
            @click="iniciarAutomaticoSeleccion"
          >
            {{ estadoAccionAutomaticoSeleccion === 'loading' ? 'Buscando estrategia...' : 'Iniciar automático' }}
          </button>
        </template>
      </section>
    </div>
    </div>
  </main>
</template>
