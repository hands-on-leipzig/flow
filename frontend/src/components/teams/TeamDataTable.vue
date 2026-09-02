<script setup lang="ts">
import {ref} from 'vue'
import axios from 'axios'
import {
  countSetCellMismatch,
  sumCountMap,
  type TeamDataColumn,
  type TeamDataRow,
} from '@/utils/teamDataCompletion'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'

const props = defineProps<{
  eventId?: number | null
  teams: TeamDataRow[]
  columns: TeamDataColumn[]
}>()

const emit = defineEmits<{
  'open-count': [team: TeamDataRow, column: TeamDataColumn, anchor: HTMLElement]
  updated: [team: TeamDataRow]
}>()

const savingTeamId = ref<number | null>(null)
const debounceTimers = new Map<string, ReturnType<typeof setTimeout>>()

function displayNumber(value: number | null | undefined) {
  if (value === null || value === undefined) return '—'
  return String(value)
}

function countSetTotal(row: TeamDataRow, column: TeamDataColumn): number {
  if (column.editor === 'meal_counts') {
    return sumCountMap(row.meals)
  }
  const fieldKey = column.field_key
  if (!fieldKey) return 0
  const map = row.custom[fieldKey]
  if (!map || typeof map !== 'object') return 0
  return sumCountMap(map as Record<string, number>)
}

function scalarValue(row: TeamDataRow, column: TeamDataColumn): string {
  const fieldKey = column.field_key
  if (!fieldKey) return ''
  const value = row.custom[fieldKey]
  if (value === null || value === undefined) return ''
  return String(value)
}

function scheduleScalarSave(row: TeamDataRow, column: TeamDataColumn, raw: string) {
  const fieldKey = column.field_key
  if (!fieldKey || !props.eventId) return

  const timerKey = `${row.id}:${fieldKey}`
  const existing = debounceTimers.get(timerKey)
  if (existing) clearTimeout(existing)

  debounceTimers.set(
    timerKey,
    setTimeout(() => {
      debounceTimers.delete(timerKey)
      void saveScalar(row, column, raw)
    }, 450),
  )
}

async function saveScalar(row: TeamDataRow, column: TeamDataColumn, raw: string) {
  const fieldKey = column.field_key
  if (!fieldKey || !props.eventId) return

  let value: string | number | null = raw
  if (column.editor === 'number') {
    const trimmed = raw.trim()
    if (trimmed === '') {
      value = null
    } else {
      const parsed = Number.parseInt(trimmed, 10)
      if (!Number.isFinite(parsed) || parsed < 0) return
      value = parsed
    }
  } else if (raw.trim() === '') {
    value = null
  }

  savingTeamId.value = row.id
  try {
    const {data} = await axios.patch(`/events/${props.eventId}/teams/${row.id}/team-data`, {
      custom: {[fieldKey]: value},
    })
    Object.assign(row, data)
    emit('updated', row)
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    if (savingTeamId.value === row.id) savingTeamId.value = null
  }
}

function onCountCellClick(event: MouseEvent, row: TeamDataRow, column: TeamDataColumn) {
  const target = event.currentTarget
  if (!(target instanceof HTMLElement)) return
  emit('open-count', row, column, target)
}
</script>

<template>
  <div class="vol-table-frame vol-table-frame--scroll team-data-table-frame">
    <table class="vol-table team-data-table">
      <colgroup>
        <col class="team-data-col--name">
        <col class="team-data-col--nr">
        <col class="team-data-col--program">
        <col class="team-data-col--people">
        <col
            v-for="column in columns"
            :key="column.key"
            class="team-data-col--dynamic"
        >
      </colgroup>
      <thead>
        <tr>
          <th class="vol-table__sticky team-data-table__sticky--name" scope="col">Teamname</th>
          <th class="vol-table__sticky team-data-table__sticky--nr" scope="col">Nr</th>
          <th class="vol-table__sticky team-data-table__sticky--program" scope="col">Programm</th>
          <th class="vol-table__sticky team-data-table__sticky--people" scope="col">Personen</th>
          <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
          >
            {{ column.label }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-if="!teams.length">
          <td :colspan="4 + columns.length" class="team-data-table__empty">
            Keine Teams vorhanden.
          </td>
        </tr>
        <tr v-for="row in teams" :key="row.id">
          <td class="vol-table__sticky team-data-table__sticky--name">{{ row.name }}</td>
          <td class="vol-table__sticky team-data-table__sticky--nr">
            {{ displayNumber(row.team_number_plan ?? row.team_number_hot) }}
          </td>
          <td class="vol-table__sticky team-data-table__sticky--program">{{ row.program_label }}</td>
          <td class="vol-table__sticky team-data-table__sticky--people">
            {{ displayNumber(row.people_count) }}
          </td>
          <td
              v-for="column in columns"
              :key="`${row.id}-${column.key}`"
          >
            <button
                v-if="column.editor === 'meal_counts' || column.editor === 'count_set'"
                type="button"
                class="team-data-cell team-data-cell--count"
                :class="{'team-data-cell--mismatch': countSetCellMismatch(row, column)}"
                @click="onCountCellClick($event, row, column)"
            >
              {{ countSetTotal(row, column) }}
            </button>
            <input
                v-else-if="column.editor === 'text'"
                type="text"
                class="glass-input glass-input--sm team-data-cell__input"
                :value="scalarValue(row, column)"
                :disabled="savingTeamId === row.id"
                @input="scheduleScalarSave(row, column, ($event.target as HTMLInputElement).value)"
            >
            <input
                v-else-if="column.editor === 'number'"
                type="number"
                min="0"
                step="1"
                class="glass-input glass-input--sm team-data-cell__input"
                :value="scalarValue(row, column)"
                :disabled="savingTeamId === row.id"
                @input="scheduleScalarSave(row, column, ($event.target as HTMLInputElement).value)"
            >
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.team-data-table-frame {
  max-height: none;
}

.team-data-table {
  min-width: 100%;
}

.team-data-col--name,
.team-data-table__sticky--name {
  min-width: 10rem;
}

.team-data-col--nr,
.team-data-table__sticky--nr {
  min-width: 3rem;
}

.team-data-col--program,
.team-data-table__sticky--program {
  min-width: 7rem;
}

.team-data-col--people,
.team-data-table__sticky--people {
  min-width: 4.5rem;
}

.team-data-table__sticky--name {
  left: 0;
}

.team-data-table__sticky--nr {
  left: 10rem;
}

.team-data-table__sticky--program {
  left: 13rem;
}

.team-data-table__sticky--people {
  left: 20rem;
}

.team-data-table__empty {
  text-align: center;
  opacity: 0.7;
  padding: 1.5rem;
}

.team-data-cell {
  width: 100%;
  border: none;
  background: transparent;
  font: inherit;
  text-align: center;
  cursor: pointer;
  padding: 0.35rem 0.5rem;
  border-radius: var(--radius-sm);
}

.team-data-cell--count:hover {
  background: color-mix(in srgb, var(--color-accent) 8%, transparent);
}

.team-data-cell--mismatch {
  color: var(--color-warning, #b45309);
  font-weight: 600;
}

.team-data-cell__input {
  width: 100%;
  min-width: 5rem;
}
</style>
