<script setup lang="ts">
import {computed, onActivated, ref, watch} from 'vue'
import axios from 'axios'
import {RouterLink} from 'vue-router'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import TeamDataColumnsPanel from '@/components/molecules/TeamDataColumnsPanel.vue'
import VolunteerMealOptionsPanel from '@/components/molecules/VolunteerMealOptionsPanel.vue'
import VolunteerStaffingFilterBar from '@/components/molecules/VolunteerStaffingFilterBar.vue'
import TeamDataTable from '@/components/teams/TeamDataTable.vue'
import TeamDataCountPopover from '@/components/teams/TeamDataCountPopover.vue'
import {useVolunteerMealOptions} from '@/composables/useVolunteerMealOptions'
import {usePublicTeamDataEntry} from '@/composables/usePublicTeamDataEntry'
import {eventPrograms, programDisplayName} from '@/utils/eventPrograms'
import {
  isTeamPhotoConsentUnset,
  isTeamRowIncomplete,
  type TeamDataColumn,
  type TeamDataRow,
} from '@/utils/teamDataCompletion'
import {flowFilename} from '@/utils/flowFilename'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import '@/assets/volunteers.css'

defineOptions({name: 'TeamsTeamData'})

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const teams = ref<TeamDataRow[]>([])
const columns = ref<TeamDataColumn[]>([])
const collectMeal = ref(true)
const loading = ref(false)
const columnsPanelOpen = ref(false)
const mealPanelOpen = ref(false)
const exportBusy = ref(false)
const showOnlyIncomplete = ref(false)
const showOnlyPhotoUnset = ref(false)
const nameFilter = ref('')
const activeProgramFilters = ref<Set<number>>(new Set())
const sortKey = ref<'team_number_hot' | 'name' | 'organization'>('name')
const sortDir = ref<'asc' | 'desc'>('asc')

const countEditTeam = ref<TeamDataRow | null>(null)
const countEditColumn = ref<TeamDataColumn | null>(null)
const countAnchorEl = ref<HTMLElement | null>(null)

const {options: mealOptions, setOptions: setMealOptions} = useVolunteerMealOptions(eventId)

const {
  enabled: teamDataEntryEnabled,
  loading: teamDataEntryLoading,
  setEnabled: setTeamDataEntryEnabled,
  load: loadTeamDataEntry,
} = usePublicTeamDataEntry(eventId)

async function onTeamDataEntryToggle(next: boolean) {
  try {
    await setTeamDataEntryEnabled(next)
  } catch {
    // toast from composable
  }
}

const programFilters = computed(() => {
  const programs = eventPrograms(eventStore.selectedEvent)
  const idsWithTeams = new Set(teams.value.map((row) => row.first_program).filter(Boolean) as number[])
  return programs.filter((program) => idsWithTeams.has(Number(program.first_program)))
})

const filteredTeams = computed(() => {
  if (activeProgramFilters.value.size === 0) {
    return []
  }

  let rows = [...teams.value]
  rows = rows.filter((row) => row.first_program != null && activeProgramFilters.value.has(row.first_program))

  const query = nameFilter.value.trim().toLowerCase()
  if (query) {
    rows = rows.filter((row) => {
      const haystack = [
        row.name,
        row.organization ?? '',
        row.team_number_hot != null ? String(row.team_number_hot) : '',
      ]
        .join(' ')
        .toLowerCase()
      return haystack.includes(query)
    })
  }

  if (showOnlyPhotoUnset.value) {
    rows = rows.filter((row) => isTeamPhotoConsentUnset(row))
  }
  if (showOnlyIncomplete.value) {
    rows = rows.filter((row) => isTeamRowIncomplete(row, columns.value))
  }

  const dir = sortDir.value === 'asc' ? 1 : -1
  const key = sortKey.value
  rows.sort((a, b) => {
    if (key === 'team_number_hot') {
      const an = a.team_number_hot
      const bn = b.team_number_hot
      if (an == null && bn == null) {
        /* fall through */
      } else if (an == null) {
        return 1
      } else if (bn == null) {
        return -1
      } else if (an !== bn) {
        return (an - bn) * dir
      }
    } else if (key === 'organization') {
      const av = (a.organization ?? '').toLocaleLowerCase('de')
      const bv = (b.organization ?? '').toLocaleLowerCase('de')
      if (av < bv) return -1 * dir
      if (av > bv) return 1 * dir
    } else {
      const av = a.name.toLocaleLowerCase('de')
      const bv = b.name.toLocaleLowerCase('de')
      if (av < bv) return -1 * dir
      if (av > bv) return 1 * dir
    }

    const aName = a.name.toLocaleLowerCase('de')
    const bName = b.name.toLocaleLowerCase('de')
    if (aName < bName) return -1
    if (aName > bName) return 1
    return a.id - b.id
  })

  return rows
})

