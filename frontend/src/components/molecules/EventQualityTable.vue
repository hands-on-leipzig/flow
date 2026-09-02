<script setup>
import { computed, ref } from 'vue'
import { formatDateOnly } from '@/utils/dateTimeFormat'
import QPlanSummaryRow from '@/components/atoms/QPlanSummaryRow.vue'

const props = defineProps({
  events: {
    type: Array,
    default: () => [],
  },
  programErrors: {
    type: Object,
    default: () => ({}),
  },
  running: {
    type: Boolean,
    default: false,
  },
  runningEventId: {
    type: Number,
    default: null,
  },
})

const emit = defineEmits(['run-event'])

const expandedKey = ref(null)

const tablesHeader = computed(() => {
  const hasF8 = props.events.some((e) =>
    e.programs?.some((p) => p.first_program === 8),
  )
  const hasC = props.events.some((e) =>
    e.programs?.some((p) => p.first_program === 3),
  )
  if (hasF8 && !hasC) return 'RG-Felder'
  return 'RG-Tische'
})

function rowKey(eventId, firstProgram) {
  return `${eventId}_${firstProgram}`
}

function toggleExpanded(eventId, firstProgram) {
  const event = props.events.find((e) => e.event_id === eventId)
  const program = event?.programs?.find((p) => p.first_program === firstProgram)
  if (!programIsExpandable(event, program)) return

  const key = rowKey(eventId, firstProgram)
  expandedKey.value = expandedKey.value === key ? null : key
}

function eventIsStale(event) {
  return (event.programs || []).some((p) => p.stale)
}

function staleNote(event) {
  const stale = (event.programs || []).filter((p) => p.stale)
  if (stale.length === 0) return null

  const missing = stale.some((p) => p.stale_reason === 'missing')
  const changed = stale.some((p) => p.stale_reason === 'plan_changed')

  if (missing && changed) return 'Qualität veraltet'
  if (missing) return 'Nicht berechnet'
  return 'Veraltet'
}

function eventHasError(event) {
  return (event.programs || []).some((p) =>
    programError(event.event_id, p.first_program),
  )
}

function programError(eventId, firstProgram) {
  return props.programErrors[rowKey(eventId, firstProgram)] || null
}

function eventTitle(event) {
  const parts = [event.event_name, `E${event.event_id}`]
  if (event.plan_id) {
    parts.push(`P${event.plan_id}`)
  }
  return parts.join(' · ')
}

function programEvaluationStatus(program) {
  return program?.q_plan?.evaluation_status ?? null
}

function programIsExpandable(event, program) {
  if (!event || !program) return false
  if (eventIsStale(event)) return false
  if (programEvaluationStatus(program) === 'not_evaluable') return false
  return true
}

function eventEvaluationNote(event) {
  if (eventIsStale(event)) return null

  const programs = event.programs || []
  const notEvaluable = programs.filter((p) => programEvaluationStatus(p) === 'not_evaluable')
  const incomplete = programs.filter((p) => programEvaluationStatus(p) === 'incomplete')

  if (notEvaluable.length > 0 && incomplete.length > 0) {
    return 'Auswertung eingeschränkt'
  }
  if (notEvaluable.length > 0) {
    return notEvaluable.length === 1 ? 'Nicht auswertbar' : `${notEvaluable.length} nicht auswertbar`
  }
  if (incomplete.length > 0) {
    return incomplete.length === 1 ? 'Plan unvollständig' : `${incomplete.length} unvollständig`
  }

  return null
}
</script>

<template>
  <div>
    <div class="grid grid-cols-13 text-xs font-semibold text-[var(--color-text-muted)] uppercase tracking-wider py-1 border-b border-[var(--color-border)]">
      <div>Programm</div>
      <div>Teams</div>
      <div>Spuren</div>
      <div>{{ tablesHeader }}</div>
      <div>Runden</div>
      <div>RG asym</div>
      <div>Robot check</div>
      <div>Dauer</div>
      <div>Transfer</div>
      <div>Testrunde</div>
      <div>Tische</div>
      <div>Teams</div>
      <div>Abstand</div>
    </div>

    <div v-if="events.length === 0" class="text-[var(--color-text-subtle)] text-sm py-4">
      Keine Events in dieser Saison.
    </div>

    <div
      v-for="event in events"
      :key="event.event_id"
      class="border-b border-[var(--color-border)]"
    >
      <!-- No plan -->
      <div
        v-if="event.status === 'no_plan'"
        class="py-2 px-1 text-sm bg-[var(--color-bg-muted)] text-[var(--color-text-subtle)]"
      >
        <div class="font-medium">{{ eventTitle(event) }}</div>
        <div class="text-xs mt-0.5">
          {{ formatDateOnly(event.event_date) }}
          <span v-if="event.regional_partner_name"> · {{ event.regional_partner_name }}</span>
          · Kein Plan
        </div>
      </div>

      <!-- Explore only -->
      <div
        v-else-if="event.status === 'explore_only'"
        class="py-2 px-1 text-sm"
      >
        <div class="font-medium">{{ eventTitle(event) }}</div>
        <div class="text-xs mt-0.5 text-[var(--color-text-muted)]">
          {{ formatDateOnly(event.event_date) }}
          <span v-if="event.regional_partner_name"> · {{ event.regional_partner_name }}</span>
          <template v-if="event.explore_on"> · Nur Explore (E)</template>
          <template v-else> · Kein Challenge-Programm</template>
        </div>
      </div>

      <!-- Evaluable: header + sub-rows -->
      <template v-else>
        <div class="py-2 px-1 text-sm bg-[var(--color-bg-muted)]/50 flex items-center gap-2 flex-wrap">
          <span class="font-medium">{{ eventTitle(event) }}</span>
          <span class="text-xs text-[var(--color-text-muted)]">
            {{ formatDateOnly(event.event_date) }}
            <span v-if="event.regional_partner_name"> · {{ event.regional_partner_name }}</span>
          </span>
          <span
            v-if="staleNote(event)"
            class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-700 dark:text-amber-300"
          >
            {{ staleNote(event) }}
          </span>
          <span
            v-if="eventEvaluationNote(event)"
            class="text-[10px] px-1.5 py-0.5 rounded"
            :class="(event.programs || []).some((p) => programEvaluationStatus(p) === 'not_evaluable')
              ? 'bg-red-500/20 text-red-700 dark:text-red-300'
              : 'bg-amber-500/20 text-amber-700 dark:text-amber-300'"
          >
            {{ eventEvaluationNote(event) }}
          </span>
          <span
            v-if="eventHasError(event)"
            class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/20 text-red-700 dark:text-red-300"
          >
            Fehler
          </span>
          <button
            v-if="eventIsStale(event)"
            type="button"
            class="glass-btn-accent !px-2 !py-0.5 !text-xs inline-flex items-center gap-1 ml-auto"
            :disabled="running"
            @click="emit('run-event', event)"
          >
            <i class="bi bi-clipboard-check" aria-hidden="true" />
            <span v-if="runningEventId === event.event_id">Prüfe …</span>
            <span v-else>Qualität prüfen</span>
          </button>
        </div>

        <QPlanSummaryRow
          v-for="program in event.programs"
          :key="rowKey(event.event_id, program.first_program)"
          :plan-id="event.plan_id"
          :program="program"
          :expandable="programIsExpandable(event, program)"
          :expanded="expandedKey === rowKey(event.event_id, program.first_program)"
          @toggle="toggleExpanded(event.event_id, program.first_program)"
        />
      </template>
    </div>
  </div>
</template>
