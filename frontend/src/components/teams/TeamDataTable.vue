<script setup lang="ts">
import {ref} from 'vue'
import axios from 'axios'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {
  countSetCellMismatch,
  countSetTotal,
  type TeamDataColumn,
  type TeamDataRow,
} from '@/utils/teamDataCompletion'
import {
  photoConsentStatusClass,
  photoConsentStatusForTeam,
} from '@/utils/photoConsentStatus'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'

type TeamDataSortKey = 'team_number_hot' | 'name' | 'organization'

const props = defineProps<{
  eventId?: number | null
  teams: TeamDataRow[]
  columns: TeamDataColumn[]
  sortKey: TeamDataSortKey
  sortDir: 'asc' | 'desc'
}>()

const emit = defineEmits<{
  'toggle-sort': [key: TeamDataSortKey]
  'open-count': [team: TeamDataRow, column: TeamDataColumn, anchor: HTMLElement]
  updated: [team: TeamDataRow]
}>()

const savingTeamId = ref<number | null>(null)

function columnColClass(column: TeamDataColumn) {
  if (column.editor === 'text') return 'vol-col--custom-text'
  if (column.editor === 'meal_counts') return 'vol-col--meal'
  if (column.editor === 'boolean') return 'vol-col--photo'
  if (column.editor === 'select') return 'vol-col--custom'
  if (column.key === 'photo_consent' || column.kind === 'photo') return 'vol-col--photo'
  return 'vol-col--custom'
}

function displayNumber(value: number | null | undefined) {
  if (value === null || value === undefined) return '—'
  return String(value)
}

function displayOrganization(value: string | null | undefined) {
  const trimmed = (value ?? '').trim()
  return trimmed === '' ? '—' : trimmed
}

function photoCellClass(row: TeamDataRow, column: TeamDataColumn) {
  if (column.key !== 'photo_consent' && column.kind !== 'photo') {
    return {
      'vol-detail-trigger--unset': countSetTotal(row, column) === 0,
      'team-data-cell--mismatch': countSetCellMismatch(row, column),
    }
  }
  const status = photoConsentStatusForTeam(row.photo_consent, row.people_count).status
  return {
    [photoConsentStatusClass(status)]: true,
    'vol-detail-trigger--unset': countSetTotal(row, column) === 0,
    'team-data-cell--mismatch': countSetCellMismatch(row, column),
  }
}

function sortIcon(key: TeamDataSortKey) {
  if (props.sortKey !== key) return 'bi-arrow-down-up'
  return props.sortDir === 'asc' ? 'bi-sort-up' : 'bi-sort-down'
}

function scalarValue(row: TeamDataRow, column: TeamDataColumn): string {
  const fieldKey = column.field_key
  if (!fieldKey) return ''
  const value = row.custom[fieldKey]
  if (value === null || value === undefined) return ''
  return String(value)
}

function booleanValue(row: TeamDataRow, column: TeamDataColumn): boolean | null {
  const fieldKey = column.field_key
  if (!fieldKey) return null
  const value = row.custom[fieldKey]
  if (value === null || value === undefined) return null
  return Boolean(value)
}

function selectValue(row: TeamDataRow, column: TeamDataColumn): string {
  const fieldKey = column.field_key
  if (!fieldKey) return ''
  const value = row.custom[fieldKey]
  if (value === null || value === undefined) return ''
  return String(value)
}

function isSaving(row: TeamDataRow) {
  return savingTeamId.value === row.id
}

