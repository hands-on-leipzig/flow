<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import TeamDataColumnsPanel from '@/components/molecules/TeamDataColumnsPanel.vue'
import VolunteerMealOptionsPanel from '@/components/molecules/VolunteerMealOptionsPanel.vue'
import TeamDataTable from '@/components/teams/TeamDataTable.vue'
import TeamDataCountPopover from '@/components/teams/TeamDataCountPopover.vue'
import {useVolunteerMealOptions} from '@/composables/useVolunteerMealOptions'
import {eventPrograms, programDisplayName} from '@/utils/eventPrograms'
import {
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
const activeProgramFilters = ref<Set<number>>(new Set())

const countEditTeam = ref<TeamDataRow | null>(null)
const countEditColumn = ref<TeamDataColumn | null>(null)
const countAnchorEl = ref<HTMLElement | null>(null)
const countSaving = ref(false)

const {options: mealOptions, setOptions: setMealOptions} = useVolunteerMealOptions(eventId)

const programFilters = computed(() => {
  const programs = eventPrograms(eventStore.selectedEvent)
  const idsWithTeams = new Set(teams.value.map((row) => row.first_program).filter(Boolean) as number[])
  return programs.filter((program) => idsWithTeams.has(Number(program.first_program)))
})

const filteredTeams = computed(() => {
  let rows = [...teams.value]
  if (activeProgramFilters.value.size > 0) {
    rows = rows.filter((row) => row.first_program != null && activeProgramFilters.value.has(row.first_program))
  }
  if (showOnlyIncomplete.value) {
    rows = rows.filter((row) => isTeamRowIncomplete(row, columns.value))
  }
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
    const response = await axios.get(`/events/${eventId.value}/team-data/export`, {
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

watch(eventId, () => load(), {immediate: true})
onMounted(() => load())
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
          {{ exportBusy ? 'Export…' : 'Excel' }}
        </button>
      </div>
    </header>

    <section class="glass-card liquid-surface-inner vol-tile vol-roster-table-tile">
      <div v-if="teams.length && !loading" class="vol-staffing-filters">
        <button
            v-for="program in programFilters"
            :key="program.first_program"
            type="button"
            class="vol-staffing-filter"
            :class="{'vol-staffing-filter--active': activeProgramFilters.has(Number(program.first_program))}"
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
        <span class="vol-staffing-filters__sep" aria-hidden="true"/>
        <button
            type="button"
            class="vol-staffing-filter"
            :class="{'vol-staffing-filter--active': showOnlyIncomplete}"
            :aria-pressed="showOnlyIncomplete"
            @click="showOnlyIncomplete = !showOnlyIncomplete"
        >
          <i class="bi bi-exclamation-circle vol-staffing-filter__icon" aria-hidden="true"/>
          <span class="vol-staffing-filter__label">Unvollständige</span>
        </button>
        <span class="vol-toolbar__count vol-staffing-filters__count">
          {{ filteredTeams.length }} / {{ teams.length }}
        </span>
      </div>

      <p v-if="loading" class="vol-muted">Laden…</p>
      <p v-else-if="!teams.length" class="vol-muted">Keine Teams vorhanden.</p>
      <p v-else-if="!filteredTeams.length" class="vol-muted">Keine Teams für die gewählten Filter.</p>
      <TeamDataTable
          v-else
          :event-id="eventId"
          :teams="filteredTeams"
          :columns="columns"
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
        :saving="countSaving"
        @close="closeCountPopover"
        @saved="onCountSaved"
    />
  </div>
</template>