function syncProgramFilters() {
  activeProgramFilters.value = new Set(
    programFilters.value.map((program) => Number(program.first_program)).filter(Boolean),
  )
}

function toggleProgramFilter(programId: number) {
  const next = new Set(activeProgramFilters.value)
  if (next.has(programId)) {
    next.delete(programId)
  } else {
    next.add(programId)
  }
  activeProgramFilters.value = next
}

function toggleSort(key: 'team_number_hot' | 'name' | 'organization') {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = 'asc'
  }
}

function onTeamUpdated(updated: TeamDataRow) {
  const index = teams.value.findIndex((row) => row.id === updated.id)
  if (index >= 0) {
    teams.value[index] = {...teams.value[index], ...updated}
  }
}

function openCountPopover(team: TeamDataRow, column: TeamDataColumn, anchor: HTMLElement) {
  countEditTeam.value = team
  countEditColumn.value = column
  countAnchorEl.value = anchor
}

function closeCountPopover() {
  countEditTeam.value = null
  countEditColumn.value = null
  countAnchorEl.value = null
}

function onCountSaved(updated: TeamDataRow) {
  onTeamUpdated(updated)
}

async function load() {
  if (!eventId.value) return
  loading.value = true
  try {
    const {data} = await axios.get(`/events/${eventId.value}/team-data`)
    teams.value = data.teams ?? []
    columns.value = data.columns ?? []
    collectMeal.value = data.collect?.meal !== false
    if (data.meal_options) {
      setMealOptions(data.meal_options)
    }
    syncProgramFilters()
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Laden fehlgeschlagen'), 'error')
  } finally {
    loading.value = false
  }
}

