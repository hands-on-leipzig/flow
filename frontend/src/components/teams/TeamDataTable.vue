<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import Sortable from 'sortablejs'
import TeamPlayersEditor from '@/components/teams/TeamPlayersEditor.vue'
import {
  countSetCellMismatch,
  countSetTotal,
  isTeamRowIncomplete,
  type TeamDataColumn,
  type TeamDataRow,
} from '@/utils/teamDataCompletion'
import {
  photoConsentStatusClass,
  photoConsentStatusForTeam,
} from '@/utils/photoConsentStatus'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import {formatPlanTeamNo} from '@/utils/planTeamLabel'

type TeamDataSortKey = 'plan' | 'team_number_hot' | 'name' | 'organization'

type ProgramSync = {
  label?: string
  pending: Array<{key: string, label: string, number: string, name: string, organization: string}>
  missingIds: number[]
  buttonLabel: string
}

const props = defineProps<{
  eventId?: number | null
  teams: TeamDataRow[]
  columns: TeamDataColumn[]
  sortKey: TeamDataSortKey
  sortDir: 'asc' | 'desc'
  canReorder?: boolean
  syncByProgram?: Record<number, ProgramSync>
  syncingProgramId?: number | null
}>()

const emit = defineEmits<{
  'toggle-sort': [key: TeamDataSortKey]
  'open-count': [team: TeamDataRow, column: TeamDataColumn, anchor: HTMLElement]
  updated: [team: TeamDataRow]
  noshow: [team: TeamDataRow, value: boolean]
  reorder: [programId: number, orderedIds: number[]]
  sync: [programId: number]
}>()

const expandedTeamId = ref<number | null>(null)
const tableEl = ref<HTMLTableElement | null>(null)

function programSync(programId: number | null | undefined): ProgramSync | null {
  if (programId == null) return null
  return props.syncByProgram?.[programId] ?? null
}

function isMissing(row: TeamDataRow) {
  return programSync(row.first_program)?.missingIds.includes(row.id) ?? false
}

function programGroups() {
  const groups: Array<{programId: number, label: string, rows: TeamDataRow[]}> = []
  for (const row of props.teams) {
    const programId = Number(row.first_program ?? 0)
    const last = groups[groups.length - 1]
    if (!last || last.programId !== programId) {
      groups.push({programId, label: row.program_label || 'Programm', rows: [row]})
    } else {
      last.rows.push(row)
    }
  }
  return groups
}

const groups = computed(programGroups)

const sortables = new Map<number, Sortable>()

function destroySortables() {
  for (const instance of sortables.values()) instance.destroy()
  sortables.clear()
}

function restoreSortableDom(evt: Sortable.SortableEvent) {
  const parent = evt.from
  const item = evt.item
  if (evt.oldIndex == null || evt.oldIndex === evt.newIndex) return
  parent.removeChild(item)
  const remaining = [...parent.children]
  parent.insertBefore(item, remaining[evt.oldIndex] ?? null)
}

function mountSortables() {
  destroySortables()
  if (!props.canReorder) return
  const table = tableEl.value
  if (!table) return
  for (const body of table.querySelectorAll('tbody.team-data-program')) {
    const programId = Number((body as HTMLElement).dataset.program)
    if (!Number.isFinite(programId)) continue
    const instance = Sortable.create(body as HTMLElement, {
      draggable: 'tr.team-data-row',
      handle: '.team-data-drag',
      animation: 150,
      ghostClass: 'team-data-row--ghost',
      forceFallback: true,
      fallbackOnBody: true,
      onEnd(evt) {
        const ids = [...(evt.from.querySelectorAll('tr.team-data-row'))]
          .map((node) => Number((node as HTMLElement).dataset.teamId))
          .filter((id) => Number.isFinite(id))
        restoreSortableDom(evt)
        if (evt.oldIndex === evt.newIndex) return
        emit('reorder', programId, ids)
      },
    })
    sortables.set(programId, instance)
  }
}

onMounted(() => {
  void nextTick(mountSortables)
})

onBeforeUnmount(() => {
  destroySortables()
})

watch(
  () => [props.canReorder, props.teams.map((row) => `${row.first_program}:${row.id}`).join(','), props.sortKey, props.sortDir] as const,
  () => {
    void nextTick(mountSortables)
  },
)

