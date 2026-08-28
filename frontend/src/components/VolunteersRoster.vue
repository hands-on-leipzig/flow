<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {useAnchoredPanel} from '@/composables/useAnchoredPanel'
import VolunteerEmailOutreach from '@/components/molecules/VolunteerEmailOutreach.vue'
import VolunteerRosterColumnsPanel from '@/components/molecules/VolunteerRosterColumnsPanel.vue'
import VolunteerStaffingFilterBar from '@/components/molecules/VolunteerStaffingFilterBar.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'
import {eventPrograms, programNameForId} from '@/utils/eventPrograms'
import {compareRosterEntriesByStaffingRole} from '@/utils/volunteerStaffingSort'
import {
  buildStaffingFilterKeys,
  staffingFilterKeyFromScope,
  syncStaffingFilters,
  toggleStaffingFilter,
  type StaffingFilterKey,
} from '@/utils/volunteerStaffingFilters'
import {flowFilename} from '@/utils/flowFilename'
import {ROSTER_TABLE_COLUMNS, type RosterColumnMeta} from '@/volunteers/columns/rosterColumns'
import {rosterEntryHasUnsetField} from '@/utils/volunteerRosterUnset'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import {type VolunteerPersonRef, volunteerDisplayName, volunteerSearchHaystack} from '@/utils/volunteerPerson'

const T_SHIRT_CUTS = [
  {value: 'maenner', label: 'Männer'},
  {value: 'frauen', label: 'Frauen'},
] as const

const T_SHIRT_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL'] as const

const MEALS = [
  {value: 'standard', label: 'Standard'},
  {value: 'vegetarisch', label: 'Vegetarisch'},
  {value: 'vegan', label: 'Vegan'},
  {value: 'keine', label: 'Keine'},
] as const

type RosterDetail = {
  t_shirt_cut: string | null
  t_shirt_size: string | null
  meal: string | null
  notes: string | null
  updated_at: string | null
}

type Person = VolunteerPersonRef

type RosterAssignment = {
  tile_name: string
  label: string
  role_id: number
  first_program: number | null
  is_local: boolean
  sequence: number
  group_index: number
}

