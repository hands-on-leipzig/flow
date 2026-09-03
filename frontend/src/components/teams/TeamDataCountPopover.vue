<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {useAnchoredPanel} from '@/composables/useAnchoredPanel'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import type {TeamDataColumn, TeamDataRow} from '@/utils/teamDataCompletion'
import type {VolunteerMealOption} from '@/composables/useVolunteerMealOptions'

const BOOLEAN_LABELS: Record<string, string> = {
  unknown: '?',
  yes: 'Ja',
  no: 'Nein',
}

const props = defineProps<{
  eventId?: number | null
  team: TeamDataRow | null
  column: TeamDataColumn | null
  anchor: HTMLElement | null
  mealOptions: VolunteerMealOption[]
}>()

const emit = defineEmits<{
  close: []
  saved: [team: TeamDataRow]
}>()

const draft = ref<Record<string, number>>({})
const saving = ref(false)

const isOpen = computed(() => !!props.team && !!props.column && !!props.anchor)

const {panelRef, panelStyle} = useAnchoredPanel({
  isOpen,
  anchor: computed(() => props.anchor),
  fallbackWidth: 280,
  fallbackHeight: 320,
  closeOn: 'mousedown',
  onClose: () => emit('close'),
})

const rows = computed(() => {
  const column = props.column
  if (!column) return []

  if (column.editor === 'meal_counts') {
    return props.mealOptions.map((option) => ({
      key: option.value,
      label: option.label,
    }))
  }

  if (column.key === 'photo_consent' || column.kind === 'photo') {
    return (column.boolean_keys ?? ['unknown', 'yes', 'no']).map((key) => ({
      key,
      label: BOOLEAN_LABELS[key] ?? key,
    }))
  }

  if (column.type === 'boolean') {
    return (column.boolean_keys ?? ['unknown', 'yes', 'no']).map((key) => ({
      key,
      label: BOOLEAN_LABELS[key] ?? key,
    }))
  }

  return (column.options ?? []).map((option) => ({
    key: option.value,
    label: option.label,
  }))
})

const title = computed(() => props.column?.label ?? 'Anzahlen')

watch(
  () => [props.team, props.column] as const,
  ([team, column]) => {
    if (!team || !column) return
    const next: Record<string, number> = {}
    if (column.editor === 'meal_counts') {
      for (const option of props.mealOptions) {
        next[option.value] = Number(team.meals?.[option.value] ?? 0)
      }
    } else if (column.key === 'photo_consent' || column.kind === 'photo') {
      const map = team.photo_consent ?? {}
      for (const row of rows.value) {
        next[row.key] = Number(map[row.key] ?? 0)
      }
    } else if (column.field_key) {
      const map = team.custom[column.field_key]
      const source = map && typeof map === 'object' ? (map as Record<string, number>) : {}
      for (const row of rows.value) {
        next[row.key] = Number(source[row.key] ?? 0)
      }
    }
    draft.value = next
  },
  {immediate: true},
)

function onCountInput(key: string, raw: string) {
  const parsed = raw.trim() === '' ? 0 : Number.parseInt(raw, 10)
  draft.value[key] = Number.isFinite(parsed) && parsed >= 0 ? parsed : 0
}

async function confirm() {
  const team = props.team
  const column = props.column
  if (!team || !column || !props.eventId || saving.value) return

  saving.value = true
  try {
    const payload: Record<string, unknown> = {}
    if (column.editor === 'meal_counts') {
      payload.meals = {...draft.value}
    } else if (column.key === 'photo_consent' || column.kind === 'photo') {
      payload.photo_consent = {...draft.value}
    } else if (column.field_key) {
      payload.custom = {[column.field_key]: {...draft.value}}
    }

    const {data} = await axios.patch(`/events/${props.eventId}/teams/${team.id}/team-data`, payload)
    emit('saved', data as TeamDataRow)
    emit('close')
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
        v-if="team && column"
        ref="panelRef"
        class="glass-modal team-data-popover"
        :style="panelStyle"
        @click.stop
    >
      <h3 class="team-data-popover__title">{{ title }}</h3>
      <p v-if="team.people_count !== null" class="team-data-popover__hint">
        Personen: {{ team.people_count }}
      </p>
      <div class="team-data-popover__rows">
        <label
            v-for="row in rows"
            :key="row.key"
            class="team-data-popover__row"
        >
          <span class="team-data-popover__label">{{ row.label }}</span>
          <input
              type="number"
              min="0"
              step="1"
              class="glass-input glass-input--sm team-data-popover__input"
              :value="draft[row.key] ?? 0"
              @input="onCountInput(row.key, ($event.target as HTMLInputElement).value)"
          >
        </label>
      </div>
      <div class="team-data-popover__actions">
        <button type="button" class="glass-btn-secondary" :disabled="saving" @click="emit('close')">
          Abbrechen
        </button>
        <button type="button" class="glass-btn-accent" :disabled="saving" @click="confirm">
          Übernehmen
        </button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.team-data-popover {
  z-index: 1200;
  width: min(22rem, calc(100vw - 1rem));
  padding: 0.85rem 1rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-popover-fill);
  backdrop-filter: blur(var(--liquid-popover-blur));
  box-shadow: var(--shadow-lg);
}

.team-data-popover__title {
  margin: 0 0 0.35rem;
  font-size: 0.875rem;
  font-weight: 600;
}

.team-data-popover__hint {
  margin: 0 0 0.65rem;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.team-data-popover__rows {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  max-height: 16rem;
  overflow: auto;
}

.team-data-popover__row {
  display: grid;
  grid-template-columns: 1fr 4.5rem;
  gap: 0.5rem;
  align-items: center;
}

.team-data-popover__label {
  font-size: 0.8125rem;
}

.team-data-popover__input {
  width: 100%;
}

.team-data-popover__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.85rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--color-border);
}
</style>
