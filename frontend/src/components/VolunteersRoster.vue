<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import VolunteerEmailOutreach from '@/components/molecules/VolunteerEmailOutreach.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'
import {eventPrograms, programDisplayName, programId, programNameForId} from '@/utils/eventPrograms'
import {compareRosterEntriesByStaffingRole} from '@/utils/volunteerStaffingSort'
import {flowFilename} from '@/utils/flowFilename'
import {ROSTER_TABLE_COLUMNS, rosterColumnLabel} from '@/volunteers/columns/rosterColumns'
import type {VolunteerTableColumn} from '@/volunteers/columns/types'
import {rosterEntryHasUnsetField} from '@/utils/volunteerRosterUnset'

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
  eve_meeting: boolean | null
  notes: string | null
  updated_at: string | null
}

type Person = {
  id: number
  first_name: string
  last_name: string
  nickname: string | null
  email: string
  mobile: string | null
  updated_at: string | null
  on_roster?: boolean
}

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
  created_at: string | null
  person: Person
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roster = ref<RosterEntry[]>([])
const tableColumns = ref<VolunteerTableColumn[]>([...ROSTER_TABLE_COLUMNS])
const pool = ref<Person[]>([])
const personSearch = ref('')
const loading = ref(false)
const togglingId = ref<number | null>(null)
const addingId = ref<number | null>(null)
const removeTarget = ref<RosterEntry | null>(null)
const error = ref('')
const toast = ref('')
const exportBusy = ref(false)
const savingDetailKey = ref<string | null>(null)
const shirtEditEntry = ref<RosterEntry | null>(null)
const shirtDraft = ref<{cut: string | null; size: string | null}>({cut: null, size: null})
const shirtAnchorEl = ref<HTMLElement | null>(null)
const shirtPanelRef = ref<HTMLElement | null>(null)
const shirtPanelStyle = ref<Record<string, string>>({
  position: 'fixed',
  top: '0',
  left: '0',
  visibility: 'hidden',
})
const sortKey = ref<'name' | 'role'>('name')
const sortDir = ref<'asc' | 'desc'>('asc')

type AssignmentFilterKey = 'cross' | 'local' | `program:${number}`

const activeAssignmentFilters = ref<Set<AssignmentFilterKey>>(new Set())
const showOnlyUnset = ref(false)

const programFilters = computed(() => eventPrograms(eventStore.selectedEvent))

const rosterPersonIds = computed(() => new Set(roster.value.map((r) => r.person.id)))

const personSearchMatches = computed(() => {
  const q = personSearch.value.trim().toLowerCase()
  if (!q) return []

  return pool.value
    .filter((p) => searchHaystack(p).includes(q))
    .sort((a, b) => {
      const av = displayName(a).toLocaleLowerCase('de')
      const bv = displayName(b).toLocaleLowerCase('de')
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
      const av = displayName(a.person).toLocaleLowerCase('de')
      const bv = displayName(b.person).toLocaleLowerCase('de')
      if (av < bv) return -1 * dir
      if (av > bv) return 1 * dir
    }
    const aName = displayName(a.person).toLocaleLowerCase('de')
    const bName = displayName(b.person).toLocaleLowerCase('de')
    if (aName < bName) return -1
    if (aName > bName) return 1
    return a.person.id - b.person.id
  })
})

function assignmentFilterKey(assignment: RosterAssignment): AssignmentFilterKey {
  if (assignment.is_local) return 'local'
  if (assignment.first_program == null) return 'cross'
  return `program:${assignment.first_program}`
}

function entryMatchesFilters(entry: RosterEntry) {
  const assignments = entry.assignments ?? []
  if (assignments.length) {
    if (activeAssignmentFilters.value.size === 0) return false
    if (!assignments.some((assignment) => activeAssignmentFilters.value.has(assignmentFilterKey(assignment)))) {
      return false
    }
  }

  if (showOnlyUnset.value && !rosterEntryHasUnsetField(entry)) {
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
  const base = `${displayName(entry.person)} wird von der Helferliste dieser Veranstaltung entfernt.`
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
    eve_meeting: 'vol-col--eve',
    notes: 'vol-col--notes',
  }
  return classes[key] ?? ''
}