async function saveCustomField(row: TeamDataRow, column: TeamDataColumn, value: string | number | boolean | null) {
  const fieldKey = column.field_key
  if (!fieldKey || !props.eventId) return

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

function setCustomBoolean(row: TeamDataRow, column: TeamDataColumn, value: boolean | null) {
  if (booleanValue(row, column) === value) return
  if (column.field_key) {
    row.custom[column.field_key] = value
  }
  void saveCustomField(row, column, value)
}

function setCustomSelect(row: TeamDataRow, column: TeamDataColumn, raw: string) {
  const value = raw.trim() === '' ? null : raw
  if (selectValue(row, column) === (value ?? '')) return
  if (column.field_key) {
    row.custom[column.field_key] = value
  }
  void saveCustomField(row, column, value)
}

function saveScalar(row: TeamDataRow, column: TeamDataColumn, raw: string) {
  // Match Helferliste: trim text; empty → null. Numbers keep non-negative int parse.
  if (column.editor === 'number') {
    const trimmed = raw.trim()
    if (trimmed === '') {
      void saveCustomField(row, column, null)
      return
    }
    const parsed = Number.parseInt(trimmed, 10)
    if (!Number.isFinite(parsed) || parsed < 0) return
    void saveCustomField(row, column, parsed)
    return
  }

  const trimmed = raw.trim()
  void saveCustomField(row, column, trimmed === '' ? null : trimmed)
}

function onCountCellClick(event: MouseEvent, row: TeamDataRow, column: TeamDataColumn) {
  const target = event.currentTarget
  if (!(target instanceof HTMLElement)) return
  emit('open-count', row, column, target)
}

/** Show entered total against registered people for Fotoerlaubnis / Essen. */
function countCellLabel(row: TeamDataRow, column: TeamDataColumn) {
  const total = countSetTotal(row, column)
  if (row.people_count === null) return String(total)
  return `${total} / ${row.people_count}`
}
</script>

<template>
  <div class="vol-table-frame vol-table-frame--scroll vol-table-frame--roster vol-table-frame--team-data">
    <table class="vol-table vol-table--roster vol-table--team-data">
      <colgroup>
        <col class="vol-col--program">
        <col class="vol-col--nr">
        <col class="vol-col--name">
        <col class="vol-col--organization">
        <col class="vol-col--people">
        <col
            v-for="column in columns"
            :key="column.key"
            :class="columnColClass(column)"
        >
      </colgroup>
      <thead>
        <tr>
          <th class="vol-table__roster vol-table__sticky vol-table__sticky--program" scope="col">
            <span class="sr-only">Programm</span>
          </th>
          <th class="vol-table__sticky vol-table__sticky--nr" scope="col">
            <button
                type="button"
                class="vol-sort"
                :class="{'vol-sort--active': sortKey === 'team_number_hot'}"
                @click="emit('toggle-sort', 'team_number_hot')"
            >
              Nr
              <i class="bi" :class="sortIcon('team_number_hot')" aria-hidden="true"/>
            </button>
          </th>
          <th class="vol-table__name vol-table__sticky vol-table__sticky--name" scope="col">
            <button
                type="button"
                class="vol-sort"
                :class="{'vol-sort--active': sortKey === 'name'}"
                @click="emit('toggle-sort', 'name')"
            >
              Teamname
              <i class="bi" :class="sortIcon('name')" aria-hidden="true"/>
            </button>
          </th>
          <th scope="col">
            <button
                type="button"
                class="vol-sort"
                :class="{'vol-sort--active': sortKey === 'organization'}"
                @click="emit('toggle-sort', 'organization')"
            >
              Organisation
              <i class="bi" :class="sortIcon('organization')" aria-hidden="true"/>
            </button>
          </th>
          <th scope="col">Personen</th>
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
        <tr v-for="row in teams" :key="row.id" class="glass-table-row--hover">
          <td class="vol-table__roster vol-table__sticky vol-table__sticky--program">
            <ProgramLogo
                v-if="row.first_program"
                :program="row.first_program"
                size="chip"
                decorative
                class="vol-table__assignment-icon"
            />
            <span v-else class="team-data-table__dash">—</span>
          </td>
          <td class="vol-table__sticky vol-table__sticky--nr team-data-table__nr">
            {{ displayNumber(row.team_number_hot) }}
          </td>
          <td class="vol-table__name vol-table__sticky vol-table__sticky--name">{{ row.name }}</td>
          <td class="vol-table__role">
            {{ displayOrganization(row.organization) }}
          </td>
          <td class="team-data-table__people">
            {{ displayNumber(row.people_count) }}
          </td>
          <td
              v-for="column in columns"
              :key="`${row.id}-${column.key}`"
              class="vol-table__field"
          >
            <button
                v-if="column.editor === 'meal_counts' || column.editor === 'count_set'"
                type="button"
                class="vol-detail-trigger glass-input glass-input--sm"
                :class="photoCellClass(row, column)"
                :title="row.people_count !== null ? `Summe ${countSetTotal(row, column)} von ${row.people_count} Personen` : undefined"
                @click="onCountCellClick($event, row, column)"
            >
              {{ countCellLabel(row, column) }}
            </button>
            <div
                v-else-if="column.editor === 'boolean'"
                class="glass-segment vol-tristate"
                role="group"
                :aria-label="column.label"
            >
              <button
                  type="button"
                  class="glass-segment__btn"
                  :class="{'glass-segment__btn--active': booleanValue(row, column) === null}"
                  :aria-pressed="booleanValue(row, column) === null"
                  :disabled="isSaving(row)"
                  @click="setCustomBoolean(row, column, null)"
              >
                ?
              </button>
              <button
                  type="button"
                  class="glass-segment__btn"
                  :class="{'glass-segment__btn--active': booleanValue(row, column) === true}"
                  :aria-pressed="booleanValue(row, column) === true"
                  :disabled="isSaving(row)"
                  @click="setCustomBoolean(row, column, true)"
              >
                Ja
              </button>
              <button
                  type="button"
                  class="glass-segment__btn"
                  :class="{'glass-segment__btn--active': booleanValue(row, column) === false}"
                  :aria-pressed="booleanValue(row, column) === false"
                  :disabled="isSaving(row)"
                  @click="setCustomBoolean(row, column, false)"
              >
                Nein
              </button>
            </div>
            <select
                v-else-if="column.editor === 'select'"
                class="select-input vol-detail-select vol-detail-select--full"
                :value="selectValue(row, column)"
                :disabled="isSaving(row)"
                @change="setCustomSelect(row, column, ($event.target as HTMLSelectElement).value)"
            >
              <option value="">?</option>
              <option v-for="option in column.options ?? []" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
            <input
                v-else-if="column.editor === 'text'"
                type="text"
                class="glass-input glass-input--sm vol-detail-input"
                :value="scalarValue(row, column)"
                :disabled="isSaving(row)"
                @change="saveScalar(row, column, ($event.target as HTMLInputElement).value)"
            >
            <input
                v-else-if="column.editor === 'number'"
                type="number"
                class="glass-input glass-input--sm vol-detail-input vol-detail-input--number"
                :value="scalarValue(row, column)"
                :disabled="isSaving(row)"
                @change="saveScalar(row, column, ($event.target as HTMLInputElement).value)"
            >
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.vol-table-frame--team-data {
  --td-sticky-program: 2.75rem;
  --td-sticky-nr: 3.25rem;
  --td-sticky-name: 11rem;
}

.vol-table--team-data {
  width: max-content;
  min-width: 100%;
  table-layout: auto;
}

.vol-col--program {
  width: var(--td-sticky-program);
}

.vol-col--nr {
  min-width: var(--td-sticky-nr);
}

.vol-col--name {
  min-width: var(--td-sticky-name);
}

.vol-col--organization {
  min-width: 9rem;
}

.vol-col--people {
  min-width: 4.75rem;
}

.vol-col--meal {
  min-width: 7.5rem;
}

.vol-col--photo {
  min-width: 8.5rem;
}

.vol-col--custom {
  min-width: 7.5rem;
}

.vol-col--custom-text {
  min-width: 14rem;
}

.vol-table__sticky {
  position: sticky;
}

.vol-table-frame--team-data thead .vol-table__sticky {
  background: color-mix(in srgb, var(--color-bg-muted) 88%, #fff);
  backdrop-filter: blur(8px);
}

.vol-table-frame--team-data tbody .vol-table__sticky {
  background: color-mix(in srgb, #ffffff 92%, var(--color-bg-muted));
}

.vol-table__sticky--program {
  left: 0;
  z-index: 2;
}

.vol-table__sticky--nr {
  left: var(--td-sticky-program);
  z-index: 2;
}

.vol-table__sticky--name {
  left: calc(var(--td-sticky-program) + var(--td-sticky-nr));
  z-index: 2;
  box-shadow: 4px 0 8px -4px rgba(15, 23, 42, 0.14);
}

.vol-table-frame--team-data thead th.vol-table__sticky {
  z-index: 4;
}

.vol-table-frame--team-data tbody tr.glass-table-row--hover:hover .vol-table__sticky {
  background: var(--color-bg-hover);
}

.vol-table__name {
  font-weight: 600;
}

.vol-table__role {
  color: var(--color-text-muted);
}

.vol-table__assignment-icon {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
  object-fit: contain;
}

.team-data-table__nr,
.team-data-table__people {
  font-variant-numeric: tabular-nums;
}

.team-data-table__dash {
  opacity: 0.5;
}

.team-data-cell--mismatch {
  color: var(--color-warning, #b45309);
  font-weight: 600;
}
</style>
