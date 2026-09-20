<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const API_BASE_URL = 'http://127.0.0.1:8000'

// Refresco periódico del centro de control: estado automático, actividad y
// operaciones. El bot corre en el scheduler de Laravel (fuera de esta
// pestaña), así que el panel necesita re-consultar para seguir "vivo".
const REFRESCO_MS = 15000

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

// Estado del centro de control: todo viene de GET /api/automatic/status, que
// es la fuente de verdad persistida en Laravel (App\Models\ActiveStrategy +
// Trade). Nada de esto se calcula ni se reinicia localmente en el frontend.
const estrategiaActiva = ref(null)
const cycleProfitLoss = ref(null)
const nextReview = ref(null)
const openPosition = ref(null)
const eventos = ref([])
const trades = ref([])

// idle | loading | error
const estadoAccionAutomatica = ref('idle')
const errorAccionAutomatica = ref('')

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
    <div class="w-full max-w-4xl grid grid-cols-1 gap-6">
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

      <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
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

      <section class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 text-center">
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
        <p class="text-sm text-neutral-500 mb-3 text-center">Actividad</p>
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

      <section class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6">
        <p class="text-sm text-neutral-500 mb-3 text-center">Buscar y aplicar una estrategia</p>

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
              <p class="font-medium">
                {{ resultado.selectedCandidate ? resultado.selectedCandidate.strategyName : 'No se seleccionó una estrategia.' }}
              </p>
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
                    <span :class="validationResult.passed ? 'text-emerald-400' : 'text-red-400'">
                      {{ validationResult.passed ? 'PASSED' : 'FAILED' }}
                    </span>
                  </p>
                  <p class="text-neutral-400 mt-1">
                    P/L: {{ formatoDinero(validationResult.validationEvaluation.profitLoss) }} ·
                    Win rate: {{ validationResult.validationEvaluation.winRate }}% ·
                    Drawdown: {{ validationResult.validationEvaluation.maxDrawdownPercentage }}% ·
                    Profit factor: {{ validationResult.validationEvaluation.profitFactor }}
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

      <section v-if="estrategiaActiva" class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6 flex flex-col gap-4">
        <p class="text-sm text-neutral-500 text-center">Control del modo automático</p>

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
          Iniciar automático
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
    </div>
  </main>
</template>
