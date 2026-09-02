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
})

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
  const key = rowKey(eventId, firstProgram)
  expandedKey.value = expandedKey.value === key ? null : key
}

function staleCount(event) {
  return (event.programs || []).filter((p) => p.stale).length
}

function programError(eventId, firstProgram) {
  return props.programErrors[rowKey(eventId, firstProgram)] || null
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
        <div class="font-medium">{{ event.event_name }}</div>
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
        <div class="font-medium">{{ event.event_name }}</div>
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
          <span class="font-medium">{{ event.event_name }}</span>
          <span class="text-xs text-[var(--color-text-muted)]">
            {{ formatDateOnly(event.event_date) }}
            <span v-if="event.regional_partner_name"> · {{ event.regional_partner_name }}</span>
          </span>
          <span
            v-if="staleCount(event) > 0"
            class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-700 dark:text-amber-300"
          >
            {{ staleCount(event) }} veraltet
          </span>
        </div>

        <QPlanSummaryRow
          v-for="program in event.programs"
          :key="rowKey(event.event_id, program.first_program)"
          :plan-id="event.plan_id"
          :program="program"
          :expanded="expandedKey === rowKey(event.event_id, program.first_program)"
          :dimmed="program.stale"
          :error="programError(event.event_id, program.first_program)"
          @toggle="toggleExpanded(event.event_id, program.first_program)"
        />
      </template>
    </div>
  </div>
</template>
