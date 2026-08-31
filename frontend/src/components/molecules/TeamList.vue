<script setup>
import {computed, ref, onMounted} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import SavingToast from '@/components/atoms/SavingToast.vue'
import TeamsSyncTables from '@/components/teams/TeamsSyncTables.vue'
import TeamsRegistrationStats from '@/components/teams/TeamsRegistrationStats.vue'
import TeamsEmailOutreach from '@/components/teams/TeamsEmailOutreach.vue'
import {showGlassToast} from '@/composables/useGlassToast'
import {drahtIdFor, programMatchesSlug} from '@/utils/eventPrograms'
import {getProgramTheme} from '@/utils/programTheme'
import {visibleDrahtTeams} from '@/utils/teamSync'

const props = defineProps({
  program: {type: String, required: true},
  remoteTeams: {type: Array, default: () => []},
  remoteCapacity: {type: Number, default: 0},
  split: {type: Boolean, default: false},
})

const isExplore = computed(() => programMatchesSlug(props.program, 'explore'))
const isChallenge = computed(() => programMatchesSlug(props.program, 'challenge'))
const isFuture8 = computed(() => programMatchesSlug(props.program, 'future_8'))

const programTheme = computed(() => getProgramTheme(props.program))
const programLabel = computed(() => programTheme.value.shortName)

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const teamList = ref([])
const savingToast = ref(null)
const syncing = ref(false)

const peopleData = ref({})
const expandedTeams = ref(new Set())
const totalPlayers = ref(0)
const totalCoaches = ref(0)

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
  teamList.value = normalizeTeamsResponse(dbRes.data)
}

const onSort = async () => {
  teamList.value = teamList.value.map((team, index) => ({
    ...team,
    team_number_plan: index + 1,
  }))

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
  const remoteTeam = visibleRemoteTeams.value.find(
    (rt) => rt.id === team.id || (rt.name === team.name && rt.number),
  )
  return remoteTeam?.number ? String(remoteTeam.number) : null
}

function getTeamPeopleData(team) {
  const teamNumber = getDrahtTeamNumber(team)
  if (!teamNumber || !peopleData.value[teamNumber]) return null
  return peopleData.value[teamNumber]
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
          totalPlayers.value = peopleRes.data.total_players || 0
          totalCoaches.value = peopleRes.data.total_coaches || 0
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

  <div class="team-list" :class="{'team-list--split': split}">
    <div class="team-list__main glass-card liquid-surface-inner">
      <div class="flex items-start sm:items-center gap-2 mb-2">
        <ProgramLogo :program="program" size="xl"/>
        <div>
          <h3 class="text-lg font-semibold">
            <span class="italic">FIRST</span> LEGO League {{ programLabel }}
          </h3>
          <div class="text-sm text-[var(--color-text-subtle)] flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
            <span>
              <span :class="planCapacity !== enrolledCount ? 'bg-amber-50 px-1.5 py-0.5 rounded-md text-amber-950 font-medium' : ''">
                Angemeldet: {{ enrolledCount }}
              </span>,
              <span :class="planCapacity !== enrolledCount ? 'bg-amber-50 px-1.5 py-0.5 rounded-md text-amber-950 font-medium' : ''">
                Plan für: {{ planCapacity }}
              </span>,
              Kapazität: {{ venueCapacity }}
            </span>
            <template v-if="hasTwoExploreGroups">
              <span class="flex items-center gap-1">
                <span class="w-6 h-4 rounded" style="background-color: #1e40af;"/>
                <span style="color: #1e40af;">Vormittag</span>
              </span>
              <span class="flex items-center gap-1">
                <span class="w-6 h-4 rounded" style="background-color: #93c5fd;"/>
                <span style="color: #93c5fd;">Nachmittag</span>
              </span>
            </template>
          </div>
        </div>
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
          :total-coaches="totalCoaches"
          :total-members="totalPlayers"
          :people-data="peopleData"
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

      <div v-if="!split" class="mt-4 text-xs text-[var(--color-text-muted)] italic">
        "No-show" Teams bleiben im Plan, werden aber in allen Ausgaben "durchgestrichen" dargestellt.
      </div>
    </div>

    <aside v-if="split" class="team-list__aside glass-card liquid-surface-inner">
      <h2 class="text-sm font-semibold tracking-wide uppercase text-[var(--color-text-muted)] mb-3">
        Export & Funktionen
      </h2>

      <div class="space-y-4">
        <TeamsRegistrationStats/>

        <div>
          <div class="text-xs font-semibold uppercase tracking-wide text-[var(--color-text-muted)] mb-2">
            Export
          </div>
          <TeamsEmailOutreach :current-program="program"/>
        </div>

        <div class="text-xs text-[var(--color-text-muted)] italic leading-relaxed">
          "No-show" Teams bleiben im Plan, werden aber in allen Ausgaben "durchgestrichen" dargestellt.
        </div>
      </div>
    </aside>
  </div>
</template>

<style scoped>
.team-list {
  min-height: 0;
}

.team-list--split {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
  align-items: start;
  height: 100%;
}

@media (min-width: 960px) {
  .team-list--split {
    grid-template-columns: minmax(0, 2fr) minmax(16rem, 1fr);
  }

  .team-list--split .team-list__main {
    max-height: calc(100dvh - 8rem);
    overflow-y: auto;
  }

  .team-list--split .team-list__aside {
    position: sticky;
    top: 0.25rem;
  }
}

.team-list__main,
.team-list__aside {
  min-width: 0;
}
</style>