type RosterEntry = {
  id: number
  has_assignment: boolean
  assignments?: RosterAssignment[]
  detail?: RosterDetail
  custom?: Record<string, string | number | boolean | null>
  created_at: string | null
  person: Person
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roster = ref<RosterEntry[]>([])
const tableColumns = ref<RosterColumnMeta[]>([...ROSTER_TABLE_COLUMNS])
const columnsPanelOpen = ref(false)
const pool = ref<Person[]>([])
const personSearch = ref('')
const loading = ref(false)
const togglingId = ref<number | null>(null)
const addingId = ref<number | null>(null)
const removeTarget = ref<RosterEntry | null>(null)
const exportBusy = ref(false)
const savingDetailKey = ref<string | null>(null)
const shirtEditEntry = ref<RosterEntry | null>(null)
const shirtDraft = ref<{cut: string | null; size: string | null}>({cut: null, size: null})
const shirtAnchorEl = ref<HTMLElement | null>(null)

const sortKey = ref<'name' | 'role'>('name')
const sortDir = ref<'asc' | 'desc'>('asc')

const activeAssignmentFilters = ref<Set<StaffingFilterKey>>(new Set())
const showOnlyUnset = ref(false)

const programFilters = computed(() => eventPrograms(eventStore.selectedEvent))

const rosterPersonIds = computed(() => new Set(roster.value.map((r) => r.person.id)))

const personSearchMatches = computed(() => {
  const q = personSearch.value.trim().toLowerCase()
  if (!q) return []

  return pool.value
    .filter((p) => volunteerSearchHaystack(p).includes(q))
    .sort((a, b) => {
      const av = volunteerDisplayName(a).toLocaleLowerCase('de')
      const bv = volunteerDisplayName(b).toLocaleLowerCase('de')
      if (av < bv) return -1
      if (av > bv) return 1
      return a.id - b.id
    })
})

const sortedRoster = computed(() => {
  const dir = sortDir.value === 'asc' ? 1 : -1
  const key = sortKey.value
  const programs = eventStore.selectedEvent?.programs
  return [...roster.value].sort((a, b) => {
    if (key === 'role') {
      const cmp = compareRosterEntriesByStaffingRole(a, b, programs)
      if (cmp !== 0) return cmp * dir
    } else {
      const av = volunteerDisplayName(a.person).toLocaleLowerCase('de')
      const bv = volunteerDisplayName(b.person).toLocaleLowerCase('de')
      if (av < bv) return -1 * dir
      if (av > bv) return 1 * dir
    }
    const aName = volunteerDisplayName(a.person).toLocaleLowerCase('de')
    const bName = volunteerDisplayName(b.person).toLocaleLowerCase('de')
    if (aName < bName) return -1
    if (aName > bName) return 1
    return a.person.id - b.person.id
  })
})

function assignmentFilterKey(assignment: RosterAssignment): StaffingFilterKey {
  return staffingFilterKeyFromScope(assignment)
}

function entryMatchesFilters(entry: RosterEntry) {
  const assignments = entry.assignments ?? []
  if (assignments.length) {
    if (activeAssignmentFilters.value.size === 0) return false
    if (!assignments.some((assignment) => activeAssignmentFilters.value.has(assignmentFilterKey(assignment)))) {
      return false
    }
  }

  if (showOnlyUnset.value && !rosterEntryHasUnsetField(entry, tableColumns.value)) {
    return false
  }

  return true
}

const filteredRoster = computed(() => {
  if (activeAssignmentFilters.value.size === 0) return []
  return sortedRoster.value.filter(entryMatchesFilters)
})

const visibleRosterPeople = computed(() => filteredRoster.value.map((entry) => entry.person))

const removeMessage = computed(() => {
  const entry = removeTarget.value
  if (!entry) return ''
  const base = `${volunteerDisplayName(entry.person)} wird von der Helferliste dieser Veranstaltung entfernt.`
  if (entry.has_assignment) {
    return `${base} Bestehende Zuordnungen werden ebenfalls entfernt.`
  }
  return base
})

function columnColClass(key: string) {
  const classes: Record<string, string> = {
    name: 'vol-col--name',
    role: 'vol-col--role',
    t_shirt: 'vol-col--tshirt',
    meal: 'vol-col--meal',
    notes: 'vol-col--notes',
  }
  return classes[key] ?? 'vol-col--custom'
}

function isSortableRosterColumn(key: string): key is 'name' | 'role' {
  return key === 'name' || key === 'role'
}

function isOnRoster(person: Person) {
  return rosterPersonIds.value.has(person.id)
}

function onSearchChipClick(person: Person) {
  if (isOnRoster(person) || addingId.value) return
  addToRoster(person)
}

function assignmentLogoSrc(assignment: RosterAssignment) {
  if (!assignment.first_program) return ''
  return programLogoSrc({
    first_program: assignment.first_program,
    name: programNameForId(eventStore.selectedEvent, assignment.first_program),
  })
}

function assignmentLogoAlt(assignment: RosterAssignment) {
  if (!assignment.first_program) return ''
  return programLogoAlt({
    first_program: assignment.first_program,
    name: programNameForId(eventStore.selectedEvent, assignment.first_program),
  })
}

function syncAssignmentFilters() {
  activeAssignmentFilters.value = syncStaffingFilters(
    activeAssignmentFilters.value,
    buildStaffingFilterKeys(programFilters.value),
  )
}

function onToggleAssignmentFilter(key: StaffingFilterKey) {
  activeAssignmentFilters.value = toggleStaffingFilter(activeAssignmentFilters.value, key)
}

function defaultDetail(): RosterDetail {
  return {
    t_shirt_cut: null,
    t_shirt_size: null,
    meal: null,
    notes: null,
    updated_at: null,
  }
}

function entryCustom(entry: RosterEntry): Record<string, string | number | boolean | null> {
  if (!entry.custom) {
    entry.custom = {}
  }
  return entry.custom
}

function customValue(entry: RosterEntry, fieldKey: string) {
  return entryCustom(entry)[fieldKey] ?? null
}

function entryDetail(entry: RosterEntry): RosterDetail {
  if (!entry.detail) {
    entry.detail = defaultDetail()
  }
  return entry.detail
}

function tShirtLabel(detail: RosterDetail): string {
  if (!detail.t_shirt_cut || !detail.t_shirt_size) return '?'
  const cutLabel = T_SHIRT_CUTS.find((c) => c.value === detail.t_shirt_cut)?.label ?? detail.t_shirt_cut
  return `${cutLabel} ${detail.t_shirt_size}`
}

function openShirtPopup(entry: RosterEntry, anchor: HTMLElement) {
  const detail = entryDetail(entry)
  shirtAnchorEl.value = anchor
  shirtEditEntry.value = entry
  shirtDraft.value = {
    cut: detail.t_shirt_cut,
    size: detail.t_shirt_size,
  }
}

function closeShirtPopup() {
  shirtEditEntry.value = null
  shirtAnchorEl.value = null
}

const shirtPanelOpen = computed(() => !!shirtEditEntry.value)

const {panelRef: shirtPanelRef, panelStyle: shirtPanelStyle} = useAnchoredPanel({
  isOpen: shirtPanelOpen,
  anchor: shirtAnchorEl,
  fallbackWidth: 260,
  fallbackHeight: 300,
  closeOn: 'mousedown',
  onClose: closeShirtPopup,
})

function shirtDraftCutValue(): string {
  return shirtDraft.value.cut ?? ''
}

function shirtDraftSizeValue(): string {
  return shirtDraft.value.size ?? ''
}

function cancelShirtPopup() {
  closeShirtPopup()
}

function onShirtCutPick(cut: string | null) {
  shirtDraft.value.cut = cut
}

function onShirtSizePick(size: string | null) {
  shirtDraft.value.size = size
}

async function confirmShirtPopup() {
  const entry = shirtEditEntry.value
  if (!entry) return

  const cut = shirtDraft.value.cut
  const size = shirtDraft.value.size
  const hasCut = cut !== null && cut !== ''
  const hasSize = size !== null && size !== ''

  if (hasCut !== hasSize) {
    showGlassToast('Bitte Schnitt und Größe gemeinsam wählen — oder „?“ in beiden Spalten.', 'info')
    return
  }

  const detail = entryDetail(entry)
  detail.t_shirt_cut = hasCut ? cut : null
  detail.t_shirt_size = hasSize ? size : null
  await saveDetail(entry)
  closeShirtPopup()
}

async function saveDetail(entry: RosterEntry) {
  if (!eventId.value) return
  const detail = entryDetail(entry)
  const key = `${entry.id}`
  savingDetailKey.value = key
  try {
    const {data} = await axios.patch(
      `/events/${eventId.value}/volunteer-roster/${entry.person.id}/detail`,
      {
        t_shirt_cut: detail.t_shirt_cut,
        t_shirt_size: detail.t_shirt_size,
        meal: detail.meal,
        notes: detail.notes,
      },
    )
    entry.detail = data.detail ?? detail
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    if (savingDetailKey.value === key) savingDetailKey.value = null
  }
}

async function saveCustom(entry: RosterEntry, fieldKey: string, value: string | number | boolean | null) {
  if (!eventId.value) return
  const key = `${entry.id}`
  savingDetailKey.value = key
  try {
    const {data} = await axios.patch(
      `/events/${eventId.value}/volunteer-roster/${entry.person.id}/custom`,
      {field_key: fieldKey, value},
    )
    if (data.custom) {
      entry.custom = {...entryCustom(entry), ...data.custom}
    }
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    if (savingDetailKey.value === key) savingDetailKey.value = null
  }
}

function setCustomBoolean(entry: RosterEntry, fieldKey: string, value: boolean | null) {
  if (customValue(entry, fieldKey) === value) return
  entryCustom(entry)[fieldKey] = value
  void saveCustom(entry, fieldKey, value)
}

async function downloadCsv() {
  if (!eventId.value || exportBusy.value || !filteredRoster.value.length) return
  exportBusy.value = true
  try {
    const personIds = filteredRoster.value.map((entry) => entry.person.id)
    const response = await axios.get(`/events/${eventId.value}/volunteer-roster/export`, {
      params: {person_ids: personIds.join(',')},
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = flowFilename('Helferliste', 'csv', eventStore.selectedEvent?.date)
    link.click()
    window.URL.revokeObjectURL(url)
  } catch {
    showGlassToast('Export fehlgeschlagen', 'error')
  } finally {
    exportBusy.value = false
  }
}

function rosterIconTooltip(entry: RosterEntry) {
  if (entry.has_assignment) {
    return 'Von Helferliste entfernen — Zuordnungen werden ebenfalls entfernt'
  }
  return 'Von Helferliste entfernen'
}

function toggleSort(key: 'name' | 'role') {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = 'asc'
  }
}

function sortIcon(key: 'name' | 'role') {
  if (sortKey.value !== key) return 'bi-arrow-down-up'
  return sortDir.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down'
}

async function load() {
  if (!eventId.value) return
  loading.value = true
  try {
    const [rosterRes, poolRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/volunteer-roster`),
      axios.get(`/events/${eventId.value}/volunteers`),
    ])
    roster.value = (rosterRes.data.roster ?? []).map((entry: RosterEntry) => ({
      ...entry,
      detail: entry.detail ?? defaultDetail(),
      custom: entry.custom ?? {},
    }))
    tableColumns.value = rosterRes.data.columns ?? [...ROSTER_TABLE_COLUMNS]
    pool.value = poolRes.data.people ?? []
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Laden fehlgeschlagen'), 'error')
  } finally {
    loading.value = false
  }
}

async function addToRoster(person: Person) {
  if (!eventId.value || addingId.value) return
  addingId.value = person.id
  try {
    await axios.post(`/events/${eventId.value}/volunteer-roster`, {
      volunteer_person: person.id,
    })
    await load()
    showGlassToast(`${volunteerDisplayName(person)} zur Helferliste hinzugefügt`, 'success')
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Hinzufügen fehlgeschlagen'), 'error')
  } finally {
    addingId.value = null
  }
}

function requestRemove(entry: RosterEntry) {
  if (togglingId.value === entry.person.id) return
  removeTarget.value = entry
}

async function confirmRemove() {
  const entry = removeTarget.value
  if (!eventId.value || !entry || togglingId.value === entry.person.id) return
  togglingId.value = entry.person.id
  try {
    await axios.delete(`/events/${eventId.value}/volunteer-roster/${entry.person.id}`)
    removeTarget.value = null
    await load()
    showGlassToast('Von Helferliste entfernt', 'success')
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Entfernen fehlgeschlagen'), 'error')
  } finally {
    togglingId.value = null
  }
}

watch(eventId, () => syncAssignmentFilters(), {immediate: true})
watch(eventId, () => load(), {immediate: true})

onMounted(() => load())
</script>

<template>
  <div class="vol-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Helferliste</h1>
        <p class="vol-page__sub">Alle Helfer für diese Veranstaltung</p>
      </div>
      <div class="vol-page__actions">
        <button
            type="button"
            class="glass-btn-secondary vol-upload-trigger"
            title="Spalten verwalten"
            :disabled="!eventId"
            @click="columnsPanelOpen = true"
        >
          <i class="bi bi-gear" aria-hidden="true"/>
          Spalten
        </button>
        <button
            type="button"
            class="glass-btn-secondary vol-upload-trigger"
            :class="{'vol-upload-trigger--active': exportBusy}"
            :disabled="!eventId || exportBusy || !filteredRoster.length"
            @click="downloadCsv"
        >
          <i class="bi bi-download" aria-hidden="true"/>
          {{ exportBusy ? 'Export…' : 'Download' }}
        </button>
        <VolunteerEmailOutreach scope="roster" :people="visibleRosterPeople"/>
      </div>
    </header>

    <section class="glass-card liquid-surface-inner vol-tile vol-search-tile">
      <input
          v-model="personSearch"
          type="search"
          class="glass-input glass-input--sm vol-search-tile__input"
          placeholder="Personen zum Hinzufügen zur Liste suchen…"
          autocomplete="off"
      />
      <div v-if="personSearch.trim()" class="vol-search-results">
        <p v-if="!personSearchMatches.length" class="vol-muted">
          Keine Treffer in der Personenliste.
        </p>
        <div v-else class="vol-search-chips">
          <button
              v-for="person in personSearchMatches"
              :key="person.id"
              type="button"
              class="glass-row-item vol-search-chip"
              :class="isOnRoster(person) ? 'vol-search-chip--on' : 'glass-row-item--interactive'"
              :disabled="addingId === person.id"
              @click="onSearchChipClick(person)"
          >
            <i
                class="bi vol-search-chip__icon"
                :class="isOnRoster(person) ? 'bi-clipboard-check-fill vol-search-chip__icon--roster' : 'bi-person-fill'"
                aria-hidden="true"
            />
            <span class="vol-search-chip__label">{{ volunteerDisplayName(person) }}</span>
          </button>
        </div>
      </div>
    </section>

    <section class="glass-card liquid-surface-inner vol-tile">
      <VolunteerStaffingFilterBar
          v-if="roster.length && !loading"
          :active-filters="activeAssignmentFilters"
          :programs="programFilters"
          @toggle="onToggleAssignmentFilter"
      >
        <template #trailing>
          <span class="vol-staffing-filters__sep" aria-hidden="true"/>
          <button
              type="button"
              class="vol-staffing-filter"
              :class="{'vol-staffing-filter--active': showOnlyUnset}"
              :aria-pressed="showOnlyUnset"
              @click="showOnlyUnset = !showOnlyUnset"
          >
            <i class="bi bi-exclamation-circle vol-staffing-filter__icon" aria-hidden="true"/>
            <span class="vol-staffing-filter__label">Unvollständige</span>
          </button>
          <span class="vol-toolbar__count vol-staffing-filters__count">
            {{ filteredRoster.length }} / {{ roster.length }}
          </span>
        </template>
      </VolunteerStaffingFilterBar>

      <p v-if="loading" class="vol-muted">Laden…</p>
      <p v-else-if="!roster.length" class="vol-muted">Noch niemand angemeldet.</p>
      <p v-else-if="!filteredRoster.length" class="vol-muted">Keine Helfer für die gewählten Filter.</p>

      <div v-else class="vol-table-frame vol-table-frame--scroll">
        <table class="vol-table">
          <colgroup>
            <col class="vol-col--roster"/>
            <col
                v-for="column in tableColumns"
                :key="`roster-col-${column.key}`"
                :class="columnColClass(column.key)"
            />
          </colgroup>
          <thead>
            <tr>
              <th class="vol-table__roster" scope="col"><span class="sr-only">Helferliste</span></th>
              <th
                  v-for="column in tableColumns"
                  :key="column.key"
                  scope="col"
              >
                <button
                    v-if="column.sortable && isSortableRosterColumn(column.key)"
                    type="button"
                    class="vol-sort"
                    :class="{'vol-sort--active': sortKey === column.key}"
                    @click="toggleSort(column.key)"
                >
                  {{ column.label }}
                  <i class="bi" :class="sortIcon(column.key)" aria-hidden="true"/>
                </button>
                <span v-else>{{ column.label }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in filteredRoster" :key="entry.id" class="glass-table-row--hover">
              <td class="vol-table__roster">
                <button
                    type="button"
                    class="vol-roster-icon vol-roster-icon--on"
                    :disabled="togglingId === entry.person.id"
                    :aria-label="rosterIconTooltip(entry)"
                    @click="requestRemove(entry)"
                >
                  <i class="bi bi-clipboard-check-fill vol-roster-icon__glyph" aria-hidden="true"/>
                  <span class="vol-roster-icon__tip glass-dropdown" role="tooltip">
                    {{ rosterIconTooltip(entry) }}
                  </span>
                </button>
              </td>
              <template v-for="column in tableColumns" :key="`${entry.id}-${column.key}`">
                <td v-if="column.key === 'name'" class="vol-table__name">{{ volunteerDisplayName(entry.person) }}</td>
                <td v-else-if="column.key === 'role'" class="vol-table__role">
                  <div v-if="entry.assignments?.length" class="vol-table__assignments">
                    <div
                        v-for="(assignment, idx) in entry.assignments"
                        :key="`${entry.id}-assignment-${idx}`"
                        class="vol-table__assignment"
                    >
                      <img
                          v-if="assignmentLogoSrc(assignment)"
                          :src="assignmentLogoSrc(assignment)"
                          :alt="assignmentLogoAlt(assignment)"
                          class="vol-table__assignment-icon"
                      >
                      <span>{{ assignment.tile_name }}</span>
                    </div>
                  </div>
                  <span v-else>—</span>
                </td>
                <td v-else-if="column.editor === 't_shirt'" class="vol-table__field">
                  <button
                      type="button"
                      class="vol-detail-trigger glass-input glass-input--sm"
                      :class="{'vol-detail-trigger--unset': !entryDetail(entry).t_shirt_cut || !entryDetail(entry).t_shirt_size}"
                      :disabled="savingDetailKey === String(entry.id)"
                      @click="openShirtPopup(entry, $event.currentTarget as HTMLElement)"
                  >
                    {{ tShirtLabel(entryDetail(entry)) }}
                  </button>
                </td>
                <td v-else-if="column.editor === 'meal'" class="vol-table__field">
                  <select
                      class="select-input vol-detail-select vol-detail-select--full"
                      :value="entryDetail(entry).meal ?? ''"
                      :disabled="savingDetailKey === String(entry.id)"
                      @change="entryDetail(entry).meal = ($event.target as HTMLSelectElement).value || null; saveDetail(entry)"
                  >
                    <option value="">?</option>
                    <option v-for="meal in MEALS" :key="meal.value" :value="meal.value">{{ meal.label }}</option>
                  </select>
                </td>
                <td v-else-if="column.editor === 'text'" class="vol-table__field">
                  <input
                      type="text"
                      class="glass-input glass-input--sm vol-detail-input"
                      :value="entryDetail(entry).notes ?? ''"
                      placeholder="Bemerkung"
                      :disabled="savingDetailKey === String(entry.id)"
                      @change="entryDetail(entry).notes = ($event.target as HTMLInputElement).value.trim() || null"
                      @blur="saveDetail(entry)"
                  >
                </td>
                <td v-else-if="column.kind === 'custom' && column.field_key" class="vol-table__field">
                  <input
                      v-if="column.type === 'text'"
                      type="text"
                      class="glass-input glass-input--sm vol-detail-input"
                      :value="(customValue(entry, column.field_key) as string | null) ?? ''"
                      :disabled="savingDetailKey === String(entry.id)"
                      @change="entryCustom(entry)[column.field_key] = ($event.target as HTMLInputElement).value.trim() || null; saveCustom(entry, column.field_key, entryCustom(entry)[column.field_key] ?? null)"
                  >
                  <input
                      v-else-if="column.type === 'number'"
                      type="number"
                      class="glass-input glass-input--sm vol-detail-input vol-detail-input--number"
                      :value="customValue(entry, column.field_key) ?? ''"
                      :disabled="savingDetailKey === String(entry.id)"
                      @change="saveCustom(entry, column.field_key, ($event.target as HTMLInputElement).value.trim() || null)"
                  >
                  <select
                      v-else-if="column.type === 'select'"
                      class="select-input vol-detail-select vol-detail-select--full"
                      :value="(customValue(entry, column.field_key) as string | null) ?? ''"
                      :disabled="savingDetailKey === String(entry.id)"
                      @change="saveCustom(entry, column.field_key, ($event.target as HTMLSelectElement).value || null)"
                  >
                    <option value="">?</option>
                    <option v-for="option in column.options ?? []" :key="option.value" :value="option.value">
                      {{ option.label }}
                    </option>
                  </select>
                  <div
                      v-else-if="column.type === 'boolean'"
                      class="glass-segment vol-tristate"
                      role="group"
                      :aria-label="column.label"
                  >
                    <button
                        type="button"
                        class="glass-segment__btn"
                        :class="{'glass-segment__btn--active': customValue(entry, column.field_key) === null}"
                        :aria-pressed="customValue(entry, column.field_key) === null"
                        :disabled="savingDetailKey === String(entry.id)"
                        @click="setCustomBoolean(entry, column.field_key, null)"
                    >
                      ?
                    </button>
                    <button
                        type="button"
                        class="glass-segment__btn"
                        :class="{'glass-segment__btn--active': customValue(entry, column.field_key) === true}"
                        :aria-pressed="customValue(entry, column.field_key) === true"
                        :disabled="savingDetailKey === String(entry.id)"
                        @click="setCustomBoolean(entry, column.field_key, true)"
                    >
                      Ja
                    </button>
                    <button
                        type="button"
                        class="glass-segment__btn"
                        :class="{'glass-segment__btn--active': customValue(entry, column.field_key) === false}"
                        :aria-pressed="customValue(entry, column.field_key) === false"
                        :disabled="savingDetailKey === String(entry.id)"
                        @click="setCustomBoolean(entry, column.field_key, false)"
                    >
                      Nein
                    </button>
                  </div>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <ConfirmationModal
        :show="!!removeTarget"
        type="warning"
        title="Von Helferliste entfernen?"
        :message="removeMessage"
        confirm-text="Entfernen"
        cancel-text="Abbrechen"
        @confirm="confirmRemove"
        @cancel="removeTarget = null"
    />

    <VolunteerRosterColumnsPanel
        :open="columnsPanelOpen"
        :event-id="eventId"
        @close="columnsPanelOpen = false"
        @changed="load"
    />

    <Teleport to="body">
      <div
          v-if="shirtEditEntry"
          ref="shirtPanelRef"
          class="glass-modal vol-shirt-popover"
          :style="shirtPanelStyle"
          @click.stop
      >
        <h3 class="vol-shirt-popover__title">T-Shirt</h3>
        <div class="vol-shirt-popover__columns">
        <fieldset class="vol-shirt-popover__group">
          <legend class="vol-shirt-popover__legend">Schnitt</legend>
          <label class="vol-shirt-popover__option">
            <input
                type="radio"
                name="vol-shirt-cut"
                value=""
                :checked="shirtDraftCutValue() === ''"
                @change="onShirtCutPick(null)"
            >
            <span>?</span>
          </label>
          <label
              v-for="cut in T_SHIRT_CUTS"
              :key="cut.value"
              class="vol-shirt-popover__option"
          >
            <input
                type="radio"
                name="vol-shirt-cut"
                :value="cut.value"
                :checked="shirtDraftCutValue() === cut.value"
                @change="onShirtCutPick(cut.value)"
            >
            <span>{{ cut.label }}</span>
          </label>
        </fieldset>
        <fieldset class="vol-shirt-popover__group">
          <legend class="vol-shirt-popover__legend">Größe</legend>
          <label class="vol-shirt-popover__option">
            <input
                type="radio"
                name="vol-shirt-size"
                value=""
                :checked="shirtDraftSizeValue() === ''"
                @change="onShirtSizePick(null)"
            >
            <span>?</span>
          </label>
          <label
              v-for="size in T_SHIRT_SIZES"
              :key="size"
              class="vol-shirt-popover__option"
          >
            <input
                type="radio"
                name="vol-shirt-size"
                :value="size"
                :checked="shirtDraftSizeValue() === size"
                @change="onShirtSizePick(size)"
            >
            <span>{{ size }}</span>
          </label>
        </fieldset>
        </div>
        <div class="vol-shirt-popover__actions">
          <button
              type="button"
              class="glass-btn-secondary"
              :disabled="savingDetailKey === String(shirtEditEntry?.id)"
              @click="cancelShirtPopup"
          >
            Abbruch
          </button>
          <button
              type="button"
              class="glass-btn-accent"
              :disabled="savingDetailKey === String(shirtEditEntry?.id)"
              @click="confirmShirtPopup"
          >
            Übernehmen
          </button>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.vol-search-tile {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.vol-search-tile__input {
  width: 100%;
}
.vol-search-results {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.vol-search-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.vol-search-chip {
  font-size: 0.75rem;
  padding: 0.35rem 0.5rem;
  gap: 0.4rem;
}
.vol-search-chip--on {
  cursor: default;
}
.vol-search-chip__icon {
  color: var(--color-text-subtle);
}
.vol-search-chip__icon--roster {
  color: var(--color-accent);
}
.vol-search-chip__label {
  padding: 0;
}
.vol-search-chip:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.vol-col--name { width: 20%; }
.vol-col--role { width: 18%; }
.vol-col--tshirt { width: 11%; }
.vol-col--meal { width: 11%; }
.vol-col--notes { width: auto; }
.vol-col--custom { width: 11%; }

.vol-table__name {
  font-weight: 600;
}
.vol-table__role {
  color: var(--color-text-muted);
}
.vol-table__assignments {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.vol-table__assignment {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
}
.vol-table__assignment-icon {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
  object-fit: contain;
}
.vol-table__placeholder {
  color: var(--color-text-muted);
}
.vol-table__field {
  vertical-align: middle;
}
.vol-detail-trigger {
  width: 100%;
  min-width: 5.5rem;
  text-align: left;
  cursor: pointer;
}
.vol-detail-trigger--unset {
  color: var(--color-text-subtle);
  text-align: center;
}
.vol-detail-trigger:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.vol-detail-select,
.vol-detail-input {
  width: 100%;
  min-width: 0;
  font-size: 0.8125rem;
}
.vol-table__field select.select-input {
  box-sizing: border-box;
  min-height: var(--field-min-height-sm);
  height: var(--field-min-height-sm);
  padding: var(--field-padding-y-sm) 2rem var(--field-padding-y-sm) var(--field-padding-x-sm);
  font-size: var(--field-font-size-sm);
  border-radius: var(--field-radius-sm);
  line-height: 1.4;
}
.vol-detail-input--number {
  -moz-appearance: textfield;
  appearance: textfield;
}
.vol-detail-input--number::-webkit-outer-spin-button,
.vol-detail-input--number::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.vol-detail-select--full {
  min-width: 6.5rem;
}
.vol-shirt-popover {
  z-index: 1200;
  width: min(20rem, calc(100vw - 1rem));
  padding: 0.85rem 1rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-popover-fill);
  backdrop-filter: blur(var(--liquid-popover-blur));
  box-shadow: var(--shadow-lg);
}
.vol-shirt-popover__title {
  margin: 0 0 0.65rem;
  font-size: 0.875rem;
  font-weight: 600;
}
.vol-shirt-popover__columns {
  display: flex;
  flex-direction: row;
  align-items: flex-start;
  gap: 1rem;
}
.vol-shirt-popover__group {
  flex: 1;
  min-width: 0;
  margin: 0;
  padding: 0;
  border: none;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
.vol-shirt-popover__legend {
  padding: 0;
  margin-bottom: 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
}
.vol-shirt-popover__option {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  width: 100%;
  margin: 0.12rem 0;
  font-size: 0.8125rem;
  cursor: pointer;
}
.vol-shirt-popover__option input {
  margin: 0;
}
.vol-shirt-popover__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.85rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--color-border);
}
.vol-tristate {
  display: inline-flex;
  width: 100%;
  min-width: 7.5rem;
}
.vol-tristate .glass-segment__btn {
  flex: 1;
  padding: 0.2rem 0.35rem;
  font-size: 0.75rem;
  line-height: 1.3;
}
.vol-tristate .glass-segment__btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
</style>