async function downloadExcel() {
  if (!eventId.value || exportBusy.value || !filteredTeams.value.length) return
  exportBusy.value = true
  try {
    const teamIds = filteredTeams.value.map((team) => team.id)
    const response = await axios.get(`/events/${eventId.value}/team-data/export`, {
      params: {team_ids: teamIds.join(',')},
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename']
      || flowFilename('Teamdaten', 'xlsx', eventStore.selectedEvent?.date)
    link.click()
    window.URL.revokeObjectURL(url)
  } catch {
    showGlassToast('Export fehlgeschlagen', 'error')
  } finally {
    exportBusy.value = false
  }
}

watch(eventId, () => {
  void load()
}, {immediate: true})

// keep-alive: coach form / Spalten may change while this pane is cached
onActivated(() => {
  void load()
  void loadTeamDataEntry()
})
</script>

<template>
  <div class="vol-page vol-page--fill">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Teamdaten</h1>
        <p class="vol-page__sub">Erfassung pro Team für diese Veranstaltung</p>
      </div>
      <div class="vol-page__actions">
        <button
            v-if="collectMeal"
            type="button"
            class="glass-btn-secondary vol-upload-trigger"
            title="Essensoptionen verwalten"
            :disabled="!eventId"
            @click="mealPanelOpen = true"
        >
          <i class="bi bi-fork-knife" aria-hidden="true"/>
          Essen
        </button>
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
            :disabled="!eventId || exportBusy || !filteredTeams.length"
            @click="downloadExcel"
        >
          <i class="bi bi-download" aria-hidden="true"/>
          {{ exportBusy ? 'Export…' : 'Download' }}
        </button>
      </div>
    </header>

    <div class="vol-roster-toolbar vol-roster-toolbar--solo">
      <section class="glass-card liquid-surface-inner vol-tile vol-roster-publish">
        <div class="vol-roster-publish__row">
          <span class="vol-roster-publish__label">Dateneingabe durch Coaches</span>
          <ToggleSwitch
              :model-value="teamDataEntryEnabled"
              :disabled="teamDataEntryLoading || !eventId"
              @update:modelValue="onTeamDataEntryToggle"
          />
        </div>
        <p class="glass-settings-hint !mb-0 vol-roster-publish__hint">
          Coaches können auf dem öffentlichen Plan Teamdaten eingeben. Formular-Felder unter
          <RouterLink to="/plan/publish" class="vol-roster-publish__link">
            Ausgabe → Veröffentlichung
          </RouterLink>.
        </p>
      </section>
    </div>

    <section class="glass-card liquid-surface-inner vol-tile vol-roster-table-tile">
      <VolunteerStaffingFilterBar
          v-if="teams.length && !loading"
      >
        <template #leading>
          <div class="vol-staffing-filters__name-group">
            <span class="vol-staffing-filters__name-icon" aria-hidden="true">
              <i class="bi bi-funnel"/>
            </span>
            <input
                v-model="nameFilter"
                type="search"
                class="glass-input glass-input--sm vol-staffing-filters__name"
                placeholder="Team…"
                aria-label="Nach Teamname, Organisation oder Nr filtern"
                autocomplete="off"
            >
          </div>
        </template>
        <template #middle>
          <button
              v-for="program in programFilters"
              :key="program.first_program"
              type="button"
              class="vol-staffing-filter"
              :class="{'vol-staffing-filter--active': activeProgramFilters.has(Number(program.first_program))}"
              :aria-pressed="activeProgramFilters.has(Number(program.first_program))"
              @click="toggleProgramFilter(Number(program.first_program))"
          >
            <ProgramLogo
                :program="program"
                size="chip"
                decorative
                class="vol-staffing-filter__logo"
            />
            <span class="vol-staffing-filter__label">{{ programDisplayName(program) }}</span>
          </button>
        </template>
        <template #trailing>
          <span class="vol-staffing-filters__sep" aria-hidden="true"/>
          <button
              type="button"
              class="vol-staffing-filter"
              :class="{'vol-staffing-filter--active': showOnlyPhotoUnset}"
              :aria-pressed="showOnlyPhotoUnset"
              title="Nur Teams mit fehlender Fotoerlaubnis anzeigen"
              @click="showOnlyPhotoUnset = !showOnlyPhotoUnset"
          >
            <i class="bi bi-camera vol-staffing-filter__icon" aria-hidden="true"/>
            <span class="vol-staffing-filter__label">Fotoerlaubnis fehlt</span>
          </button>
          <button
              type="button"
              class="vol-staffing-filter"
              :class="{'vol-staffing-filter--active': showOnlyIncomplete}"
              :aria-pressed="showOnlyIncomplete"
              @click="showOnlyIncomplete = !showOnlyIncomplete"
          >
            <i class="bi bi-exclamation-circle vol-staffing-filter__icon" aria-hidden="true"/>
            <span class="vol-staffing-filter__label">Unvollständige Antworten</span>
          </button>
          <span class="vol-toolbar__count vol-staffing-filters__count">
            {{ filteredTeams.length }} / {{ teams.length }}
          </span>
        </template>
      </VolunteerStaffingFilterBar>

      <p v-if="loading" class="vol-muted">Laden…</p>
      <p v-else-if="!teams.length" class="vol-muted">Keine Teams vorhanden.</p>
      <p v-else-if="!filteredTeams.length" class="vol-muted">Keine Teams für die gewählten Filter.</p>
      <TeamDataTable
          v-else
          :event-id="eventId"
          :teams="filteredTeams"
          :columns="columns"
          :sort-key="sortKey"
          :sort-dir="sortDir"
          @toggle-sort="toggleSort"
          @open-count="openCountPopover"
          @updated="onTeamUpdated"
      />
    </section>

    <TeamDataColumnsPanel
        :open="columnsPanelOpen"
        :event-id="eventId"
        @close="columnsPanelOpen = false"
        @changed="load"
    />

    <VolunteerMealOptionsPanel
        :open="mealPanelOpen"
        :event-id="eventId"
        @close="mealPanelOpen = false"
        @changed="load"
    />

    <TeamDataCountPopover
        :event-id="eventId"
        :team="countEditTeam"
        :column="countEditColumn"
        :anchor="countAnchorEl"
        :meal-options="mealOptions"
        @close="closeCountPopover"
        @saved="onCountSaved"
    />
  </div>
</template>