function isSortableRosterColumn(key: string): key is 'name' | 'role' {
  return key === 'name' || key === 'role'
}

function displayName(p: Person) {
  if (p.nickname?.trim()) return `${p.first_name} „${p.nickname}“ ${p.last_name}`
  return `${p.first_name} ${p.last_name}`
}

function searchHaystack(p: Person) {
  return [
    p.first_name,
    p.last_name,
    p.nickname,
    p.email,
    p.mobile,
    p.updated_at?.slice(0, 10),
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
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

function buildAssignmentFilterKeys(): AssignmentFilterKey[] {
  const keys: AssignmentFilterKey[] = ['cross', 'local']
  for (const program of programFilters.value) {
    const id = programId(program)
    if (id > 0) keys.push(`program:${id}`)
  }
  return keys
}

function syncAssignmentFilters() {
  const keys = buildAssignmentFilterKeys()
  const kept = keys.filter((key) => activeAssignmentFilters.value.has(key))
  activeAssignmentFilters.value = kept.length > 0 ? new Set(kept) : new Set(keys)
}

function isAssignmentFilterActive(key: AssignmentFilterKey) {
  return activeAssignmentFilters.value.has(key)
}

function toggleAssignmentFilter(key: AssignmentFilterKey) {
  const next = new Set(activeAssignmentFilters.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  activeAssignmentFilters.value = next
}

function programFilterLogo(program: {first_program?: number; id?: number; name?: string | null}) {
  return programLogoSrc({
    first_program: programId(program),
    name: program.name ?? programNameForId(eventStore.selectedEvent, programId(program)),
  })
}

function defaultDetail(): RosterDetail {
  return {
    t_shirt_cut: null,
    t_shirt_size: null,
    meal: null,
    eve_meeting: null,
    notes: null,
    updated_at: null,
  }
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
  void nextTick(() => placeShirtPanel())
}

function closeShirtPopup() {
  shirtEditEntry.value = null
  shirtAnchorEl.value = null
}

function placeShirtPanel() {
  const anchor = shirtAnchorEl.value
  const panel = shirtPanelRef.value
  if (!anchor || !panel) return

  const rect = anchor.getBoundingClientRect()
  const width = panel.offsetWidth || 260
  const height = panel.offsetHeight || 300
  const margin = 8
  const vw = window.innerWidth
  const vh = window.innerHeight

  let top = rect.bottom + margin
  if (top + height > vh - margin && rect.top - height - margin >= margin) {
    top = rect.top - height - margin
  }
  top = Math.min(Math.max(top, margin), Math.max(margin, vh - margin - height))

  let left = rect.left
  if (left + width > vw - margin) left = vw - margin - width
  if (left < margin) left = margin

  shirtPanelStyle.value = {
    position: 'fixed',
    top: `${Math.round(top)}px`,
    left: `${Math.round(left)}px`,
    visibility: 'visible',
  }
}

function onShirtReposition() {
  if (shirtEditEntry.value) placeShirtPanel()
}

function handleShirtClickOutside(event: MouseEvent) {
  if (!shirtEditEntry.value) return
  const target = event.target
  if (!(target instanceof Node)) return
  if (shirtPanelRef.value?.contains(target)) return
  if (shirtAnchorEl.value?.contains(target)) return
  closeShirtPopup()
}

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
    error.value = 'Bitte Schnitt und Größe gemeinsam wählen — oder „?“ in beiden Spalten.'
    return
  }

  const detail = entryDetail(entry)
  detail.t_shirt_cut = hasCut ? cut : null
  detail.t_shirt_size = hasSize ? size : null
  await saveDetail(entry)
  closeShirtPopup()
}

function setEveMeeting(entry: RosterEntry, value: boolean | null) {
  const detail = entryDetail(entry)
  if (detail.eve_meeting === value) return
  detail.eve_meeting = value
  void saveDetail(entry)
}

async function saveDetail(entry: RosterEntry) {
  if (!eventId.value) return
  const detail = entryDetail(entry)
  const key = `${entry.id}`
  savingDetailKey.value = key
  error.value = ''
  try {
    const {data} = await axios.patch(
      `/events/${eventId.value}/volunteer-roster/${entry.person.id}/detail`,
      {
        t_shirt_cut: detail.t_shirt_cut,
        t_shirt_size: detail.t_shirt_size,
        meal: detail.meal,
        eve_meeting: detail.eve_meeting,
        notes: detail.notes,
      },
    )
    entry.detail = data.detail ?? detail
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Speichern fehlgeschlagen'
  } finally {
    if (savingDetailKey.value === key) savingDetailKey.value = null
  }
}

async function downloadCsv() {
  if (!eventId.value || exportBusy.value || !filteredRoster.value.length) return
  exportBusy.value = true
  error.value = ''
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
    error.value = 'Export fehlgeschlagen'
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
  error.value = ''
  try {
    const [rosterRes, poolRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/volunteer-roster`),
      axios.get(`/events/${eventId.value}/volunteers`),
    ])
    roster.value = (rosterRes.data.roster ?? []).map((entry: RosterEntry) => ({
      ...entry,
      detail: entry.detail ?? defaultDetail(),
    }))
    tableColumns.value = rosterRes.data.columns ?? [...ROSTER_TABLE_COLUMNS]
    pool.value = poolRes.data.people ?? []
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Laden fehlgeschlagen'
  } finally {
    loading.value = false
  }
}

async function addToRoster(person: Person) {
  if (!eventId.value || addingId.value) return
  addingId.value = person.id
  error.value = ''
  try {
    await axios.post(`/events/${eventId.value}/volunteer-roster`, {
      volunteer_person: person.id,
    })
    await load()
    showToast(`${displayName(person)} zur Helferliste hinzugefügt`)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Hinzufügen fehlgeschlagen'
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
  error.value = ''
  try {
    await axios.delete(`/events/${eventId.value}/volunteer-roster/${entry.person.id}`)
    removeTarget.value = null
    await load()
    showToast('Von Helferliste entfernt')
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Entfernen fehlgeschlagen'
  } finally {
    togglingId.value = null
  }
}

function showToast(msg: string) {
  toast.value = msg
  setTimeout(() => {
    if (toast.value === msg) toast.value = ''
  }, 2200)
}

watch(eventId, () => syncAssignmentFilters(), {immediate: true})
watch(eventId, () => load(), {immediate: true})
watch(shirtEditEntry, async (entry) => {
  if (!entry) {
    shirtPanelStyle.value = {
      position: 'fixed',
      top: '0',
      left: '0',
      visibility: 'hidden',
    }
    return
  }
  await nextTick()
  placeShirtPanel()
})

onMounted(() => {
  load()
  document.addEventListener('mousedown', handleShirtClickOutside)
  window.addEventListener('resize', onShirtReposition)
  window.addEventListener('scroll', onShirtReposition, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', handleShirtClickOutside)
  window.removeEventListener('resize', onShirtReposition)
  window.removeEventListener('scroll', onShirtReposition, true)
})
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

    <div v-if="error" class="glass-alert-warning vol-page__alert">{{ error }}</div>
    <div v-if="toast" class="vol-page__toast">{{ toast }}</div>

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
            <span class="vol-search-chip__label">{{ displayName(person) }}</span>
          </button>
        </div>
      </div>
    </section>

    <section class="glass-card liquid-surface-inner vol-tile">
      <div v-if="roster.length && !loading" class="roster-filters">
        <button
            type="button"
            class="roster-filter"
            :class="{'roster-filter--active': isAssignmentFilterActive('cross')}"
            @click="toggleAssignmentFilter('cross')"
        >
          <span class="roster-filter__label">Übergreifend</span>
        </button>
        <button
            v-for="program in programFilters"
            :key="`filter-program-${programId(program)}`"
            type="button"
            class="roster-filter"
            :class="{'roster-filter--active': isAssignmentFilterActive(`program:${programId(program)}`)}"
            @click="toggleAssignmentFilter(`program:${programId(program)}`)"
        >
          <img
              v-if="programFilterLogo(program)"
              :src="programFilterLogo(program)"
              :alt="programDisplayName(program)"
              class="roster-filter__logo"
          >
          <span class="roster-filter__label">{{ programDisplayName(program) }}</span>
        </button>
        <button
            type="button"
            class="roster-filter"
            :class="{'roster-filter--active': isAssignmentFilterActive('local')}"
            @click="toggleAssignmentFilter('local')"
        >
          <span class="roster-filter__label">Zusätzlich</span>
        </button>
        <span class="roster-filters__sep" aria-hidden="true"/>
        <button
            type="button"
            class="roster-filter roster-filter--toggle"
            :class="{'roster-filter--active': showOnlyUnset}"
            :aria-pressed="showOnlyUnset"
            @click="showOnlyUnset = !showOnlyUnset"
        >
          <i class="bi bi-exclamation-circle roster-filter__icon" aria-hidden="true"/>
          <span class="roster-filter__label">Unvollständige</span>
        </button>
        <span class="vol-toolbar__count roster-filters__count">
          {{ filteredRoster.length }} / {{ roster.length }}
        </span>
      </div>

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
              <td class="vol-table__name">{{ displayName(entry.person) }}</td>
              <td class="vol-table__role">
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
              <td class="vol-table__field">
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
              <td class="vol-table__field">
                <select
                    class="glass-input glass-input--sm vol-detail-select vol-detail-select--full"
                    :value="entryDetail(entry).meal ?? ''"
                    :disabled="savingDetailKey === String(entry.id)"
                    @change="entryDetail(entry).meal = ($event.target as HTMLSelectElement).value || null; saveDetail(entry)"
                >
                  <option value="">?</option>
                  <option v-for="meal in MEALS" :key="meal.value" :value="meal.value">{{ meal.label }}</option>
                </select>
              </td>
              <td class="vol-table__field">
                <div
                    class="glass-segment vol-tristate"
                    role="group"
                    :aria-label="rosterColumnLabel('eve_meeting')"
                >
                  <button
                      type="button"
                      class="glass-segment__btn"
                      :class="{'glass-segment__btn--active': entryDetail(entry).eve_meeting === null}"
                      :aria-pressed="entryDetail(entry).eve_meeting === null"
                      :disabled="savingDetailKey === String(entry.id)"
                      @click="setEveMeeting(entry, null)"
                  >
                    ?
                  </button>
                  <button
                      type="button"
                      class="glass-segment__btn"
                      :class="{'glass-segment__btn--active': entryDetail(entry).eve_meeting === true}"
                      :aria-pressed="entryDetail(entry).eve_meeting === true"
                      :disabled="savingDetailKey === String(entry.id)"
                      @click="setEveMeeting(entry, true)"
                  >
                    Ja
                  </button>
                  <button
                      type="button"
                      class="glass-segment__btn"
                      :class="{'glass-segment__btn--active': entryDetail(entry).eve_meeting === false}"
                      :aria-pressed="entryDetail(entry).eve_meeting === false"
                      :disabled="savingDetailKey === String(entry.id)"
                      @click="setEveMeeting(entry, false)"
                  >
                    Nein
                  </button>
                </div>
              </td>
              <td class="vol-table__field">
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
.vol-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 0.5rem 0 2rem;
}
.vol-page__header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; }
.vol-page__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
  justify-content: flex-end;
  flex-shrink: 0;
}
.vol-upload-trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  flex-shrink: 0;
}
.vol-upload-trigger--active {
  box-shadow:
    inset 0 0 0 1px color-mix(in srgb, var(--color-accent) 45%, transparent),
    0 8px 18px rgba(15, 23, 42, 0.08);
}
.vol-page__title { font-size: 1.5rem; font-weight: 650; margin: 0; }
.vol-page__sub { margin: 0.25rem 0 0; opacity: 0.75; }
.vol-page__alert { padding: 0.75rem 1rem; border-radius: 0.75rem; }
.vol-page__toast {
  padding: 0.65rem 1rem;
  border-radius: 0.75rem;
  background: color-mix(in srgb, #15803d 12%, var(--color-bg-muted));
  border: 1px solid color-mix(in srgb, #15803d 30%, var(--color-border));
  font-size: 0.9rem;
}
.vol-tile { padding: 1rem; }
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
.vol-muted { opacity: 0.7; font-size: 0.9rem; margin: 0; }

.roster-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
  margin-bottom: 0.75rem;
}

.roster-filters__sep {
  width: 1px;
  align-self: stretch;
  min-height: 1.75rem;
  margin: 0 0.1rem;
  background: color-mix(in srgb, var(--color-border-strong) 55%, transparent);
}

.roster-filters__count {
  margin-left: auto;
  opacity: 0.6;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.roster-filter {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.65rem;
  border: 1px solid var(--liquid-border-soft);
  border-radius: var(--radius);
  background: var(--liquid-tile-bg-inner);
  box-shadow: var(--liquid-shadow-inset);
  color: var(--color-text-muted);
  font-size: 0.8125rem;
  font-weight: 500;
  line-height: 1.2;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.roster-filter:hover {
  background: var(--color-bg-hover);
  color: var(--color-text);
}

.roster-filter--active {
  border-color: color-mix(in srgb, var(--color-accent) 45%, var(--color-border));
  background: color-mix(in srgb, var(--color-accent-muted) 55%, var(--liquid-tile-bg-inner));
  color: var(--color-text);
}

.roster-filter__logo {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
}

.roster-filter__icon {
  font-size: 0.95rem;
  line-height: 1;
  flex-shrink: 0;
  opacity: 0.85;
}

.roster-filter--active .roster-filter__icon {
  opacity: 1;
}

.vol-table-frame {
  width: 100%;
  scrollbar-gutter: stable;
}
.vol-table-frame--scroll {
  max-height: min(62vh, 36rem);
  overflow: auto;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 70%, transparent);
}
.vol-table {
  width: 100%;
  table-layout: fixed;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.875rem;
}
.vol-col--roster { width: 2.75rem; }
.vol-col--name { width: 20%; }
.vol-col--role { width: 18%; }
.vol-col--tshirt { width: 11%; }
.vol-col--meal { width: 11%; }
.vol-col--eve { width: 13%; }
.vol-col--notes { width: auto; }

.vol-table th,
.vol-table td {
  padding: 0.4rem 0.45rem;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid var(--color-border);
}
.vol-table th {
  position: sticky;
  top: 0;
  z-index: 1;
  font-size: 0.75rem;
  font-weight: 650;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--color-text-muted);
  background: color-mix(in srgb, var(--color-bg-muted) 88%, #fff);
  backdrop-filter: blur(8px);
}
.vol-table tbody tr:last-child td { border-bottom: none; }
.vol-table__roster { text-align: center; }
.vol-table th.vol-table__roster,
.vol-table td.vol-table__roster { text-align: center; }
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

.vol-roster-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  position: relative;
  width: 2rem;
  height: 2rem;
  margin: 0 auto;
  padding: 0;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  cursor: pointer;
  font-size: 1.1rem;
  line-height: 1;
}
.vol-roster-icon__tip {
  position: absolute;
  top: 50%;
  left: calc(100% + 0.45rem);
  z-index: 30;
  width: max-content;
  max-width: 12rem;
  padding: 0.5rem 0.65rem;
  font-size: 0.8125rem;
  font-weight: 400;
  line-height: 1.4;
  color: var(--color-text-muted);
  text-align: left;
  white-space: normal;
  pointer-events: none;
  opacity: 0;
  transform: translateY(-50%) translateX(-2px);
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.vol-roster-icon:hover .vol-roster-icon__tip,
.vol-roster-icon:focus-visible .vol-roster-icon__tip {
  opacity: 1;
  transform: translateY(-50%) translateX(0);
}
.vol-roster-icon--on {
  color: var(--color-accent);
}
.vol-roster-icon--on:hover:not(:disabled) {
  background: var(--color-accent-muted);
}
.vol-roster-icon:disabled {
  cursor: not-allowed;
}
.vol-roster-icon:disabled .vol-roster-icon__glyph {
  opacity: 0.35;
}

.vol-sort {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  border: none;
  background: transparent;
  color: inherit;
  font: inherit;
  letter-spacing: inherit;
  text-transform: inherit;
  cursor: pointer;
}
.vol-sort .bi {
  font-size: 0.9rem;
  opacity: 0.45;
}
.vol-sort:hover .bi,
.vol-sort--active .bi {
  opacity: 1;
  color: var(--color-accent);
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
