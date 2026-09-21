<script setup>
import { onMounted, onUnmounted, reactive, ref } from 'vue'

const API_BASE_URL = 'http://127.0.0.1:8000'

// Más frecuente que el refresco general del centro de control (60s): el
// tiempo restante y el estado de cada ciclo cambian minuto a minuto, y este
// panel es justamente lo que se supone que se sienta "vivo".
const REFRESCO_MS = 15000

// Fase 4B: este panel consume únicamente los endpoints de Fase 4A
// (GET /api/cycles, GET /api/cycles/summary, GET /api/cycles/{id},
// POST /api/cycles/{id}/stop, POST /api/cycles/stop-all). Nunca recalcula
// estado ni tiempo restante — todo viene ya calculado del Backend.
const resumen = ref(null)
const ciclos = ref([])

// idle | loading | success | error
const estadoCiclos = ref('idle')

// id de ciclo -> array de eventos {at, type, message}, cacheado tras la
// primera carga de su timeline. id de ciclo -> 'idle' | 'loading' | 'error'.
const historialPorCiclo = reactive({})
const estadoHistorial = reactive({})
const cicloExpandido = ref(null)

// id de ciclo -> 'idle' | 'loading' | 'error'
const estadoDetener = reactive({})

// idle | loading | error
const estadoDetenerTodos = ref('idle')

let refrescoIntervalId = null

const ETIQUETAS_EVENTO = {
  cycle_created: 'Ciclo creado',
  position_opened: 'BUY',
  position_closed: 'SELL',
  cycle_closed: 'Ciclo cerrado',
  cycle_expired: 'Ciclo expirado',
  cycle_stopped: 'Detenido manualmente',
}

const ETIQUETAS_ESTADO = {
  HOLD: 'HOLD',
  POSITION_OPEN: 'Posición abierta',
  CLOSED: 'Cerrado',
  EXPIRED: 'Expirado',
}

function claseEstado(status) {
  switch (status) {
    case 'HOLD':
      return 'text-amber-400'
    case 'POSITION_OPEN':
      return 'text-emerald-400'
    case 'EXPIRED':
      return 'text-red-400'
    default:
      return 'text-neutral-500'
  }
}

function etiquetaEstado(status) {
  return ETIQUETAS_ESTADO[status] ?? status
}

function etiquetaEvento(evento) {
  return ETIQUETAS_EVENTO[evento.type] ?? evento.message
}

// Un ciclo solo puede detenerse manualmente mientras sigue en HOLD o
// POSITION_OPEN (ver StopTradingCycleAction) — CLOSED/EXPIRED son
// terminales. El botón simplemente no se muestra para esos, en vez de
// mostrarlo deshabilitado con un error después del click.
function esDetenible(ciclo) {
  return ciclo.status === 'HOLD' || ciclo.status === 'POSITION_OPEN'
}

function hayCiclosActivos() {
  return ciclos.value.some(esDetenible)
}

function formatoHora(iso) {
  if (!iso) {
    return '—'
  }

  const fecha = new Date(iso)
  return `${String(fecha.getHours()).padStart(2, '0')}:${String(fecha.getMinutes()).padStart(2, '0')}`
}

function formatoPL(valor) {
  const numero = Number(valor)
  if (!Number.isFinite(numero)) {
    return '—'
  }

  const signo = numero > 0 ? '+' : numero < 0 ? '−' : ''
  return `${signo}${Math.abs(numero).toFixed(2)} USDT`
}

async function cargarResumen() {
  try {
    const response = await fetch(`${API_BASE_URL}/api/cycles/summary`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      return
    }

    resumen.value = await response.json()
  } catch {
    // El resumen se reintenta en el próximo refresco.
  }
}

async function cargarCiclos() {
  if (estadoCiclos.value !== 'success') {
    estadoCiclos.value = 'loading'
  }

  try {
    const response = await fetch(`${API_BASE_URL}/api/cycles`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      throw new Error('request-failed')
    }

    ciclos.value = (await response.json()).cycles
    estadoCiclos.value = 'success'
  } catch {
    estadoCiclos.value = 'error'
  }
}

function refrescar() {
  cargarResumen()
  cargarCiclos()
}

