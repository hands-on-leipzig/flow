<script setup>
import {computed, ref, onMounted} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import SavingToast from '@/components/atoms/SavingToast.vue'
import TeamsSyncTables from '@/components/teams/TeamsSyncTables.vue'
import {showGlassToast} from '@/composables/useGlassToast'
import {drahtIdFor, programMatchesSlug} from '@/utils/eventPrograms'
import {getProgramTheme} from '@/utils/programTheme'
import {visibleDrahtTeams} from '@/utils/teamSync'
import {
  applyJuryLanesForPlanSlots,
  buildJuryLaneByPlanSlot,
} from '@/utils/teamJury'

const props = defineProps({
  program: {type: String, required: true},
  remoteTeams: {type: Array, default: () => []},
  remoteCapacity: {type: Number, default: 0},
})

const isExplore = computed(() => programMatchesSlug(props.program, 'explore'))
const isChallenge = computed(() => programMatchesSlug(props.program, 'challenge'))
const isFuture8 = computed(() => programMatchesSlug(props.program, 'future_8'))

const programTheme = computed(() => getProgramTheme(props.program))
const programLabel = computed(() => programTheme.value.shortName)

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const teamList = ref([])
const juryLaneByPlanSlot = ref({})
const savingToast = ref(null)
const syncing = ref(false)

const peopleData = ref({})
const expandedTeams = ref(new Set())

const planParams = ref({
  c_teams: 0,
  e_teams: 0,
  e1_teams: 0,
  e_mode: 0,
  f8_teams: 0,
})

const visibleRemoteTeams = computed(() => visibleDrahtTeams(props.remoteTeams))

const planCapacity = computed(() => {
  if (isExplore.value) return planParams.value.e_teams
  if (isChallenge.value) return planParams.value.c_teams
  if (isFuture8.value) return planParams.value.f8_teams
  return visibleRemoteTeams.value.length
})

const enrolledCount = computed(() => visibleRemoteTeams.value.length)
const venueCapacity = computed(() => Number(props.remoteCapacity || 0))

const teamsBeyondCapacity = computed(() => teamList.value.length > planCapacity.value)

const hasTwoExploreGroups = computed(() =>
  isExplore.value && (planParams.value.e_mode === 5 || planParams.value.e_mode === 8),
)

function normalizeTeamsResponse(data) {
  const teamsArray = Array.isArray(data) ? data : (data.teams || [])
  return teamsArray.map((team) => ({
    ...team,
    noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1',
  }))
}