function eventAnswersLabel(row: TeamDataRow) {
  const answerColumns = props.columns.filter((column) => column.editor === 'meal_counts' || column.kind === 'custom')
  if (!answerColumns.length) return '—'
  return isTeamRowIncomplete(row, answerColumns) ? 'Offen' : 'Ausgefüllt'
}

function playersTitle(row: TeamDataRow) {
  const parts = [expandedTeamId.value === row.id ? 'Teammitglieder zuklappen' : 'Teammitglieder aufklappen']
  const outOfRange = row.players_age_out_of_range ?? 0
  if (outOfRange === 1) parts.push('1 außerhalb der Altersgrenze')
  else if (outOfRange > 1) parts.push(`${outOfRange} außerhalb der Altersgrenze`)
  if (row.players_complete === true) parts.push('Coach hat „Teilnehmende komplett“ bestätigt')
  else if (row.players_complete === false) parts.push('Bestätigung fehlt')
  return parts.join(' – ')
}

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

function displayPlace(value: string | null | undefined) {
  const trimmed = (value ?? '').trim()
  return trimmed === '' ? '' : trimmed
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

function togglePlayers(row: TeamDataRow) {
  expandedTeamId.value = expandedTeamId.value === row.id ? null : row.id
}

function onTeamRowClick(event: MouseEvent, row: TeamDataRow) {
  const target = event.target
  if (!(target instanceof Element)) return
  if (target.closest('button, a, input, select, textarea, label')) return
  togglePlayers(row)
}

function tableSpan() {
  return 10 + props.columns.length
}

function displayHotNumber(value: number | null | undefined) {
  if (value === null || value === undefined) return '–'
  return String(value).padStart(4, '0')
}

const syncBubbles = computed(() => {
  const entries = Object.entries(props.syncByProgram ?? {})
  return entries.map(([id, sync]) => {
    const programId = Number(id)
    const action = sync.buttonLabel.replace(/^Abgleichen:\s*/, '')
    const detail = sync.pending
      .map((item) => `${item.label}: ${item.number} ${item.name}`)
      .join(', ')
    return {
      programId,
      label: sync.label || 'Programm',
      action,
      title: detail || sync.buttonLabel,
    }
  })
})

function onCountCellClick(event: MouseEvent, row: TeamDataRow, column: TeamDataColumn) {
  const target = event.currentTarget
  if (!(target instanceof HTMLElement)) return
  emit('open-count', row, column, target)
}

/** Show entered total against registered people for Fotoerlaubnis / Essen. */
function answerTally(row: TeamDataRow, column: TeamDataColumn) {
  const total = row.players_count ?? 0
  const answered = column.kind === 'photo' ? (row.photo_answered ?? 0) : (row.meal_answered ?? 0)
  return `${answered} / ${total}`
}

function answerTallyOpen(row: TeamDataRow, column: TeamDataColumn) {
  const total = row.players_count ?? 0
  const answered = column.kind === 'photo' ? (row.photo_answered ?? 0) : (row.meal_answered ?? 0)
  return total > 0 && answered < total
}

function countCellLabel(row: TeamDataRow, column: TeamDataColumn) {
  const total = countSetTotal(row, column)
  if (row.people_count === null) return String(total)
  return `${total} / ${row.people_count}`
}
</script>

<template>
  <div class="team-data-table">
    <div v-if="syncBubbles.length" class="team-data-sync-bubbles">
      <button
          v-for="bubble in syncBubbles"
          :key="bubble.programId"
          type="button"
          class="team-data-sync-bubble no-touch-min"
          :title="bubble.title"
          :disabled="syncingProgramId === bubble.programId"
          @click="emit('sync', bubble.programId)"
      >
        <i class="bi bi-arrow-repeat" aria-hidden="true"/>
        <span>{{ bubble.label }}</span>
        <span>{{ syncingProgramId === bubble.programId ? 'Abgleichen…' : bubble.action }}</span>
      </button>
    </div>
    <div class="vol-table-frame vol-table-frame--scroll vol-table-frame--roster vol-table-frame--team-data">
    <table ref="tableEl" class="vol-table vol-table--roster vol-table--team-data">
      <colgroup>
        <col class="vol-col--drag">
        <col class="vol-col--seq">
        <col class="vol-col--identity">
        <col class="vol-col--organization">
        <col class="vol-col--people">
        <col class="vol-col--players">
        <col class="vol-col--jury">
        <col class="vol-col--noshow">
        <col class="vol-col--answers">
        <col
            v-for="column in columns"
            :key="column.key"
            :class="columnColClass(column)"
        >
        <col class="vol-col--remove">
      </colgroup>
      <thead>
        <tr>
          <th class="vol-table__sticky vol-table__sticky--drag" scope="col">
            <span class="sr-only">Sortieren</span>
          </th>
          <th class="vol-table__sticky vol-table__sticky--seq" scope="col">
            <button
                type="button"
                class="vol-sort"
                :class="{'vol-sort--active': sortKey === 'plan'}"
                @click="emit('toggle-sort', 'plan')"
            >
              Reihenfolge
              <i class="bi" :class="sortIcon('plan')" aria-hidden="true"/>
            </button>
          </th>
          <th class="vol-table__name vol-table__sticky vol-table__sticky--identity" scope="col">
            <button
                type="button"
                class="vol-sort"
                :class="{'vol-sort--active': sortKey === 'team_number_hot'}"
                @click="emit('toggle-sort', 'team_number_hot')"
            >
              Nr
              <i class="bi" :class="sortIcon('team_number_hot')" aria-hidden="true"/>
            </button>
            <button
                type="button"
                class="vol-sort"
                :class="{'vol-sort--active': sortKey === 'name'}"
                @click="emit('toggle-sort', 'name')"
            >
              Team
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
          <th scope="col">Teilnehmende</th>
          <th scope="col">Jury</th>
          <th scope="col">No-show</th>
          <th scope="col">Eventangaben</th>
          <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
          >
            {{ column.label }}
          </th>
          <th scope="col"><span class="sr-only">Aktionen</span></th>
        </tr>
      </thead>
      <tbody
          v-for="group in groups"
          :key="group.programId"
          class="team-data-program"
          :data-program="group.programId"
      >
        <tr class="team-data-group">
          <td :colspan="tableSpan()">
            <span class="team-data-group__label">
              <strong>{{ group.label }}</strong>
              <span class="team-data-group__count">{{ group.rows.length }} Teams</span>
            </span>
          </td>
        </tr>
        <template v-for="row in group.rows" :key="row.id">
        <tr
            class="team-data-row glass-table-row--hover"
            :data-team-id="row.id"
            :data-program="group.programId"
            :class="{
              'team-data-row--noshow': row.noshow,
              'team-data-row--open': expandedTeamId === row.id,
              'team-data-row--missing': isMissing(row),
            }"
            @click="onTeamRowClick($event, row)"
        >
          <td class="vol-table__sticky vol-table__sticky--drag">
            <button
                v-if="canReorder"
                type="button"
                class="team-data-drag no-touch-min"
                title="Reihenfolge ändern"
                aria-label="Reihenfolge ändern"
                @click.stop
            >
              <i class="bi bi-grip-vertical" aria-hidden="true"/>
            </button>
          </td>
          <td class="vol-table__sticky vol-table__sticky--seq team-data-table__nr">{{ formatPlanTeamNo(row.plan_number) || '–' }}</td>
          <td class="vol-table__sticky vol-table__sticky--identity">
            <span class="team-data-identity">
              <span class="team-data-identity__nr">{{ displayHotNumber(row.team_number_hot) }}</span>
              <span class="team-data-identity__name" :title="row.name">{{ row.name || '–' }}</span>
              <button
                  type="button"
                  class="team-data-expand no-touch-min"
                  :title="playersTitle(row)"
                  :aria-expanded="expandedTeamId === row.id"
                  :aria-label="expandedTeamId === row.id ? 'Team zuklappen' : 'Team aufklappen'"
                  @click.stop="togglePlayers(row)"
              >
                <svg class="team-data-expand__icon" viewBox="0 0 16 16" aria-hidden="true">
                  <path d="M2.5 5.25 8 10.75 13.5 5.25" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
            </span>
          </td>
          <td class="vol-table__role">
            <span class="team-data-table__org">{{ displayOrganization(row.organization) }}</span>
            <span v-if="displayPlace(row.location)" class="team-data-table__place">{{ displayPlace(row.location) }}</span>
          </td>
          <td class="team-data-table__people">
            {{ displayNumber(row.people_count) }}
          </td>
          <td class="team-data-table__players">
            <span
                class="team-data-players"
                :class="{
                  'team-data-players--unset': !row.players_count,
                  'team-data-cell--mismatch': (row.players_age_out_of_range ?? 0) > 0,
                }"
                :title="playersTitle(row)"
            >
              <i
                  v-if="(row.players_age_out_of_range ?? 0) > 0"
                  class="bi bi-exclamation-triangle"
                  aria-hidden="true"
              />
              <i
                  v-else-if="row.players_complete === true"
                  class="bi bi-check-circle"
                  aria-hidden="true"
              />
              {{ displayNumber(row.players_count) }}
            </span>
          </td>
          <td>{{ row.jury_label || '–' }}</td>
          <td>
            <button
                type="button"
                class="glass-btn-secondary team-data-noshow no-touch-min"
                :aria-pressed="!!row.noshow"
                @click="emit('noshow', row, !row.noshow)"
            >
              {{ row.noshow ? 'No-show' : 'dabei' }}
            </button>
          </td>
          <td>{{ eventAnswersLabel(row) }}</td>
          <td
              v-for="column in columns"
              :key="`${row.id}-${column.key}`"
              class="vol-table__field"
          >
            <span
                v-if="column.kind === 'photo' || column.kind === 'meal'"
                :class="{'team-data-cell--mismatch': answerTallyOpen(row, column)}"
            >
              {{ answerTally(row, column) }}
            </span>
            <button
                v-else-if="column.editor === 'meal_counts' || column.editor === 'count_set'"
                type="button"
                class="vol-detail-trigger glass-input glass-input--sm team-data-count-trigger team-data-control no-touch-min"
                :class="photoCellClass(row, column)"
                :title="row.people_count !== null ? `Summe ${countSetTotal(row, column)} von ${row.people_count} Personen` : undefined"
                @click="onCountCellClick($event, row, column)"
            >
              {{ countCellLabel(row, column) }}
            </button>
            <div
                v-else-if="column.editor === 'boolean'"
                class="glass-segment vol-tristate team-data-control"
                role="group"
                :aria-label="column.label"
            >
              <button
                  type="button"
                  class="glass-segment__btn no-touch-min"
                  :class="{'glass-segment__btn--active': booleanValue(row, column) === null}"
                  :aria-pressed="booleanValue(row, column) === null"
                  :disabled="isSaving(row)"
                  @click="setCustomBoolean(row, column, null)"
              >
                ?
              </button>
              <button
                  type="button"
                  class="glass-segment__btn no-touch-min"
                  :class="{'glass-segment__btn--active': booleanValue(row, column) === true}"
                  :aria-pressed="booleanValue(row, column) === true"
                  :disabled="isSaving(row)"
                  @click="setCustomBoolean(row, column, true)"
              >
                Ja
              </button>
              <button
                  type="button"
                  class="glass-segment__btn no-touch-min"
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
                class="select-input vol-detail-select vol-detail-select--full team-data-control"
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
                class="glass-input glass-input--sm vol-detail-input team-data-control"
                :value="scalarValue(row, column)"
                :disabled="isSaving(row)"
                @change="saveScalar(row, column, ($event.target as HTMLInputElement).value)"
            >
            <input
                v-else-if="column.editor === 'number'"
                type="number"
                class="glass-input glass-input--sm vol-detail-input vol-detail-input--number team-data-control"
                :value="scalarValue(row, column)"
                :disabled="isSaving(row)"
                @change="saveScalar(row, column, ($event.target as HTMLInputElement).value)"
            >
          </td>
          <td class="team-data-table__remove"></td>
        </tr>
        <TeamPlayersEditor
            v-if="expandedTeamId === row.id"
            :event-id="eventId"
            :team-id="row.id"
            :columns="columns"
            @saved="emit('updated', $event)"
        />
        </template>
      </tbody>
    </table>
    </div>
  </div>