async function alternarTimeline(cicloId) {
  if (cicloExpandido.value === cicloId) {
    cicloExpandido.value = null
    return
  }

  cicloExpandido.value = cicloId

  if (historialPorCiclo[cicloId]) {
    return
  }

  estadoHistorial[cicloId] = 'loading'

  try {
    const response = await fetch(`${API_BASE_URL}/api/cycles/${cicloId}`, {
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      throw new Error('request-failed')
    }

    historialPorCiclo[cicloId] = (await response.json()).history
    estadoHistorial[cicloId] = 'success'
  } catch {
    estadoHistorial[cicloId] = 'error'
  }
}

async function detenerCiclo(cicloId) {
  if (estadoDetener[cicloId] === 'loading') {
    return
  }

  if (!window.confirm('¿Detener este ciclo?')) {
    return
  }

  estadoDetener[cicloId] = 'loading'

  try {
    const response = await fetch(`${API_BASE_URL}/api/cycles/${cicloId}/stop`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      throw new Error('request-failed')
    }

    estadoDetener[cicloId] = 'idle'
    delete historialPorCiclo[cicloId]
    refrescar()
  } catch {
    estadoDetener[cicloId] = 'error'
  }
}

async function detenerTodos() {
  if (estadoDetenerTodos.value === 'loading') {
    return
  }

  if (!window.confirm('¿Detener todos los ciclos activos?')) {
    return
  }

  estadoDetenerTodos.value = 'loading'

  try {
    const response = await fetch(`${API_BASE_URL}/api/cycles/stop-all`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
    })

    if (!response.ok) {
      throw new Error('request-failed')
    }

    estadoDetenerTodos.value = 'idle'
    refrescar()
  } catch {
    estadoDetenerTodos.value = 'error'
  }
}

onMounted(() => {
  refrescar()
  refrescoIntervalId = setInterval(refrescar, REFRESCO_MS)
})

onUnmounted(() => {
  clearInterval(refrescoIntervalId)
})
</script>

