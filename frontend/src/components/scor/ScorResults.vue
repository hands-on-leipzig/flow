<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'

const props = defineProps<{results: any}>()
const emit = defineEmits<{saved: [results: any]}>()

const awardError = ref('')
const mailNotice = ref('')
const awardBusy = ref(false)
const trophies = ref<{team: number, category: string}[]>([])
const nominations = ref<{team: number, category: string}[]>([])
const coaching = ref<number | ''>('')

const judgedTrophies = [
  {id: 'research', label: 'Projekt'},
  {id: 'robotdesign', label: 'Technisches Design'},
  {id: 'core_values', label: 'Grundwerte'},
]
const nominationColumns = [
  {id: 'research', label: 'Projekt'},
  {id: 'robotdesign', label: 'Technisches Design'},
  {id: 'core_values', label: 'Grundwerte'},
]
const judgedIds = ['champion', 'research', 'robotdesign', 'core_values']

const awardTeams = computed(() => {
  return [...(props.results?.teams || [])].sort((a, b) => {
    if (a.champion_rank !== b.champion_rank) return a.champion_rank - b.champion_rank
    return Number(a.number ?? 0) - Number(b.number ?? 0)
  })
})

function heldTrophy(teamId: number) {
  return trophies.value.find((row) => row.team === teamId && judgedIds.includes(row.category))
}

function nominationCount(category: string) {
  return nominations.value.filter((row) => row.category === category).length
}

type CellState = 'on' | 'open' | 'wait' | 'locked' | 'taken' | 'full'

function trophyState(team: any, category: string): CellState {
  const on = marked(trophies.value, team.id, category)
  if (!props.results?.can_edit_awards) return on ? 'on' : 'locked'
  if (category !== 'champion' && !championTeam.value) return 'wait'
  if (category === 'champion' && team.champion_rank !== 1 && !on) return 'locked'
  if (category !== 'champion' && category !== 'robot') {
    const held = heldTrophy(team.id)
    if (held && held.category !== category) return 'taken'
  }
  return on ? 'on' : 'open'
}

function nominationState(team: any, category: string): CellState {
  const on = marked(nominations.value, team.id, category)
  if (!props.results?.can_edit_awards) return on ? 'on' : 'locked'
  if (!championTeam.value) return 'wait'
  if (!on && nominationCount(category) >= 4) return 'full'
  return on ? 'on' : 'open'
}

function coachingState(team: any): CellState {
  const on = coaching.value === team.id
  if (!props.results?.can_edit_awards) return on ? 'on' : 'locked'
  if (!championTeam.value) return 'wait'
  return on ? 'on' : 'open'
}

function pickTrophy(team: any, category: string) {
  const state = trophyState(team, category)
  if (state === 'wait' || state === 'locked' || state === 'taken') return
  if (category === 'champion') {
    championTeam.value = state === 'on' ? '' : team.id
    return
  }
  trophies.value = trophies.value.filter((row) => row.category !== category)
  if (state !== 'on') trophies.value.push({team: team.id, category})
}

function pickNomination(team: any, category: string) {
  const state = nominationState(team, category)
  if (state === 'wait' || state === 'locked' || state === 'full') return
  toggleMark(nominations.value, team.id, category)
}

function pickCoaching(team: any) {
  const state = coachingState(team)
  if (state === 'wait' || state === 'locked') return
  coaching.value = state === 'on' ? '' : team.id
}

watch(() => props.results, (results) => {
  trophies.value = [...(results?.awards?.trophies || [])]
  nominations.value = [...(results?.awards?.nominations || [])]
  coaching.value = results?.awards?.coaching || ''
  awardError.value = ''
}, {immediate: true})

function marked(rows: {team: number, category: string}[], team: number, category: string) {
  return rows.some((row) => row.team === team && row.category === category)
}

function toggleMark(rows: {team: number, category: string}[], team: number, category: string) {
  const index = rows.findIndex((row) => row.team === team && row.category === category)
  if (index >= 0) rows.splice(index, 1)
  else rows.push({team, category})
}

async function closeEvent() {
  const {data} = await axios.post(`/scor/events/${props.results.event.id}/close`)
  emit('saved', {...props.results, lifecycle: data})
}

async function releaseEvent() {
  const {data} = await axios.post(`/scor/events/${props.results.event.id}/release`)
  emit('saved', {...props.results, lifecycle: data})
}

async function reopenEvent() {
  const {data} = await axios.post(`/scor/events/${props.results.event.id}/reopen`)
  emit('saved', {...props.results, lifecycle: data})
}