</template>

<style scoped>
/* The scroll frame needs a bounded flex parent inside the fill tile, or rows are clipped instead of scrolling. */
.team-data-table {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.team-data-sync-bubbles {
  flex: 0 0 auto;
}

.vol-table-frame--team-data {
  --td-drag: 1.75rem;
  --td-seq: 7.4rem;
  --td-identity: 24rem;
  --td-row-bg: #fbfcfe;
  --td-open-bg: #f4f7fb;
  --td-hover-bg: #eef2f7;
}

.vol-table--team-data {
  width: max-content;
  table-layout: fixed;
  font-size: 0.8125rem;
}

.vol-col--drag { width: var(--td-drag); }
.vol-col--seq { width: var(--td-seq); }
.vol-col--identity { width: 24rem; min-width: 24rem; }
.vol-col--organization { width: 16rem; }
.vol-col--people { width: 9.25rem; }
.vol-col--players { width: 11rem; }
.vol-col--jury { width: 4.75rem; }
.vol-col--noshow { width: 6.5rem; }
.vol-col--answers { width: 8.75rem; }
.vol-col--photo { width: 9rem; }
.vol-col--meal { width: 9.75rem; }
.vol-col--custom { width: 8.5rem; }
.vol-col--custom-text { width: 12rem; }
.vol-col--remove { width: 2.15rem; }

.vol-table-frame--team-data :deep(th),
.vol-table-frame--team-data :deep(td) {
  padding: 0.18rem 0.35rem;
}

.vol-table-frame--team-data :deep(th) {
  white-space: nowrap;
  font-size: 0.68rem;
  letter-spacing: 0.03em;
}

.vol-table-frame--team-data :deep(.vol-table__sticky) {
  position: sticky;
}

.vol-table-frame--team-data :deep(thead .vol-table__sticky) {
  background: var(--vol-table-head-bg);
}

.vol-table-frame--team-data :deep(tbody .vol-table__sticky) {
  background: var(--td-row-bg);
}

.vol-table-frame--team-data :deep(.vol-table__sticky--drag) {
  left: 0;
  z-index: 2;
}

.vol-table-frame--team-data :deep(.vol-table__sticky--seq) {
  left: var(--td-drag);
  z-index: 2;
}

.vol-table-frame--team-data :deep(.vol-table__sticky--identity) {
  left: calc(var(--td-drag) + var(--td-seq));
  z-index: 2;
}

.vol-table-frame--team-data :deep(thead th.vol-table__sticky) {
  z-index: 4;
}

.vol-table__name {
  font-weight: 600;
}

.vol-table__role {
  color: var(--color-text-muted);
}

.team-data-table__org,
.team-data-table__place {
  display: block;
}

.team-data-table__place {
  margin-top: 0.05rem;
  font-size: 0.72rem;
  line-height: 1.2;
  color: var(--color-text-subtle);
}

.vol-table__assignment-icon {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
  object-fit: contain;
}

.team-data-table__nr,
.team-data-table__people,
.team-data-players {
  font-variant-numeric: tabular-nums;
}

.team-data-players {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}

.team-data-players--unset {
  color: var(--color-text-subtle);
}

.team-data-table__dash {
  opacity: 0.5;
}

.team-data-group td {
  padding-top: 0.55rem;
  padding-bottom: 0.3rem;
  background: #f4f6f9;
  font-size: 0.8rem;
}

.team-data-group__label {
  position: sticky;
  left: 0.35rem;
  display: inline-block;
}

.team-data-group__count {
  margin-left: 0.65rem;
  color: var(--color-text-muted);
  font-weight: 400;
}

.team-data-row {
  cursor: pointer;
}

.team-data-row--noshow {
  opacity: 0.55;
}

.vol-table-frame--team-data :deep(.team-data-row--open td),
.vol-table-frame--team-data :deep(tr.team-players-editor__row td),
.vol-table-frame--team-data :deep(tr.team-players-editor__subhead td),
.vol-table-frame--team-data :deep(tr.team-players-editor__note td),
.vol-table-frame--team-data :deep(tr.team-players-editor__footer td) {
  background: var(--td-open-bg);
  border-bottom-color: color-mix(in srgb, var(--color-border) 65%, transparent);
}

.vol-table-frame--team-data :deep(.team-data-row--open td) {
  border-bottom-color: transparent;
}

.vol-table-frame--team-data :deep(.team-data-row:hover td),
.vol-table-frame--team-data :deep(.team-data-row:hover td.vol-table__sticky) {
  background: var(--td-hover-bg);
}

.vol-table-frame--team-data :deep(.team-data-row--open:hover td),
.vol-table-frame--team-data :deep(.team-data-row--open:hover td.vol-table__sticky),
.vol-table-frame--team-data :deep(.team-data-row--open td.vol-table__sticky),
.vol-table-frame--team-data :deep(tr.team-players-editor__row td.vol-table__sticky),
.vol-table-frame--team-data :deep(tr.team-players-editor__subhead td.vol-table__sticky),
.vol-table-frame--team-data :deep(tr.team-players-editor__note td.vol-table__sticky),
.vol-table-frame--team-data :deep(tr.team-players-editor__footer td.vol-table__sticky) {
  background: var(--td-open-bg);
}

.team-data-expand-wrap {
  display: inline-flex;
  align-items: center;
  gap: 0.05rem;
}

.team-data-drag {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.15rem;
  height: 1.55rem;
  margin: 0;
  padding: 0;
  border: none;
  background: transparent;
  color: var(--color-text-muted);
  cursor: grab;
  font-size: 1.15rem;
}

.team-data-drag:active {
  cursor: grabbing;
}

.team-data-row--ghost {
  opacity: 0.45;
}

.team-data-identity {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}

.team-data-identity__nr,
.team-data-identity__name {
  font-weight: 700;
  color: var(--color-text);
}

.team-data-identity__nr {
  font-variant-numeric: tabular-nums;
  flex: 0 0 auto;
}

.team-data-identity__name {
  flex: 1 1 auto;
  min-width: 8rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.team-data-identity .team-data-expand {
  flex: 0 0 auto;
  margin-left: 0.15rem;
}

.team-data-row--missing .team-data-identity__nr,
.team-data-row--missing .team-data-identity__name,
.team-data-row--missing .vol-table__role {
  text-decoration: line-through;
}

.team-data-sync-bubbles {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  margin: 0 0 0.65rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid #e07a2f;
  border-radius: 0.85rem;
  background: #fff1e4;
}

.team-data-sync-bubbles::before {
  content: 'Änderungen aus der Anmeldung';
  margin-right: 0.35rem;
  font-size: 0.84rem;
  font-weight: 750;
  color: #9a3412;
}

.team-data-sync-bubble {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  padding: 0.4rem 0.85rem;
  border: none;
  border-radius: 999px;
  background: #e86a1a;
  color: #fff;
  font-size: 0.84rem;
  font-weight: 700;
  line-height: 1.2;
  cursor: pointer;
  box-shadow: 0 1px 0 rgba(122, 45, 8, 0.25);
}

.team-data-sync-bubble:hover:not(:disabled) {
  background: #cf5510;
}

.team-data-sync-bubble:disabled {
  opacity: 0.65;
  cursor: default;
}

.team-data-expand {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.55rem;
  height: 1.55rem;
  margin: 0;
  padding: 0;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--color-text);
  cursor: pointer;
}

.team-data-expand:hover {
  background: rgba(15, 23, 42, 0.06);
}

.team-data-expand__icon {
  width: 0.95rem;
  height: 0.95rem;
  display: block;
  transition: transform 0.15s ease;
}

.team-data-row--open .team-data-expand__icon {
  transform: rotate(180deg);
}

.team-data-noshow {
  min-height: 1.6rem;
  height: 1.6rem;
  padding: 0 0.5rem;
  font-size: 0.75rem;
  box-shadow: none;
}

.vol-table-frame--team-data :deep(.team-data-control) {
  box-sizing: border-box;
  width: 100%;
  min-width: 0;
  min-height: 1.6rem;
  height: 1.6rem;
  padding: 0 0.4rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, var(--color-border));
  border-radius: 6px;
  background-color: #fff;
  box-shadow: none;
  font-size: 0.8125rem;
  line-height: 1.3;
}

.vol-table-frame--team-data :deep(select.team-data-control) {
  padding-right: 1.25rem;
  background-position: right 0.3rem center;
  background-size: 0.85rem;
}

.vol-table-frame--team-data :deep(.vol-tristate.team-data-control) {
  height: auto;
  min-height: 1.6rem;
}

.vol-table-frame--team-data :deep(input.team-data-control[type='date']) {
  padding-right: 0.15rem;
}

.team-data-count-trigger,
.team-data-count-trigger.vol-detail-trigger--unset {
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.team-data-cell--mismatch {
  color: var(--color-warning, #b45309);
  font-weight: 600;
}

.vol-table-frame--team-data :deep(.team-data-icon) {
  width: 1.6rem;
  height: 1.6rem;
  font-size: 0.9rem;
}
</style>
