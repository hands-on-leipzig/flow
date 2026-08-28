<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import VolunteerEmailOutreach from '@/components/molecules/VolunteerEmailOutreach.vue'
import VolunteerRosterColumnsPanel from '@/components/molecules/VolunteerRosterColumnsPanel.vue'
import VolunteerStaffingFilterBar from '@/components/molecules/VolunteerStaffingFilterBar.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import VolunteerPersonSearch from '@/components/volunteers/VolunteerPersonSearch.vue'
import VolunteerRosterTable from '@/components/volunteers/VolunteerRosterTable.vue'
import VolunteerShirtPopover from '@/components/volunteers/VolunteerShirtPopover.vue'
import {eventPrograms} from '@/utils/eventPrograms'
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
import {type VolunteerPersonRef, volunteerDisplayName} from '@/utils/volunteerPerson'
import {defaultRosterDetail, type RosterEntry} from '@/volunteers/rosterTypes'

type Person = VolunteerPersonRef

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roster = ref<RosterEntry[]>([])
const tableColumns = ref<RosterColumnMeta[]>([...ROSTER_TABLE_COLUMNS])
const columnsPanelOpen = ref(false)
const pool = ref<Person[]>([])
const loading = ref(false)
const togglingId = ref<number | null>(null)
const addingId = ref<number | null>(null)
const removeTarget = ref<RosterEntry | null>(null)
const exportBusy = ref(false)
const shirtEditEntry = ref<RosterEntry | null>(null)
const shirtAnchorEl = ref<HTMLElement | null>(null)

const sortKey = ref<'name' | 'role'>('name')
const sortDir = ref<'asc' | 'desc'>('asc')

const activeAssignmentFilters = ref<Set<StaffingFilterKey>>(new Set())
const showOnlyUnset = ref(false)

const programFilters = computed(() => eventPrograms(eventStore.selectedEvent))

const rosterPersonIds = computed(() => new Set(roster.value.map((r) => r.person.id)))

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

function entryMatchesFilters(entry: RosterEntry) {
  const assignments = entry.assignments ?? []
  if (assignments.length) {
    if (activeAssignmentFilters.value.size === 0) return false
    if (!assignments.some((assignment) => activeAssignmentFilters.value.has(staffingFilterKeyFromScope(assignment)))) {
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

function syncAssignmentFilters() {
  activeAssignmentFilters.value = syncStaffingFilters(
    activeAssignmentFilters.value,
    buildStaffingFilterKeys(programFilters.value),
  )
}

function onToggleAssignmentFilter(key: StaffingFilterKey) {
  activeAssignmentFilters.value = toggleStaffingFilter(activeAssignmentFilters.value, key)
}

function toggleSort(key: 'name' | 'role') {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = 'asc'
  }
}

function openShirtPopup(entry: RosterEntry, anchor: HTMLElement) {
  shirtAnchorEl.value = anchor
  shirtEditEntry.value = entry
}

function closeShirtPopup() {
  shirtEditEntry.value = null
  shirtAnchorEl.value = null
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
      detail: entry.detail ?? defaultRosterDetail(),
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

    <VolunteerPersonSearch
        :pool="pool"
        :on-roster="(id) => rosterPersonIds.has(id)"
        :busy-person-id="addingId"
        @select="addToRoster"
    />

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

      <VolunteerRosterTable
          v-else
          :event-id="eventId"
          :entries="filteredRoster"
          :columns="tableColumns"
          :sort-key="sortKey"
          :sort-dir="sortDir"
          :toggling-id="togglingId"
          @toggle-sort="toggleSort"
          @request-remove="requestRemove"
          @open-shirt="openShirtPopup"
      />
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

    <VolunteerShirtPopover
        :event-id="eventId"
        :entry="shirtEditEntry"
        :anchor="shirtAnchorEl"
        @close="closeShirtPopup"
    />
  </div>
</template>