async function sendMail() {
  mailNotice.value = ''
  try {
    const {data} = await axios.post(`/scor/events/${props.results.event.id}/coach-mail`)
    mailNotice.value = data.sent
        ? `${data.sent} Mails gesendet.`
        : 'Keine Coach-Adresse gefunden.'
    if (data.missing?.length) mailNotice.value += ' Ohne Adresse: '+data.missing.join(', ')
  } catch (e: any) {
    mailNotice.value = e?.response?.data?.error || 'Mail fehlgeschlagen.'
  }
}

async function saveAwards() {
  awardError.value = ''
  awardBusy.value = true
  try {
    const {data} = await axios.put(`/scor/events/${props.results.event.id}/awards`, {
      trophies: trophies.value,
      nominations: nominations.value,
      coaching: coaching.value || null,
    })
    emit('saved', data)
  } catch (e: any) {
    awardError.value = e?.response?.data?.error || 'Speichern fehlgeschlagen.'
  } finally {
    awardBusy.value = false
  }
}

const sortKey = ref('champion_rank')
const sortDir = ref<'asc' | 'desc'>('asc')

const columns = [
  {key: 'champion_rank', label: 'Champion', note: '*'},
  {key: 'robot_rank', label: 'Spiel', note: '**', points: 'robot'},
  {key: 'research_rank', label: 'Projekt', points: 'research'},
  {key: 'robotdesign_rank', label: 'Technisches Design', points: 'robotdesign'},
  {key: 'core_values_rank', label: 'Grundwerte', points: 'core_values'},
]

const ranked = computed(() => {
  const teams = [...(props.results?.teams || [])]
  const key = sortKey.value
  const dir = sortDir.value === 'asc' ? 1 : -1
  teams.sort((a, b) => {
    const left = key === 'number' ? Number(a.number ?? 0) : Number(a[key] ?? 0)
    const right = key === 'number' ? Number(b.number ?? 0) : Number(b[key] ?? 0)
    if (left !== right) return (left - right) * dir
    return Number(a.number ?? 0) - Number(b.number ?? 0)
  })
  return teams
})

const byRobot = computed(() => {
  return [...(props.results?.teams || [])].sort((a, b) => {
    if (a.robot_rank !== b.robot_rank) return a.robot_rank - b.robot_rank
    return Number(a.number ?? 0) - Number(b.number ?? 0)
  })
})

function toggleSort(key: string) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    return
  }
  sortKey.value = key
  sortDir.value = 'asc'
}

function sortLabel(key: string) {
  if (sortKey.value !== key) return 'sortieren'
  return sortDir.value === 'asc' ? 'aufsteigend sortiert' : 'absteigend sortiert'
}

function roundCell(team: any, round: number) {
  return (team.rounds || []).find((cell: any) => cell && cell.round === round) || null
}

const championTeam = computed({
  get: () => trophies.value.find((row) => row.category === 'champion')?.team || '',
  set: (team: number | '') => {
    trophies.value = trophies.value.filter((row) => row.category !== 'champion')
    if (team) trophies.value.push({team: Number(team), category: 'champion'})
  },
})

function cellClass(state: CellState) {
  return {
    'is-on': state === 'on',
    'is-wait': state === 'wait' || state === 'locked' || state === 'taken' || state === 'full',
  }
}

function pickDisabled(state: CellState) {
  return state === 'wait' || state === 'locked' || state === 'taken' || state === 'full'
}

function pickLabel(state: CellState, onLabel: string) {
  if (state === 'on') return onLabel
  if (state === 'taken') return 'hat Pokal'
  if (state === 'wait') return 'wartet'
  if (state === 'full') return '4 voll'
  if (state === 'locked') return '–'
  return 'wählen'
}

function formatPoints(value: number) {
  if (!Number.isFinite(value)) return '–'
  if (Number.isInteger(value)) return String(value)
  return value.toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1})
}
</script>

