<script setup lang="ts">
import {computed, onActivated, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import TeamDataColumnsPanel from '@/components/molecules/TeamDataColumnsPanel.vue'
import PublicFormFieldsDialog from '@/components/molecules/PublicFormFieldsDialog.vue'
import VolunteerMealOptionsPanel from '@/components/molecules/VolunteerMealOptionsPanel.vue'
import VolunteerStaffingFilterBar from '@/components/molecules/VolunteerStaffingFilterBar.vue'
import TeamDataTable from '@/components/teams/TeamDataTable.vue'
import TeamDataCountPopover from '@/components/teams/TeamDataCountPopover.vue'
import TeamsEmailOutreach from '@/components/teams/TeamsEmailOutreach.vue'
import TeamsRegistrationStats from '@/components/teams/TeamsRegistrationStats.vue'
import TeamsMultiTeamCoaches from '@/components/teams/TeamsMultiTeamCoaches.vue'
import ScreenHelpButton from '@/components/atoms/ScreenHelpButton.vue'
import Spinner from '@/components/atoms/Spinner.vue'
import NoticePane from '@/components/molecules/NoticePane.vue'
import {useVolunteerMealOptions} from '@/composables/useVolunteerMealOptions'
import {usePublicTeamDataEntry} from '@/composables/usePublicTeamDataEntry'
import {eventPrograms, programDisplayName, programSlug} from '@/utils/eventPrograms'
import {visibleDrahtTeams, mergeTeams, hasSyncWork, syncButtonLabel} from '@/utils/teamSync'
import {
  applyJuryLanesForPlanSlots,
  buildJuryLaneByPlanSlot,
  formatJuryCell,
} from '@/utils/teamJury'
import {
  isTeamDataUnconfirmed,
  isTeamParticipantListIncomplete,
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
const publicEventUrl = computed(() => {
  const link = (eventStore.selectedEvent as {link?: string | null} | null)?.link
  return typeof link === 'string' ? link.trim() : ''
})

const teams = ref<TeamDataRow[]>([])
const columns = ref<TeamDataColumn[]>([])
const collectMeal = ref(true)
const loading = ref(false)
const columnsPanelOpen = ref(false)
const mealPanelOpen = ref(false)
const exportBusy = ref(false)
const showOnlyIncomplete = ref(false)
const showOnlyPhotoUnset = ref(false)
const showOnlyParticipantListIncomplete = ref(false)
const showOnlyUnconfirmed = ref(false)
const deadline = ref('')
const deadlineDraft = ref('')
const deadlineSaving = ref(false)
const nameFilter = ref('')
const activeProgramFilters = ref<Set<number>>(new Set())
const sortKey = ref<'plan' | 'team_number_hot' | 'name' | 'organization'>('plan')
const planById = ref<Record<number, {plan: number | null, noshow: boolean, jury: string, juryLane: number | null}>>({})
const remoteByProgram = ref<Record<number, Record<string, unknown>[]>>({})
const remoteTeamsLoaded = ref(false)
const syncingProgramId = ref<number | null>(null)
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

const formFieldsDialogOpen = ref(false)

async function onTeamDataEntryToggle(next: boolean) {
  try {
    const saved = await setTeamDataEntryEnabled(next)
    if (saved && next) {
      formFieldsDialogOpen.value = true
    }
  } catch {
    // toast from composable
  }
}

function textOrNull(value: unknown): string | null {
  const text = String(value ?? '').trim()
  return text === '' ? null : text
}

function remoteTeamFor(row: TeamDataRow): Record<string, unknown> | undefined {
  const programId = Number(row.first_program ?? 0)
  const number = Number(row.team_number_hot ?? 0)
  if (!programId || !number) return undefined
  return (remoteByProgram.value[programId] ?? []).find((team) => Number(team.number) === number)
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

  let rows = [...teams.value].map((row) => {
    const plan = planById.value[row.id]
    const remote = remoteTeamFor(row)
    return {
      ...row,
      organization: textOrNull(row.organization) ?? textOrNull(remote?.organization) ?? null,
      location: textOrNull(row.location) ?? textOrNull(remote?.location) ?? null,
      plan_number: plan?.plan ?? row.team_number_plan,
      noshow: plan?.noshow ?? false,
      jury_label: plan?.jury ?? '–',
    }
  })
  rows = rows.filter((row) => row.first_program != null && activeProgramFilters.value.has(row.first_program))

  const query = nameFilter.value.trim().toLowerCase()
  if (query) {
    rows = rows.filter((row) => {
      const haystack = [
        row.name,
        row.organization ?? '',
        row.location ?? '',
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
  if (showOnlyParticipantListIncomplete.value) {
    rows = rows.filter((row) => isTeamParticipantListIncomplete(row))
  }
  if (showOnlyUnconfirmed.value) {
    rows = rows.filter((row) => isTeamDataUnconfirmed(row))
  }

  const programOrder = new Map(programFilters.value.map((program, index) => [Number(program.first_program), index]))
  const dir = sortDir.value === 'asc' ? 1 : -1
  const key = sortKey.value
  rows.sort((a, b) => {
    const programDelta = (programOrder.get(Number(a.first_program)) ?? 99) - (programOrder.get(Number(b.first_program)) ?? 99)
    if (programDelta !== 0) return programDelta
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
    } else if (key === 'plan') {
      const an = a.plan_number ?? Number.MAX_SAFE_INTEGER
      const bn = b.plan_number ?? Number.MAX_SAFE_INTEGER
      if (an !== bn) return (an - bn) * dir
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

const visibleTeamNumbers = computed(() => {
  const nums = new Set<number>()
  for (const team of filteredTeams.value) {
    if (team.team_number_hot != null) nums.add(team.team_number_hot)
  }
  return [...nums]
})

const visibleProgramSlugs = computed(() => {
  if (!eventStore.selectedEvent) return []
  const set = activeProgramFilters.value
  const programs = eventPrograms(eventStore.selectedEvent)
  const slugs = programs
    .filter((p) => p.first_program != null && set.has(Number(p.first_program)))
    .map((p) => programSlug(p.name))
  // Deduplicate while preserving order.
  return [...new Set(slugs)]
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

const rowFilterActive = computed(() =>
  nameFilter.value.trim() !== ''
  || showOnlyPhotoUnset.value
  || showOnlyIncomplete.value
  || showOnlyParticipantListIncomplete.value
  || showOnlyUnconfirmed.value
)

const canReorder = computed(() =>
  sortKey.value === 'plan' && sortDir.value === 'asc' && !rowFilterActive.value
)

const syncByProgram = computed(() => {
  const map: Record<number, {
    label: string
    pending: Array<{key: string, label: string, number: string, name: string, organization: string}>
    missingIds: number[]
    buttonLabel: string
  }> = {}
  if (!remoteTeamsLoaded.value) return map
  for (const program of programFilters.value) {
    const programId = Number(program.first_program)
    const local = teams.value.filter((row) => row.first_program === programId)
    const remote = remoteByProgram.value[programId] ?? []
    const merged = mergeTeams(local as unknown as Record<string, unknown>[], remote)
    if (!hasSyncWork(merged)) continue
    const missingIds: number[] = []
    const pending: Array<{key: string, label: string, number: string, name: string, organization: string}> = []
    for (const row of merged) {
      if (row.status === 'missing' && row.local?.id != null) {
        missingIds.push(Number(row.local.id))
      }
      if (row.draht && (row.status === 'new' || row.status === 'conflict')) {
        const draht = row.draht
        pending.push({
          key: `${row.status}-${draht.id ?? draht.number ?? pending.length}`,
          label: row.status === 'conflict' ? 'Umbenannt' : 'Neu',
          number: draht.number != null ? String(draht.number) : '–',
          name: String(draht.name || '–'),
          organization: String(draht.organization || ''),
        })
      }
    }
    map[programId] = {
      label: programDisplayName(program),
      pending,
      missingIds,
      buttonLabel: syncButtonLabel(merged),
    }
  }
  return map
})

function toggleSort(key: 'plan' | 'team_number_hot' | 'name' | 'organization') {
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
    deadline.value = typeof data.team_data_deadline === 'string' ? data.team_data_deadline : ''
    deadlineDraft.value = deadline.value
    answersDeadline.value = typeof data.answers_deadline === 'string' && data.answers_deadline !== ''
      ? data.answers_deadline
      : deadline.value
    answersDeadlineDraft.value = answersDeadline.value
    collectMeal.value = data.collect?.meal !== false
    if (data.meal_options) {
      setMealOptions(data.meal_options)
    }
    syncProgramFilters()
    await Promise.all([attachPlan(), loadRemoteTeams()])
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Laden fehlgeschlagen'), 'error')
  } finally {
    loading.value = false
  }
}

const answersDeadline = ref('')
const answersDeadlineDraft = ref('')

async function attachPlan() {
  if (!eventId.value) return
  const next: Record<number, {plan: number | null, noshow: boolean, jury: string, juryLane: number | null}> = {}
  await Promise.all(programFilters.value.map(async (program) => {
    try {
      const {data} = await axios.get(`/events/${eventId.value}/teams`, {
        params: {program: programSlug(program.name), sort: 'plan_order'},
      })
      const list = Array.isArray(data) ? data : (data?.teams ?? [])
      for (const team of list) {
        const lane = team.jury_lane == null ? null : Number(team.jury_lane)
        next[Number(team.id)] = {
          plan: team.team_number_plan ?? null,
          noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1',
          jury: formatJuryCell(String(program.name ?? ''), lane),
          juryLane: lane != null && Number.isFinite(lane) ? lane : null,
        }
      }
    } catch {
      // A program without a plan still shows its team data.
    }
  }))
  planById.value = next
}

function teamListFromDraht(teams: unknown): unknown[] {
  if (Array.isArray(teams)) return teams
  if (teams && typeof teams === 'object') return Object.values(teams)
  return []
}

async function loadRemoteTeams() {
  if (!eventId.value) {
    remoteByProgram.value = {}
    remoteTeamsLoaded.value = false
    return
  }
  try {
    const {data} = await axios.get(`/events/${eventId.value}/draht-data`)
    const programs = Array.isArray(data?.programs) ? data.programs : []
    const next: Record<number, Record<string, unknown>[]> = {}
    for (const program of programFilters.value) {
      const programId = Number(program.first_program)
      const match = programs.find((row: {first_program?: number, name?: string}) =>
        Number(row.first_program) === programId
        || String(row.name || '').toUpperCase() === String(program.name || '').toUpperCase(),
      ) as {teams?: unknown} | undefined
      next[programId] = visibleDrahtTeams(teamListFromDraht(match?.teams).map((team) => {
        const row = team as {ref?: unknown, number?: unknown, team_number_hot?: unknown, name?: string, organization?: string, id?: unknown}
        return {
          number: row.ref ?? row.number ?? row.team_number_hot ?? null,
          name: row.name || '',
          organization: row.organization || '',
          id: row.id ?? null,
        }
      }))
    }
    remoteByProgram.value = next
    remoteTeamsLoaded.value = true
  } catch {
    remoteByProgram.value = {}
    remoteTeamsLoaded.value = false
  }
}

async function onReorder(programId: number, orderedIds: number[]) {
  if (!eventId.value || orderedIds.length === 0) return
  const program = programFilters.value.find((row) => Number(row.first_program) === programId)
  if (!program) return
  const current = teams.value
    .filter((row) => row.first_program === programId)
    .map((row) => ({
      id: row.id,
      team_number_plan: planById.value[row.id]?.plan ?? null,
      jury_lane: planById.value[row.id]?.juryLane ?? null,
    }))
  const slotMap = buildJuryLaneByPlanSlot(current)
  const byId = new Map(current.map((row) => [row.id, row]))
  const ordered = orderedIds
    .map((id, index) => {
      const row = byId.get(id)
      if (!row) return null
      return {...row, team_number_plan: index + 1}
    })
    .filter((row): row is NonNullable<typeof row> => row != null)
  const withLanes = applyJuryLanesForPlanSlots(ordered, slotMap)
  const next = {...planById.value}
  for (const row of withLanes) {
    const previous = next[row.id]
    next[row.id] = {
      plan: row.team_number_plan,
      noshow: previous?.noshow ?? false,
      jury: formatJuryCell(String(program.name ?? ''), row.jury_lane),
      juryLane: row.jury_lane ?? null,
    }
  }
  planById.value = next
  try {
    await axios.post(`/events/${eventId.value}/teams/update-order`, {
      program: programSlug(program.name),
      order: withLanes.map((row) => ({
        team_id: row.id,
        order: row.team_number_plan,
      })),
    })
    await eventStore.updateTeamDiscrepancyStatus()
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Reihenfolge konnte nicht gespeichert werden'), 'error')
    await attachPlan()
  }
}

async function onSyncProgram(programId: number) {
  if (!eventId.value || syncingProgramId.value != null) return
  const program = programFilters.value.find((row) => Number(row.first_program) === programId)
  if (!program) return
  syncingProgramId.value = programId
  try {
    await axios.post(`/events/${eventId.value}/teams/sync`, {
      program: programSlug(program.name),
    })
    await load()
    await eventStore.updateTeamDiscrepancyStatus()
    await eventStore.refreshReadiness(eventId.value)
    showGlassToast('Abgleich abgeschlossen', 'success')
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Abgleich fehlgeschlagen'), 'error')
  } finally {
    syncingProgramId.value = null
  }
}
async function onNoshow(team: TeamDataRow, noshow: boolean) {
  if (!eventId.value) return
  planById.value = {
    ...planById.value,
    [team.id]: {
      ...(planById.value[team.id] ?? {
        plan: team.plan_number ?? null,
        jury: team.jury_label ?? '–',
        juryLane: null,
        noshow,
      }),
      noshow,
    },
  }
  try {
    await axios.put(`/events/${eventId.value}/teams`, {id: team.id, noshow: noshow ? 1 : 0})
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'No-show konnte nicht gespeichert werden'), 'error')
    await attachPlan()
  }
}

const deadlineUnchanged = computed(() =>
  (deadlineDraft.value === '' || deadlineDraft.value === deadline.value)
  && (answersDeadlineDraft.value === '' || answersDeadlineDraft.value === answersDeadline.value),
)

async function saveDeadline() {
  if (!eventId.value || deadlineSaving.value || deadlineUnchanged.value) return
  deadlineSaving.value = true
  try {
    const {data} = await axios.put(`/events/${eventId.value}/team-data-deadline`, {
      deadline: deadlineDraft.value,
      answers_deadline: answersDeadlineDraft.value,
    })
    deadline.value = data.team_data_deadline ?? deadlineDraft.value
    deadlineDraft.value = deadline.value
    answersDeadline.value = data.answers_deadline ?? answersDeadlineDraft.value
    answersDeadlineDraft.value = answersDeadline.value
    if (data.changed) {
      showGlassToast('Frist gespeichert. Die Info-Mail ist raus.')
    } else {
      showGlassToast('Frist gespeichert')
    }
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Frist konnte nicht gespeichert werden'), 'error')
  } finally {
    deadlineSaving.value = false
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
        <div class="vol-page__title-row">
          <h1 class="vol-page__title">Teams</h1>
          <ScreenHelpButton/>
        </div>
        <p class="vol-page__sub">Bitte prüfe, ob die Teamdaten vollständig sind.</p>
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
        <TeamsEmailOutreach :program-slugs="visibleProgramSlugs" :team-numbers="visibleTeamNumbers"/>
      </div>
    </header>

    <NoticePane/>

    <div class="teams-overview">
      <section class="glass-card liquid-surface-inner vol-sidebar-tile vol-roster-publish">
        <div class="vol-roster-publish__row">
          <span class="vol-roster-publish__label">Dateneingabe durch Coaches</span>
          <ToggleSwitch
              :model-value="teamDataEntryEnabled"
              :disabled="teamDataEntryLoading || !eventId"
              @update:modelValue="onTeamDataEntryToggle"
          />
        </div>
        <p class="glass-settings-hint vol-roster-publish__hint">
          Dateneingabe erfolgt im Coach-Login in JOIN.
          <a
              v-if="publicEventUrl"
              :href="publicEventUrl"
              class="vol-roster-publish__link"
              target="_blank"
              rel="noopener"
          >Die öffentliche Seite des Events</a><template v-else>Die öffentliche Seite des Events</template>
          führt dahin, wenn du sie einschaltest.
        </p>
        <div class="vol-roster-publish__deadlines">
          <div class="vol-roster-publish__deadline">
            <label for="team-data-deadline">Frist Teilnehmende</label>
            <input
                id="team-data-deadline"
                v-model="deadlineDraft"
                type="date"
                class="glass-input glass-input--sm vol-roster-publish__date"
                :disabled="!eventId || deadlineSaving"
            >
          </div>
          <div class="vol-roster-publish__deadline">
            <label for="answers-deadline">Frist übrige Angaben</label>
            <input
                id="answers-deadline"
                v-model="answersDeadlineDraft"
                type="date"
                class="glass-input glass-input--sm vol-roster-publish__date"
                :disabled="!eventId || deadlineSaving"
            >
          </div>
          <button
              type="button"
              class="glass-btn-secondary vol-roster-publish__deadline-save"
              :disabled="!eventId || deadlineSaving || deadlineUnchanged"
              @click="saveDeadline"
          >
            {{ deadlineSaving ? 'Speichern…' : 'Fristen speichern' }}
          </button>
        </div>
        <p class="glass-settings-hint !mb-0 vol-roster-publish__hint">
          Teilnehmende: Name, Geschlecht, Geburtsdatum. Übrige Angaben: Foto und Essen pro Person. Gleiches Datum ist der Standard. Eine Änderung der Teilnehmenden-Frist geht an info@hands-on-technology.org und an Coaches, die „Teilnehmende komplett“ noch nicht bestätigt haben.
        </p>
      </section>
      <TeamsRegistrationStats/>
      <TeamsMultiTeamCoaches/>
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
          <template v-if="programFilters.length > 1">
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
            <span class="vol-staffing-filter__label">Antworten unvollständig</span>
          </button>
          <button
              type="button"
              class="vol-staffing-filter"
              :class="{'vol-staffing-filter--active': showOnlyParticipantListIncomplete}"
              :aria-pressed="showOnlyParticipantListIncomplete"
              title="Nur Teams ohne Teilnehmer:innen in der Anmeldung anzeigen"
              @click="showOnlyParticipantListIncomplete = !showOnlyParticipantListIncomplete"
          >
            <i class="bi bi-people vol-staffing-filter__icon" aria-hidden="true"/>
            <span class="vol-staffing-filter__label">Teilnehmerliste unvollständig</span>
          </button>
          <button
              type="button"
              class="vol-staffing-filter"
              :class="{'vol-staffing-filter--active': showOnlyUnconfirmed}"
              :aria-pressed="showOnlyUnconfirmed"
              title="Nur Teams ohne Bestätigung „Teilnehmende komplett“ anzeigen"
              @click="showOnlyUnconfirmed = !showOnlyUnconfirmed"
          >
            <i class="bi bi-check2-square vol-staffing-filter__icon" aria-hidden="true"/>
            <span class="vol-staffing-filter__label">Bestätigung fehlt</span>
          </button>
          <span class="vol-toolbar__count vol-staffing-filters__count">
            {{ filteredTeams.length }} / {{ teams.length }}
          </span>
        </template>
      </VolunteerStaffingFilterBar>

      <p v-if="loading" class="vol-muted inline-flex items-center gap-2">
        <Spinner size="sm"/>
        <span>Laden…</span>
      </p>
      <p v-else-if="!teams.length" class="vol-muted">Keine Teams vorhanden.</p>
      <p v-else-if="!filteredTeams.length" class="vol-muted">Keine Teams für die gewählten Filter.</p>
      <TeamDataTable
          v-else
          :event-id="eventId"
          :teams="filteredTeams"
          :columns="columns"
          :sort-key="sortKey"
          :sort-dir="sortDir"
          :can-reorder="canReorder"
          :sync-by-program="syncByProgram"
          :syncing-program-id="syncingProgramId"
          @toggle-sort="toggleSort"
          @open-count="openCountPopover"
          @updated="onTeamUpdated"
          @noshow="onNoshow"
          @reorder="onReorder"
          @sync="onSyncProgram"
      />
    </section>

    <TeamDataColumnsPanel
        :open="columnsPanelOpen"
        :event-id="eventId"
        @close="columnsPanelOpen = false"
        @changed="load"
    />

    <PublicFormFieldsDialog
        :open="formFieldsDialogOpen"
        :event-id="eventId"
        kind="team"
        @close="formFieldsDialogOpen = false"
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

<style scoped>
.teams-overview {
  display: flex;
  align-items: stretch;
  gap: 0.75rem;
  flex-shrink: 0;
}

.teams-overview :deep(.glass-card) {
  flex: 1 1 0;
  min-width: 0;
  margin: 0;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.96);
}

.teams-overview :deep(.vol-sidebar-heading) {
  margin-bottom: 0.45rem;
}

.teams-overview :deep(.vol-roster-publish__deadlines) {
  flex-direction: column;
  align-items: flex-start;
  gap: 0.45rem;
  margin-top: 0.65rem;
}

.teams-overview :deep(.vol-roster-publish__deadline) {
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem 0.65rem;
}

.teams-overview :deep(input.vol-roster-publish__date.glass-input) {
  width: 9.5rem;
  min-width: 9.5rem;
  max-width: 9.5rem;
  height: 1.85rem;
  min-height: 1.85rem;
  max-height: 1.85rem;
  flex: none;
  padding: 0 0.35rem 0 0.5rem;
}

.teams-overview :deep(.vol-roster-publish__deadline-save) {
  margin-left: 0;
  margin-top: 0.1rem;
}

.teams-overview :deep(.teams-multi-team-coaches__list) {
  max-height: 7.5rem;
  overflow: auto;
}

@media (max-width: 768px) {
  .teams-overview {
    flex-direction: column;
  }
}
</style>