<template>
  <section class="rounded-2xl border border-neutral-800 bg-neutral-900/50 p-6">
    <div class="flex items-center justify-between mb-4">
      <p class="text-sm text-neutral-500">Ciclos de trading</p>
      <button
        type="button"
        class="text-xs text-red-400 hover:text-red-300 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
        :disabled="estadoDetenerTodos === 'loading' || !hayCiclosActivos()"
        @click="detenerTodos"
      >
        {{ estadoDetenerTodos === 'loading' ? 'Deteniendo...' : 'Detener todos' }}
      </button>
    </div>

    <p v-if="estadoDetenerTodos === 'error'" class="text-xs text-red-400 text-center mb-3">
      No se pudo detener todos los ciclos.
    </p>

    <div v-if="resumen" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
      <div class="rounded-xl bg-neutral-800/40 p-3 text-center">
        <p class="text-xs text-neutral-500">Ciclos activos</p>
        <p class="font-semibold tabular-nums">{{ resumen.active_cycles }}</p>
      </div>
      <div class="rounded-xl bg-neutral-800/40 p-3 text-center">
        <p class="text-xs text-neutral-500">Slots libres</p>
        <p class="font-semibold tabular-nums">{{ resumen.free_slots }}</p>
      </div>
      <div class="rounded-xl bg-neutral-800/40 p-3 text-center">
        <p class="text-xs text-neutral-500">Compras abiertas</p>
        <p class="font-semibold tabular-nums">{{ resumen.open_positions }}</p>
      </div>
      <div class="rounded-xl bg-neutral-800/40 p-3 text-center">
        <p class="text-xs text-neutral-500">Expirados hoy</p>
        <p class="font-semibold tabular-nums">{{ resumen.expired_today }}</p>
      </div>
      <div class="rounded-xl bg-neutral-800/40 p-3 text-center">
        <p class="text-xs text-neutral-500">Cerrados hoy</p>
        <p class="font-semibold tabular-nums">{{ resumen.closed_trades_today }}</p>
      </div>
      <div class="rounded-xl bg-neutral-800/40 p-3 text-center">
        <p class="text-xs text-neutral-500">P/L del día</p>
        <p
          class="font-semibold tabular-nums"
          :class="Number(resumen.today_profit_loss) > 0 ? 'text-emerald-400' : Number(resumen.today_profit_loss) < 0 ? 'text-red-400' : 'text-neutral-100'"
        >
          {{ formatoPL(resumen.today_profit_loss) }}
        </p>
      </div>
    </div>

    <p v-if="estadoCiclos === 'loading' && ciclos.length === 0" class="text-center text-sm text-neutral-500">
      Cargando ciclos...
    </p>
    <p v-else-if="estadoCiclos === 'error'" class="text-center text-sm text-red-400">
      No se pudieron cargar los ciclos.
    </p>
    <p v-else-if="ciclos.length === 0" class="text-center text-sm text-neutral-500">
      Sin ciclos todavía. El bot los creará automáticamente al encontrar oportunidades.
    </p>

    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-neutral-500 border-b border-neutral-800">
            <th class="py-2 pr-3 font-normal">Activo</th>
            <th class="py-2 pr-3 font-normal">Estrategia</th>
            <th class="py-2 pr-3 font-normal">Estado</th>
            <th class="py-2 pr-3 font-normal">Tiempo restante</th>
            <th class="py-2 pr-3 font-normal">Último evento</th>
            <th class="py-2 pr-3 font-normal">Próxima revisión</th>
            <th class="py-2 font-normal"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="ciclo in ciclos" :key="ciclo.id">
            <tr class="border-b border-neutral-800/60">
              <td class="py-2 pr-3 font-medium">{{ ciclo.symbol ?? '—' }}</td>
              <td class="py-2 pr-3 text-neutral-300">{{ ciclo.strategy_name ?? '—' }}</td>
              <td class="py-2 pr-3" :class="claseEstado(ciclo.status)">{{ etiquetaEstado(ciclo.status) }}</td>
              <td class="py-2 pr-3 tabular-nums text-neutral-300">{{ ciclo.remaining_human }}</td>
              <td class="py-2 pr-3 text-neutral-400">{{ ciclo.last_event?.message ?? '—' }}</td>
              <td class="py-2 pr-3 text-neutral-400">{{ ciclo.next_review_at ? formatoHora(ciclo.next_review_at) : '—' }}</td>
              <td class="py-2 text-right whitespace-nowrap">
                <button
                  v-if="esDetenible(ciclo)"
                  type="button"
                  class="text-xs text-red-400 hover:text-red-300 transition-colors disabled:opacity-40 disabled:cursor-not-allowed mr-3"
                  :disabled="estadoDetener[ciclo.id] === 'loading'"
                  @click="detenerCiclo(ciclo.id)"
                >
                  {{ estadoDetener[ciclo.id] === 'loading' ? 'Deteniendo...' : 'Detener' }}
                </button>
                <button
                  type="button"
                  class="text-xs text-neutral-400 hover:text-neutral-200 transition-colors"
                  @click="alternarTimeline(ciclo.id)"
                >
                  {{ cicloExpandido === ciclo.id ? 'Ocultar historial' : 'Ver historial' }}
                </button>
              </td>
            </tr>
            <tr v-if="estadoDetener[ciclo.id] === 'error'">
              <td colspan="7" class="pb-2 text-xs text-red-400 text-center">No se pudo detener el ciclo.</td>
            </tr>
            <tr v-if="cicloExpandido === ciclo.id">
              <td colspan="7" class="pb-4">
                <div class="rounded-xl bg-neutral-800/40 p-4">
                  <p v-if="estadoHistorial[ciclo.id] === 'loading'" class="text-center text-sm text-neutral-500">
                    Cargando historial...
                  </p>
                  <p v-else-if="estadoHistorial[ciclo.id] === 'error'" class="text-center text-sm text-red-400">
                    No se pudo cargar el historial.
                  </p>
                  <ol v-else class="flex flex-col gap-2 text-sm">
                    <li v-for="(evento, index) in historialPorCiclo[ciclo.id]" :key="index" class="flex items-center gap-3">
                      <span class="text-neutral-500 tabular-nums w-12 shrink-0">{{ formatoHora(evento.at) }}</span>
                      <span class="text-neutral-300">{{ etiquetaEvento(evento) }}</span>
                    </li>
                  </ol>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </section>
</template>