<template>
  <div v-if="!results?.teams?.length" class="text-lg text-[var(--color-text-muted)]">
    Keine Teams im Matchplan.
  </div>

  <div v-else class="scor-results">
    <section class="scor-results__block">
      <h2 class="scor-results__title">Gesamtwertung</h2>
      <div class="scor-board glass-card liquid-surface-inner">
        <table>
          <thead>
            <tr>
              <th scope="col">
                <button type="button" class="scor-sort" :class="{ 'is-active': sortKey === 'number' }" :aria-label="`Team, ${sortLabel('number')}`" @click="toggleSort('number')">
                  Team
                  <i v-if="sortKey === 'number'" class="bi" :class="sortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill'" aria-hidden="true"/>
                </button>
              </th>
              <th v-for="column in columns" :key="column.key" scope="col">
                <button type="button" class="scor-sort" :class="{ 'is-active': sortKey === column.key }" :aria-label="`${column.label}, ${sortLabel(column.key)}`" @click="toggleSort(column.key)">
                  {{ column.label }}<sup v-if="column.note">{{ column.note }}</sup>
                  <i v-if="sortKey === column.key" class="bi" :class="sortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill'" aria-hidden="true"/>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="team in ranked" :key="team.id">
              <th scope="row">
                <span class="scor-team__name">{{ team.name }}</span>
                <span class="scor-team__number">{{ team.number }}</span>
              </th>
              <td v-for="column in columns" :key="column.key">
                <span class="scor-place">{{ team[column.key] }}</span>
                <span v-if="column.points" class="scor-place__points">{{ formatPoints(team[column.points]) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="scor-results__note">
        * Gesamtwertung aus Spiel, Projekt, Technisches Design und Grundwerten.
        Gleiche Punktzahl, gleicher Platz.
        <br>
        ** Schnitt aller Wertungsrunden, ohne Testrunde. Eine fehlende Runde zählt als 0. Darunter steht der Schnitt.
      </p>
    </section>

    <section class="scor-results__block">
      <h2 class="scor-results__title">Spiel</h2>
      <div class="scor-board glass-card liquid-surface-inner">
        <table>
          <thead>
            <tr>
              <th scope="col">Team</th>
              <th v-for="round in results.rounds" :key="round.round" scope="col">{{ round.label }}</th>
              <th scope="col">Schnitt</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="team in byRobot" :key="team.id">
              <th scope="row">
                <span class="scor-team__name">{{ team.name }}</span>
                <span class="scor-team__number">{{ team.number }}</span>
              </th>
              <td v-for="round in results.rounds" :key="round.round">
                <template v-if="roundCell(team, round.round)">
                  <span class="scor-points">{{ roundCell(team, round.round).points }}</span>
                  <span v-if="!roundCell(team, round.round).confirmed" class="scor-points__state">begonnen</span>
                </template>
                <span v-else class="scor-points__state">–</span>
              </td>
              <td>
                <span class="scor-points scor-points--best">{{ formatPoints(team.robot) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="scor-results__note">
        Gespeicherte Bögen zählen mit. „begonnen“ heißt: Schiri und Team haben noch nicht beide bestätigt.
        Die Testrunde steht hier nicht.
      </p>
    </section>

    <section class="scor-results__block">
      <h2 class="scor-results__title">Auszeichnungen</h2>
      <p class="scor-flow">
        <span :class="{'is-now': !championTeam}">1 Champion</span>
        <span :class="{'is-now': championTeam}">2 Pokale</span>
        <span :class="{'is-now': championTeam}">3 Nominierungen</span>
        <span :class="{'is-now': championTeam}">4 Spiel und Coaching</span>
      </p>
      <p class="scor-results__note">
        <template v-if="!championTeam">Zuerst den Champion. Alles andere wartet.</template>
        <template v-else>Der Champion ist für Projekt, Technisches Design und Grundwerte gesperrt. Jeder weitere Pokal dort sperrt das Team ebenfalls. Spiel und Coaching bleiben frei, höchstens drei Pokale insgesamt.</template>
      </p>
      <div class="scor-board glass-card liquid-surface-inner">
        <table class="scor-awards">
          <thead>
            <tr>
              <th scope="col">Team</th>
              <th scope="col" :class="{'is-now': !championTeam}">Champion</th>
              <th v-for="column in judgedTrophies" :key="column.id" scope="col" :class="{'is-wait': !championTeam}">{{ column.label }}</th>
              <th v-for="column in nominationColumns" :key="`n-${column.id}`" scope="col" :class="{'is-wait': !championTeam}">Nom. {{ column.label }}</th>
              <th scope="col" :class="{'is-wait': !championTeam}">Spiel</th>
              <th scope="col" :class="{'is-wait': !championTeam}">Coaching</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="team in awardTeams" :key="team.id">
              <th scope="row">
                <span class="scor-team__name">{{ team.name }}</span>
                <span class="scor-team__number">Platz {{ team.champion_rank }} · {{ team.number }}</span>
              </th>
              <td :class="cellClass(trophyState(team, 'champion'))">
                <button type="button" class="scor-pick" :disabled="pickDisabled(trophyState(team, 'champion'))" @click="pickTrophy(team, 'champion')">
                  {{ pickLabel(trophyState(team, 'champion'), 'Champion') }}
                </button>
              </td>
              <td v-for="column in judgedTrophies" :key="column.id" :class="cellClass(trophyState(team, column.id))">
                <button type="button" class="scor-pick" :disabled="pickDisabled(trophyState(team, column.id))" @click="pickTrophy(team, column.id)">
                  {{ pickLabel(trophyState(team, column.id), 'Pokal') }}
                </button>
              </td>
              <td v-for="column in nominationColumns" :key="`n-${column.id}`" :class="cellClass(nominationState(team, column.id))">
                <button type="button" class="scor-pick" :disabled="pickDisabled(nominationState(team, column.id))" @click="pickNomination(team, column.id)">
                  {{ pickLabel(nominationState(team, column.id), 'Nom.') }}
                </button>
              </td>
              <td :class="cellClass(trophyState(team, 'robot'))">
                <button type="button" class="scor-pick" :disabled="pickDisabled(trophyState(team, 'robot'))" @click="pickTrophy(team, 'robot')">
                  {{ pickLabel(trophyState(team, 'robot'), 'Spiel') }}
                </button>
              </td>
              <td :class="cellClass(coachingState(team))">
                <button type="button" class="scor-pick" :disabled="pickDisabled(coachingState(team))" @click="pickCoaching(team)">
                  {{ pickLabel(coachingState(team), 'Coaching') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="awardError" class="text-lg text-red-700">{{ awardError }}</p>
      <button v-if="results.can_edit_awards" type="button" class="glass-btn-accent" :disabled="awardBusy" @click="saveAwards">Auszeichnungen speichern</button>
      <div v-if="results.lifecycle" class="flex flex-wrap gap-3">
        <button v-if="results.can_edit_awards && !results.lifecycle.closed" type="button" class="glass-btn-secondary" @click="closeEvent">Auswertung schließen</button>
        <button v-if="results.can_edit_awards && results.lifecycle.closed && !results.lifecycle.released" type="button" class="glass-btn-secondary" @click="reopenEvent">Wieder öffnen</button>
        <button v-if="results.flow_admin && results.lifecycle.closed && !results.lifecycle.released" type="button" class="glass-btn-accent" @click="releaseEvent">Veröffentlichen</button>
        <button v-if="results.can_edit_awards && results.lifecycle.released" type="button" class="glass-btn-secondary" @click="sendMail">Jury-PDF an Coaches</button>
      </div>
      <p v-if="mailNotice" class="scor-results__note">{{ mailNotice }}</p>
    </section>
  </div>
</template>

<style scoped>
.scor-results {
  display: flex;
  flex-direction: column;
  gap: 1.75rem;
}

.scor-results__title {
  margin: 0 0 0.65rem;
  font-size: 1.15rem;
  font-weight: 800;
}

.scor-board {
  overflow-x: auto;
}

.scor-board table {
  width: 100%;
  border-collapse: collapse;
  min-width: 40rem;
}

.scor-board th,
.scor-board td {
  padding: 0.75rem 0.85rem;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  vertical-align: top;
}

.scor-board thead th {
  font-size: 1rem;
  font-weight: 700;
}

.scor-board tbody th {
  position: sticky;
  left: 0;
  background: var(--color-bg);
  min-width: 9rem;
}

.scor-sort {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  font-weight: 700;
  text-align: left;
}

.scor-sort.is-active .bi {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.scor-team__name,
.scor-team__number {
  display: block;
}

.scor-team__name {
  font-size: 1.05rem;
  font-weight: 800;
}

.scor-team__number {
  color: var(--color-text-muted);
  font-size: 1rem;
}

.scor-place,
.scor-points {
  display: block;
  font-size: 1.35rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  line-height: 1.1;
}

.scor-place__points,
.scor-points__state {
  display: block;
  margin-top: 0.15rem;
  color: var(--color-text-muted);
  font-size: 1rem;
}

.scor-points--best {
  color: inherit;
}

.scor-results__note {
  margin: 0.65rem 0 0;
  color: var(--color-text-muted);
  font-size: 1rem;
  line-height: 1.45;
}

.scor-flow {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0 0 0.35rem;
}

.scor-flow span {
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  background: var(--color-bg);
  color: var(--color-text-muted);
  font-size: 0.95rem;
  font-weight: 700;
}

.scor-flow span.is-now {
  background: #146c36;
  color: #fff;
}

.scor-awards th.is-now {
  color: #146c36;
}

.scor-awards th.is-wait,
.scor-awards td.is-wait {
  opacity: 0.38;
}

.scor-awards td.is-on {
  background: color-mix(in srgb, #146c36 14%, transparent);
}

.scor-pick {
  min-width: 5.5rem;
  min-height: 2.6rem;
  padding: 0.35rem 0.6rem;
  border: 2px solid var(--color-border);
  border-radius: 0.7rem;
  background: #fff;
  font-size: 0.95rem;
  font-weight: 800;
}

.scor-awards td.is-on .scor-pick {
  border-color: #146c36;
  background: #146c36;
  color: #fff;
}

.scor-pick:disabled {
  cursor: default;
}
</style>