async function reloadTeams() {
  const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}&sort=plan_order`)
  const teams = normalizeTeamsResponse(dbRes.data)
  juryLaneByPlanSlot.value = buildJuryLaneByPlanSlot(teams)
  teamList.value = teams
}

const onSort = async () => {
  const slotMap = Object.keys(juryLaneByPlanSlot.value).length
    ? juryLaneByPlanSlot.value
    : buildJuryLaneByPlanSlot(teamList.value)

  teamList.value = applyJuryLanesForPlanSlots(
    teamList.value.map((team, index) => ({
      ...team,
      team_number_plan: index + 1,
    })),
    slotMap,
  )

  const payload = teamList.value.map((team, index) => ({
    team_id: team.id,
    order: index + 1,
  }))

  savingToast?.value?.show()

  try {
    await axios.post(`/events/${event.value?.id}/teams/update-order`, {
      program: props.program,
      order: payload,
    })
    await eventStore.updateTeamDiscrepancyStatus()
    await reloadTeams()
  } catch (e) {
    if (import.meta.env.DEV) console.error('Order update failed', e)
  }
}

const updateTeamNoshow = async (team) => {
  savingToast?.value?.show()
  try {
    await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.id,
      noshow: team.noshow ? 1 : 0,
    })
    await eventStore.updateTeamDiscrepancyStatus()
  } catch (e) {
    if (import.meta.env.DEV) console.error(`Failed to update team noshow for ${team.id}`, e)
  }
}

async function runSync() {
  if (syncing.value) return
  syncing.value = true
  savingToast?.value?.show()
  try {
    await axios.post(`/events/${event.value?.id}/teams/sync`, {program: props.program})
    await reloadTeams()
    await eventStore.updateTeamDiscrepancyStatus()
    showGlassToast('Abgleich abgeschlossen', 'success')
  } catch (e) {
    showGlassToast(
      'Abgleich fehlgeschlagen: ' + (e.response?.data?.error || e.message),
      'error',
    )
  } finally {
    syncing.value = false
    savingToast?.value?.hide()
  }
}

function getTeamGroup(team) {
  if (!hasTwoExploreGroups.value || planParams.value.e1_teams <= 0) return null
  const teamNumberPlan = team?.team_number_plan || 0
  return teamNumberPlan <= planParams.value.e1_teams ? 'morning' : 'afternoon'
}

function getTeamBorderStyle(team) {
  const group = getTeamGroup(team)
  if (group === 'morning') return 'border-left-color: #1e40af;'
  if (group === 'afternoon') return 'border-left-color: #93c5fd;'
  return ''
}

function getDrahtTeamNumber(team) {
  if (team.team_number_hot) return String(team.team_number_hot)
  if (team.number != null && team.number !== '') return String(team.number)
  const remoteTeam = visibleRemoteTeams.value.find(
    (rt) => rt.id === team.id || (rt.name === team.name && rt.number),
  )
  return remoteTeam?.number ? String(remoteTeam.number) : null
}

function getTeamPeopleData(team) {
  const teamNumber = getDrahtTeamNumber(team)
  if (!teamNumber) return null
  return peopleData.value[teamNumber] ?? peopleData.value[Number(teamNumber)] ?? null
}

function getCoachCount(team) {
  const data = getTeamPeopleData(team)
  return data ? (data.num_coaches ?? data.coaches?.length ?? 0) : null
}

function getMemberCount(team) {
  const data = getTeamPeopleData(team)
  return data ? (data.num_players ?? data.players?.length ?? 0) : null
}

function getCoachNames(team) {
  const data = getTeamPeopleData(team)
  if (!data?.coaches?.length) return []
  return data.coaches
    .map((coach) => {
      if (typeof coach === 'string') return coach.trim() || null
      if (!coach || typeof coach !== 'object') return null
      const name = [coach.firstname, coach.name].filter(Boolean).join(' ').trim()
      return name || null
    })
    .filter(Boolean)
}

function toggleTeamExpansion(team) {
  const teamNumber = getDrahtTeamNumber(team)
  if (!teamNumber) return
  if (expandedTeams.value.has(teamNumber)) expandedTeams.value.delete(teamNumber)
  else expandedTeams.value.add(teamNumber)
}

function isTeamExpanded(team) {
  const teamNumber = getDrahtTeamNumber(team)
  return teamNumber && expandedTeams.value.has(teamNumber)
}

function formatBirthday(timestamp) {
  if (!timestamp || timestamp === false) return 'N/A'
  return new Date(timestamp * 1000).toLocaleDateString('de-DE')
}

async function copyToClipboard(text, type) {
  if (!text) return
  try {
    await navigator.clipboard.writeText(text)
    showGlassToast(`${type} kopiert!`, 'success')
  } catch {
    showGlassToast('Zwischenablage nicht verfügbar', 'error')
  }
}

onMounted(async () => {
  try {
    try {
      const planRes = await axios.get(`/plans/public/${event.value?.id}`)
      const planId = planRes.data?.id
      if (planId) {
        const paramsRes = await axios.get(`/plans/${planId}/parameters`)
        const params = Array.isArray(paramsRes.data) ? paramsRes.data : []
        planParams.value = {
          c_teams: Number(params.find((p) => p.name === 'c_teams')?.value || 0),
          e_teams: Number(params.find((p) => p.name === 'e_teams')?.value || 0),
          e1_teams: Number(params.find((p) => p.name === 'e1_teams')?.value || 0),
          e_mode: Number(params.find((p) => p.name === 'e_mode')?.value || 0),
          f8_teams: Number(params.find((p) => p.name === 'f8_teams')?.value || 0),
        }
      }
    } catch (paramErr) {
      if (import.meta.env.DEV) console.debug('Failed to fetch plan parameters', paramErr)
    }

    await reloadTeams()

    const drahtEventId = drahtIdFor(event.value, props.program)
    if (drahtEventId) {
      try {
        const peopleRes = await axios.get(`/draht/people/${drahtEventId}`)
        if (peopleRes.data) {
          const {total_players, total_coaches, ...teamsData} = peopleRes.data
          peopleData.value = teamsData
        }
      } catch (peopleErr) {
        if (import.meta.env.DEV) console.error('Failed to fetch people data', peopleErr)
      }
    }
  } catch (err) {
    if (import.meta.env.DEV) console.error('Failed to fetch teams', err)
  }
})
</script>

<template>
  <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>

  <div class="team-list">
    <div class="team-list__header">
      <div class="team-list__meta">
        <ProgramLogo :program="program" size="xl"/>
        <div class="team-list__meta-text">
          <h2 class="team-list__title text-lg font-semibold">
            <span class="italic">FIRST</span> LEGO League {{ programLabel }}
          </h2>
          <p class="team-list__stats text-sm text-[var(--color-text-subtle)]">
            <span :class="planCapacity !== enrolledCount ? 'bg-amber-50 px-1.5 py-0.5 rounded-md text-amber-950 font-medium' : ''">
              Angemeldet: {{ enrolledCount }}
            </span>,
            <span :class="planCapacity !== enrolledCount ? 'bg-amber-50 px-1.5 py-0.5 rounded-md text-amber-950 font-medium' : ''">
              Plan für: {{ planCapacity }}
            </span>,
            Kapazität: {{ venueCapacity }}
          </p>
          <div v-if="hasTwoExploreGroups" class="team-list__legend">
            <span class="flex items-center gap-1">
              <span class="w-6 h-4 rounded" style="background-color: #1e40af;"/>
              <span style="color: #1e40af;">Vormittag</span>
            </span>
            <span class="flex items-center gap-1">
              <span class="w-6 h-4 rounded" style="background-color: #93c5fd;"/>
              <span style="color: #93c5fd;">Nachmittag</span>
            </span>
          </div>
        </div>
      </div>

      <p class="team-list__note vol-muted">
        „No-show“-Teams bleiben im Plan, werden aber in allen Ausgaben durchgestrichen dargestellt.
      </p>
    </div>

    <TeamsSyncTables
        v-model:team-list="teamList"
        :program="program"
        :remote-teams="remoteTeams"
        :plan-capacity="planCapacity"
        :teams-beyond-capacity="teamsBeyondCapacity"
        :has-two-explore-groups="hasTwoExploreGroups"
        :e1-teams="planParams.e1_teams"
        :show-jury="true"
        :syncing="syncing"
        :get-coach-count="getCoachCount"
        :get-member-count="getMemberCount"
        :get-coach-names="getCoachNames"
        :get-team-people-data="getTeamPeopleData"
        :is-team-expanded="isTeamExpanded"
        :get-team-border-style="getTeamBorderStyle"
        :get-team-group="getTeamGroup"
        :format-birthday="formatBirthday"
        @sort="onSort"
        @update-noshow="updateTeamNoshow"
        @toggle="toggleTeamExpansion"
        @copy="copyToClipboard"
        @sync="runSync"
    />
  </div>
</template>

<style scoped>
.team-list__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.team-list__meta {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  min-width: 0;
  flex: 1;
}

.team-list__meta-text {
  min-width: 0;
}

.team-list__title {
  margin: 0 0 0.25rem;
}

.team-list__stats {
  margin: 0;
}

.team-list__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 0.35rem;
  font-size: 0.875rem;
}

.team-list__note {
  margin: 0;
  max-width: 13rem;
  font-size: 0.75rem;
  line-height: 1.35;
  text-align: right;
  flex-shrink: 0;
}

@media (max-width: 639px) {
  .team-list__header {
    flex-direction: column;
  }

  .team-list__note {
    max-width: none;
    text-align: left;
  }
}
</style>
