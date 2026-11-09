<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, ref, watch} from 'vue'
import axios from 'axios'

const props = defineProps<{results: any}>()
const emit = defineEmits<{saved: [results: any]}>()

const awardError = ref('')
const mailNotice = ref('')
const awardBusy = ref(false)
const publishOpen = ref(false)
const trophies = ref<{team: number, category: string}[]>([])
const nominations = ref<{team: number, category: string}[]>([])
const coaching = ref<number | ''>('')

const awardColumns = [
  {id: 'champion', label: 'Champion', nominate: false, trophy: true},
  {id: 'research', label: 'Projekt', nominate: true, trophy: true},
  {id: 'robotdesign', label: 'Technisches Design', nominate: true, trophy: true},
  {id: 'core_values', label: 'Grundwerte', nominate: true, trophy: true},
  {id: 'coaching', label: 'Coaching', nominate: false, trophy: true},
  {id: 'robot', label: 'Spiel', nominate: false, trophy: false},
]
const judgedIds = ['champion', 'research', 'robotdesign', 'core_values']
const presentRoot = ref<HTMLElement | null>(null)
const presenting = ref(false)
const phase = ref<'nominations' | 'champion' | 'awards'>('nominations')
let phaseChosen = false
let hydratingAwards = false
let awardSaveTimer = 0

const phases = [
  {id: 'nominations' as const, label: 'Nominierungen'},
  {id: 'champion' as const, label: 'Champion'},
  {id: 'awards' as const, label: 'Auszeichnungen'},
]

const awardTeams = computed(() => {
  return [...(props.results?.teams || [])].sort((a, b) => {
    if (a.champion_rank !== b.champion_rank) return a.champion_rank - b.champion_rank
    return Number(a.number ?? 0) - Number(b.number ?? 0)
  })
})

const nominationSlots = computed(() => Math.min(4, awardTeams.value.length))

const nominationsReady = computed(() => {
  if (nominationSlots.value < 1) return false
  return awardColumns.filter((column) => column.nominate).every((column) => nominationCount(column.id) >= nominationSlots.value)
})

function nominationCount(category: string) {
  return nominations.value.filter((row) => row.category === category).length
}

function heldTrophy(teamId: number) {
  return trophies.value.find((row) => row.team === teamId && judgedIds.includes(row.category))
}

function teamById(id: number) {
  return awardTeams.value.find((team) => team.id === id)
}

function hasTrophy(teamId: number, category: string) {
  if (category === 'coaching') return coaching.value === teamId
  return marked(trophies.value, teamId, category)
}

function canNominate(category: string, teamId: number) {
  if (props.results?.lifecycle?.closed) return false
  if (phase.value !== 'nominations') return false
  if (!props.results?.can_edit_awards || presenting.value) return false
  if (marked(nominations.value, teamId, category)) return true
  return nominationCount(category) < nominationSlots.value
}

function trophyCount(teamId: number) {
  const cups = trophies.value.filter((row) => row.team === teamId).length
  return cups + (coaching.value === teamId ? 1 : 0)
}

function nominationTotal(teamId: number) {
  return awardColumns.filter((column) => column.nominate && marked(nominations.value, teamId, column.id)).length
}

function anyNomination(teamId: number) {
  return nominationTotal(teamId) > 0
}

const awardsComplete = computed(() => {
  if (!nominationsReady.value || !championTeam.value || !coaching.value) return false
  return ['research', 'robotdesign', 'core_values'].every((id) => trophies.value.some((row) => row.category === id))
})

function canAward(team: any, category: string) {
  if (props.results?.lifecycle?.closed) return false
  if (!props.results?.can_edit_awards || presenting.value || !nominationsReady.value) return false
  if (category === 'robot') return false
  if (category === 'champion') return phase.value === 'champion' && (hasTrophy(team.id, category) || anyNomination(team.id))
  if (phase.value !== 'awards') return false
  const on = hasTrophy(team.id, category)
  if (!on && trophyCount(team.id) >= 3) return false
  if (category === 'coaching') return on || anyNomination(team.id)
  if (!on && !marked(nominations.value, team.id, category)) return false
  if (!on && championTeam.value === team.id) return false
  const held = heldTrophy(team.id)
  return !held || held.category === category
}

const phaseRank = {nominations: 0, champion: 1, awards: 2} as const

function rewindTo(next: 'nominations' | 'champion' | 'awards') {
  if (phaseRank[next] >= phaseRank[phase.value]) return
  trophies.value = []
  coaching.value = ''
}

function openPhase(next: 'nominations' | 'champion' | 'awards') {
  if (!phaseReachable(next) || next === phase.value) return
  rewindTo(next)
  phase.value = next
  phaseChosen = true
}

function phaseReachable(next: 'nominations' | 'champion' | 'awards') {
  if (next === 'nominations') return true
  if (next === 'champion') return nominationsReady.value
  return Boolean(championTeam.value)
}

function toggleNomination(teamId: number, category: string) {
  if (!canNominate(category, teamId)) return
  toggleMark(nominations.value, teamId, category)
  const championId = Number(championTeam.value || 0)
  if (championId && !anyNomination(championId)) championTeam.value = ''
  trophies.value = trophies.value.filter((row) => {
    if (row.category === 'champion') return anyNomination(row.team)
    if (row.category === 'research' || row.category === 'robotdesign' || row.category === 'core_values') {
      return marked(nominations.value, row.team, row.category)
    }
    return true
  })
  if (coaching.value && !anyNomination(Number(coaching.value))) coaching.value = ''
}

function toggleAward(team: any, category: string) {
  if (!canAward(team, category) && !hasTrophy(team.id, category)) return
  if (!props.results?.can_edit_awards || presenting.value || !nominationsReady.value) return
  const on = hasTrophy(team.id, category)
  if (category === 'champion') {
    const select = !hasTrophy(team.id, 'champion')
    championTeam.value = select ? team.id : ''
    if (select) {
      phase.value = 'awards'
      phaseChosen = true
    }
    return
  }
  if (category === 'coaching') {
    coaching.value = on ? '' : team.id
    return
  }
  trophies.value = trophies.value.filter((row) => row.category !== category)
  if (!on) trophies.value.push({team: team.id, category})
}

function awardHolder(category: string) {
  if (category === 'coaching') return coaching.value ? teamById(Number(coaching.value)) : null
  const row = trophies.value.find((entry) => entry.category === category)
  return row ? teamById(row.team) : null
}

function holderLine(category: string) {
  if (category === 'robot') {
    const leaders = awardTeams.value.filter((team) => team.robot_rank === 1)
    return leaders.length ? leaders.map((team) => `${team.number} ${team.name}`).join(', ') : '—'
  }
  const team = awardHolder(category)
  return team ? `${team.number} ${team.name}` : '—'
}

function nomineeTeams(category: string) {
  return nominations.value
      .filter((row) => row.category === category)
      .map((row) => teamById(row.team))
      .filter((team) => !!team)
}

async function startPresent() {
  presenting.value = true
  await nextTick()
  const node = presentRoot.value
  if (!node || document.fullscreenElement) return
  try {
    await node.requestFullscreen()
  } catch {
    /* overlay still covers the page */
  }
}

function stopPresent() {
  presenting.value = false
  if (document.fullscreenElement) {
    void document.exitFullscreen().catch(() => {})
  }
}

function onPresentKey(event: KeyboardEvent) {
  if (event.key === 'Escape') stopPresent()
}

function onFullscreenChange() {
  if (!document.fullscreenElement) presenting.value = false
}

watch(presenting, (on) => {
  if (on) {
    document.addEventListener('fullscreenchange', onFullscreenChange)
    document.addEventListener('keydown', onPresentKey)
  } else {
    document.removeEventListener('fullscreenchange', onFullscreenChange)
    document.removeEventListener('keydown', onPresentKey)
  }
})

watch(() => props.results, (results) => {
  hydratingAwards = true
  trophies.value = [...(results?.awards?.trophies || [])].filter((row) => row.category !== 'robot')
  nominations.value = [...(results?.awards?.nominations || [])]
  coaching.value = results?.awards?.coaching || ''
  awardError.value = ''
  if (!phaseChosen) {
    const hasChampion = trophies.value.some((row) => row.category === 'champion')
    phase.value = hasChampion ? 'awards' : (nominationsReady.value ? 'champion' : 'nominations')
    phaseChosen = true
  }
  hydratingAwards = false
}, {immediate: true})

watch([trophies, nominations, coaching], () => {
  if (hydratingAwards || !props.results?.can_edit_awards || props.results?.lifecycle?.closed) return
  window.clearTimeout(awardSaveTimer)
  awardSaveTimer = window.setTimeout(() => {
    void saveAwards()
  }, 700)
}, {deep: true})

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

async function confirmPublish() {
  publishOpen.value = false
  await releaseEvent()
}

async function releaseEvent() {
  const {data} = await axios.post(`/scor/events/${props.results.event.id}/release`)
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
  if (!props.results?.can_edit_awards || props.results?.lifecycle?.closed) return
  awardError.value = ''
  awardBusy.value = true
  try {
    const {data} = await axios.put(`/scor/events/${props.results.event.id}/awards`, {
      trophies: [
        ...trophies.value.filter((row) => row.category !== 'robot'),
        ...spielTrophy(),
      ],
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

const sortKey = ref('research_rank')
const awardSortKey = ref('noms')
const awardSortDir = ref<'asc' | 'desc'>('desc')
const sortDir = ref<'asc' | 'desc'>('asc')
const board = ref<'spiel' | 'jury' | 'awards'>('spiel')
const switchEl = ref<HTMLElement | null>(null)
const lens = ref({on: false, x: 0, y: 0, width: 0, height: 0, swoosh: false})
let lensPlaced = false
let lensResize: ResizeObserver | null = null
let lensSwooshTimer = 0

function placeLens() {
  const nav = switchEl.value
  if (!nav) return
  const active = nav.querySelector('.scor-switch__btn.is-on')
  if (!(active instanceof HTMLElement)) {
    lensPlaced = false
    lens.value = {...lens.value, on: false, swoosh: false}
    return
  }
  const navBox = nav.getBoundingClientRect()
  const box = active.getBoundingClientRect()
  const x = box.left - navBox.left
  const y = box.top - navBox.top
  const moved = lensPlaced && Math.abs(x - lens.value.x) > 1
  if (lensPlaced && !moved && Math.abs(box.width - lens.value.width) < 1 && Math.abs(box.height - lens.value.height) < 1) return
  lens.value = {on: true, x, y, width: box.width, height: box.height, swoosh: moved}
  lensPlaced = true
  if (!moved) return
  window.clearTimeout(lensSwooshTimer)
  lensSwooshTimer = window.setTimeout(() => {
    lens.value = {...lens.value, swoosh: false}
  }, 280)
}

watch(switchEl, (el) => {
  lensResize?.disconnect()
  lensResize = null
  lensPlaced = false
  if (!el) return
  nextTick(() => {
    placeLens()
    if (typeof ResizeObserver !== 'undefined') {
      lensResize = new ResizeObserver(() => placeLens())
      lensResize.observe(el)
    }
  })
})

watch(board, () => nextTick(() => placeLens()))

onBeforeUnmount(() => {
  lensResize?.disconnect()
  window.clearTimeout(lensSwooshTimer)
  window.clearTimeout(awardSaveTimer)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  document.removeEventListener('keydown', onPresentKey)
  if (document.fullscreenElement) void document.exitFullscreen().catch(() => {})
})

const juryColumns = [
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

function spielTrophy() {
  const leaders = (props.results?.teams || []).filter((team: any) => team.robot_rank === 1)
  if (leaders.length !== 1) return []
  return [{team: leaders[0].id, category: 'robot'}]
}

function awardSortValue(team: any, key: string) {
  if (key === 'number') return Number(team.number ?? 0)
  if (key === 'noms') return nominationTotal(team.id)
  if (key === 'robot') return Number(team.robot_rank ?? 999)
  if (key === 'champion') return hasTrophy(team.id, 'champion') ? 2 : nominationTotal(team.id)
  if (key === 'coaching') return hasTrophy(team.id, 'coaching') ? 1 : 0
  const nominated = marked(nominations.value, team.id, key) ? 1 : 0
  return nominated + (hasTrophy(team.id, key) ? 1 : 0)
}

const sortedAwardTeams = computed(() => {
  const teams = [...awardTeams.value]
  if (phase.value === 'nominations') {
    teams.sort((a, b) => Number(a.number ?? 0) - Number(b.number ?? 0))
    return teams
  }
  const key = awardSortKey.value
  const dir = awardSortDir.value === 'asc' ? 1 : -1
  teams.sort((a, b) => {
    const left = awardSortValue(a, key)
    const right = awardSortValue(b, key)
    if (left !== right) return (left - right) * dir
    return Number(a.number ?? 0) - Number(b.number ?? 0)
  })
  return teams
})

function toggleAwardSort(key: string) {
  if (phase.value === 'nominations') return
  if (awardSortKey.value === key) {
    awardSortDir.value = awardSortDir.value === 'asc' ? 'desc' : 'asc'
    return
  }
  awardSortKey.value = key
  awardSortDir.value = key === 'number' || key === 'robot' ? 'asc' : 'desc'
}

function awardSortLabel(key: string) {
  if (awardSortKey.value !== key) return 'sortieren'
  return awardSortDir.value === 'asc' ? 'aufsteigend sortiert' : 'absteigend sortiert'
}

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

watch(championTeam, (id) => {
  if (id && phase.value === 'champion') {
    phase.value = 'awards'
    phaseChosen = true
  }
})

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
    <div ref="switchEl" class="scor-switch" role="tablist" aria-label="Tabelle">
      <span
          v-show="lens.on"
          class="scor-switch__lens"
          :class="{'is-swoosh': lens.swoosh}"
          :style="{
            width: `${lens.width}px`,
            height: `${lens.height}px`,
            transform: `translate(${lens.x}px, ${lens.y}px)`,
          }"
          aria-hidden="true"
      >
        <span class="scor-switch__lens-glass"/>
      </span>
      <button type="button" class="scor-switch__btn" role="tab" :class="{'is-on': board === 'spiel'}" :aria-selected="board === 'spiel'" @click="board = 'spiel'">Spiel</button>
      <button type="button" class="scor-switch__btn" role="tab" :class="{'is-on': board === 'jury'}" :aria-selected="board === 'jury'" @click="board = 'jury'">Jury-Bewertungen</button>
      <button type="button" class="scor-switch__btn" role="tab" :class="{'is-on': board === 'awards'}" :aria-selected="board === 'awards'" @click="board = 'awards'">Auszeichnungen</button>
    </div>

    <section v-if="board === 'spiel'" class="scor-results__block">
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
        Die Testrunde steht hier nicht. Der Schnitt lässt eine fehlende Runde als 0 zählen.
      </p>
    </section>

    <section v-else-if="board === 'jury'" class="scor-results__block">
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
              <th v-for="column in juryColumns" :key="column.key" scope="col">
                <button type="button" class="scor-sort" :class="{ 'is-active': sortKey === column.key }" :aria-label="`${column.label}, ${sortLabel(column.key)}`" @click="toggleSort(column.key)">
                  {{ column.label }}
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
              <td v-for="column in juryColumns" :key="column.key">
                <span class="scor-place">{{ team[column.key] }}</span>
                <span class="scor-place__points">{{ formatPoints(team[column.points]) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="scor-results__note">
        Platz und Punkte aus dem Jury-Bogen. Gleiche Punktzahl, gleicher Platz.
      </p>
    </section>

    <section v-else class="scor-results__block">
      <div class="scor-award-head">
        <ol class="scor-phases">
          <li
              v-for="(step, index) in phases"
              :key="step.id"
              class="scor-phases__item"
              :class="{'is-on': phase === step.id, 'is-done': phase !== step.id && phaseReachable(step.id)}"
          >
            <button
                type="button"
                class="scor-phases__step"
                :disabled="!phaseReachable(step.id)"
                :aria-current="phase === step.id ? 'step' : undefined"
                @click="openPhase(step.id)"
            >
              <span class="scor-phases__mark">{{ index + 1 }}</span>
              <span class="scor-phases__label">{{ step.label }}</span>
            </button>
          </li>
        </ol>
        <div class="scor-award-tools">
          <p class="scor-results__note">
            <template v-if="phase === 'nominations'">Vier Sterne für Projekt, Technisches Design und Grundwerte.</template>
            <template v-else-if="phase === 'champion'">Ein Team, das schon einen Stern hat.</template>
            <template v-else>Die Pokale gehen an nominierte Teams.</template>
          </p>
          <button type="button" class="scor-present" :aria-label="presenting ? 'Vollbild beenden' : 'Vollbild'" @click="presenting ? stopPresent() : startPresent()">
            <i class="bi" :class="presenting ? 'bi-fullscreen-exit' : 'bi-fullscreen'" aria-hidden="true"/>
          </button>
        </div>
      </div>
      <div ref="presentRoot" class="scor-stage" :class="{'is-present': presenting}">
        <div class="scor-board glass-card liquid-surface-inner">
          <table class="scor-awards">
            <thead>
              <tr>
                <th scope="col">
                  <button type="button" class="scor-sort" :disabled="phase === 'nominations'" :class="{ 'is-active': phase !== 'nominations' && awardSortKey === 'number' }" :aria-label="`Team, ${awardSortLabel('number')}`" @click="toggleAwardSort('number')">
                    Team
                    <i v-if="phase !== 'nominations' && awardSortKey === 'number'" class="bi" :class="awardSortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill'" aria-hidden="true"/>
                  </button>
                </th>
                <th scope="col">
                  <button type="button" class="scor-sort" :disabled="phase === 'nominations'" :class="{ 'is-active': phase !== 'nominations' && awardSortKey === 'noms' }" :aria-label="`Anzahl der Nominierungen, ${awardSortLabel('noms')}`" @click="toggleAwardSort('noms')">
                    Anzahl
                    <i v-if="phase !== 'nominations' && awardSortKey === 'noms'" class="bi" :class="awardSortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill'" aria-hidden="true"/>
                  </button>
                </th>
                <th v-for="column in awardColumns" :key="column.id" scope="col">
                  <button type="button" class="scor-sort" :disabled="phase === 'nominations'" :class="{ 'is-active': phase !== 'nominations' && awardSortKey === column.id }" :aria-label="`${column.label}, ${awardSortLabel(column.id)}`" @click="toggleAwardSort(column.id)">
                    {{ column.label }}
                    <span v-if="column.nominate" class="scor-award-count">{{ nominationCount(column.id) }}/{{ nominationSlots }}</span>
                    <i v-if="phase !== 'nominations' && awardSortKey === column.id" class="bi" :class="awardSortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill'" aria-hidden="true"/>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="team in sortedAwardTeams" :key="team.id">
                <th scope="row">
                  <span class="scor-team__name">{{ team.name }}</span>
                  <span class="scor-team__number">{{ team.number }}</span>
                </th>
                <td>
                  <span class="scor-place">{{ nominationTotal(team.id) }}</span>
                </td>
                <td v-for="column in awardColumns" :key="column.id">
                  <div v-if="column.id === 'robot'" class="scor-award-cell scor-award-cell--result">
                    <span class="scor-place">{{ team.robot_rank }}</span>
                    <span class="scor-place__points">{{ formatPoints(team.robot) }}</span>
                  </div>
                  <div v-else class="scor-award-cell">
                    <button
                        v-if="column.nominate"
                        type="button"
                        class="scor-mark"
                        :class="{'is-on': marked(nominations, team.id, column.id), 'is-locked': phase !== 'nominations'}"
                        :disabled="phase !== 'nominations' || presenting || (!canNominate(column.id, team.id) && !marked(nominations, team.id, column.id))"
                        :aria-pressed="marked(nominations, team.id, column.id)"
                        :aria-label="`${column.label} nominieren`"
                        @click="toggleNomination(team.id, column.id)"
                    >
                      <i class="bi" :class="marked(nominations, team.id, column.id) ? 'bi-star-fill' : 'bi-star'" aria-hidden="true"/>
                    </button>
                    <button
                        v-if="column.trophy"
                        type="button"
                        class="scor-mark scor-mark--cup"
                        :class="{'is-on': hasTrophy(team.id, column.id), 'is-locked': column.id === 'champion' ? phase !== 'champion' : phase !== 'awards'}"
                        :disabled="!canAward(team, column.id)"
                        :aria-pressed="hasTrophy(team.id, column.id)"
                        :aria-label="`${column.label} Pokal`"
                        @click="toggleAward(team, column.id)"
                    >
                      <i class="bi" :class="hasTrophy(team.id, column.id) ? 'bi-trophy-fill' : 'bi-trophy'" aria-hidden="true"/>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <ul class="scor-recap">
          <li v-for="column in awardColumns" :key="column.id">
            <span class="scor-recap__label">{{ column.label }}</span>
            <strong>{{ holderLine(column.id) }}</strong>
            <span v-if="column.nominate" class="scor-recap__noms">
              {{ nomineeTeams(column.id).map((team) => `${team.number} ${team.name}`).join(', ') || 'keine Nominierung' }}
            </span>
          </li>
        </ul>
      </div>
      <p v-if="awardError" class="text-lg text-red-700">{{ awardError }}</p>
      <template v-if="results.can_edit_awards && results.lifecycle">
        <button
            v-if="!results.lifecycle.closed"
            type="button"
            class="scor-finalize"
            :disabled="!awardsComplete || awardBusy"
            @click="closeEvent"
        >
          Auswertung festschreiben
        </button>
        <p v-if="results.lifecycle.closed && !results.lifecycle.released" class="scor-results__note">
          Die Auswertung ist festgeschrieben. Änderungen sind nicht mehr möglich. Öffentlich sichtbar ist sie noch nicht.
        </p>
        <button
            v-if="results.lifecycle.closed && !results.lifecycle.released"
            type="button"
            class="scor-finalize"
            @click="publishOpen = true"
        >
          Veröffentlichen
        </button>
        <p v-if="results.lifecycle.released" class="scor-results__note">
          Die Ergebnisse stehen auf der Standortseite.
        </p>
        <button v-if="results.lifecycle.released" type="button" class="glass-btn-secondary" @click="sendMail">Jury-PDF an Coaches</button>
      </template>
      <p v-if="mailNotice" class="scor-results__note">{{ mailNotice }}</p>
      <div v-if="publishOpen" class="scor-publish" role="dialog" aria-modal="true" aria-labelledby="scor-publish-title">
        <div class="scor-publish__card">
          <h2 id="scor-publish-title">Ergebnisse veröffentlichen</h2>
          <p>Danach sind die Ergebnisse auf der Standortseite zu sehen. Ein Wiedereröffnen ist nicht mehr möglich.</p>
          <div class="scor-publish__actions">
            <button type="button" class="glass-btn-secondary" @click="publishOpen = false">Abbrechen</button>
            <button type="button" class="glass-btn-accent" @click="confirmPublish">Veröffentlichen</button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.scor-results {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.scor-switch {
  position: relative;
  display: flex;
  align-items: stretch;
  gap: 0.2rem;
  min-height: 3.65rem;
  padding: 0.28rem;
  box-sizing: border-box;
  overflow: hidden;
  border: 1px solid var(--liquid-border);
  border-radius: 1.85rem;
  background: var(--liquid-tile-bg);
  backdrop-filter: blur(var(--liquid-blur)) saturate(var(--liquid-saturate));
  -webkit-backdrop-filter: blur(var(--liquid-blur)) saturate(var(--liquid-saturate));
  box-shadow: var(--liquid-shadow);
}

.scor-switch::before {
  content: "";
  position: absolute;
  z-index: 0;
  inset: 0;
  border-radius: inherit;
  pointer-events: none;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.55) 0%, rgba(255, 255, 255, 0.12) 34%, transparent 58%);
}

html[data-theme='dark'] .scor-switch::before {
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.18) 0%, transparent 48%);
}

.scor-switch__lens {
  position: absolute;
  z-index: 1;
  top: 0;
  left: 0;
  border-radius: 1.5rem;
  pointer-events: none;
  transition: transform 0.62s cubic-bezier(0.22, 1.45, 0.36, 1);
}

.scor-switch__lens-glass {
  display: block;
  width: 100%;
  height: 100%;
  border-radius: inherit;
  background: color-mix(in srgb, var(--color-bg, #fff) 88%, transparent);
  box-shadow:
    inset 0 1.5px 0 rgba(255, 255, 255, 1),
    inset 0 -1px 0 rgba(15, 23, 42, 0.08),
    0 6px 16px rgba(15, 23, 42, 0.12);
  transform: scaleX(1) scaleY(1);
  transition: transform 0.5s cubic-bezier(0.22, 1.35, 0.36, 1);
}

.scor-switch__lens.is-swoosh .scor-switch__lens-glass {
  transform: scaleX(1.22) scaleY(0.9);
}

html[data-theme='dark'] .scor-switch__lens-glass {
  background: color-mix(in srgb, #fff 22%, transparent);
  box-shadow:
    inset 0 1px 0 rgba(255, 255, 255, 0.28),
    0 6px 16px rgba(0, 0, 0, 0.28);
}

.scor-switch__btn {
  position: relative;
  z-index: 2;
  flex: 1 1 0;
  min-width: 0;
  min-height: 3rem;
  margin: 0;
  padding: 0.45rem 0.55rem;
  border: 0;
  border-radius: 1.5rem;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.95rem;
  font-weight: 650;
  line-height: 1.15;
  text-align: center;
  cursor: pointer;
  touch-action: manipulation;
  -webkit-tap-highlight-color: transparent;
}

.scor-switch__btn:hover,
.scor-switch__btn:focus-visible {
  color: var(--color-text);
  outline: none;
}

.scor-switch__btn.is-on,
.scor-switch__btn.is-on:hover,
.scor-switch__btn.is-on:focus-visible {
  color: var(--color-accent, #e85d04);
  font-weight: 800;
}

@media (prefers-reduced-motion: reduce) {
  .scor-switch__lens,
  .scor-switch__lens-glass {
    transition: none;
  }
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

.scor-results__block {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.scor-results__block > .scor-results__note,
.scor-award-tools .scor-results__note {
  margin: 0;
}

.scor-award-head {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.scor-phases {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0;
  margin: 0;
  padding: 0.15rem 0 0;
  list-style: none;
}

.scor-phases__item {
  position: relative;
  min-width: 0;
}

.scor-phases__item:not(:last-child)::after {
  content: "";
  position: absolute;
  top: 0.85rem;
  left: calc(50% + 1.15rem);
  right: calc(-50% + 1.15rem);
  height: 2px;
  background: var(--color-border);
}

.scor-phases__item.is-done:not(:last-child)::after,
.scor-phases__item.is-on:not(:last-child)::after {
  background: color-mix(in srgb, var(--color-accent) 55%, var(--color-border));
}

.scor-phases__step {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.35rem;
  width: 100%;
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.8rem;
  font-weight: 700;
  line-height: 1.2;
  text-align: center;
  cursor: pointer;
}

.scor-phases__mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.7rem;
  height: 1.7rem;
  border: 2px solid var(--color-border);
  border-radius: 999px;
  background: var(--orbit-base, #e8ebf0);
  font-size: 0.8rem;
  font-weight: 800;
}

.scor-phases__step.is-on,
.scor-phases__item.is-on .scor-phases__step {
  color: var(--color-text);
}

.scor-phases__item.is-on .scor-phases__mark {
  border-color: var(--color-accent);
  background: var(--color-accent);
  color: var(--color-on-accent, #fff);
}

.scor-phases__item.is-done .scor-phases__mark {
  border-color: var(--color-accent);
  color: var(--color-accent);
}

.scor-phases__step:disabled {
  cursor: default;
  opacity: 0.45;
}

.scor-award-tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.scor-sort:disabled {
  cursor: default;
}

.scor-stage.is-present,
.scor-stage:fullscreen {
  position: fixed;
  inset: 0;
  z-index: 12000;
  box-sizing: border-box;
  overflow: auto;
  padding: 2rem;
  background: var(--orbit-base, #e8ebf0);
}

.scor-stage:fullscreen {
  position: static;
  padding: 2.5rem 3rem;
}

.scor-stage:fullscreen .scor-team__name {
  font-size: 1.65rem;
}

.scor-stage:fullscreen .scor-team__number,
.scor-stage:fullscreen .scor-award-count,
.scor-stage:fullscreen .scor-place__points {
  font-size: 1.2rem;
}

.scor-stage:fullscreen .scor-awards thead th,
.scor-stage:fullscreen .scor-awards .scor-sort {
  font-size: 1.2rem;
}

.scor-stage:fullscreen .scor-place {
  font-size: 1.8rem;
}

.scor-stage:fullscreen .scor-recap__label {
  font-size: 1.05rem;
}

.scor-stage:fullscreen .scor-recap strong {
  font-size: 1.45rem;
}

.scor-stage:fullscreen .scor-mark {
  width: 3.4rem;
  height: 3.4rem;
  font-size: 1.85rem;
}

.scor-present {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 2.75rem;
  height: 2.75rem;
  border: 1px solid var(--liquid-border);
  border-radius: 0.8rem;
  background: color-mix(in srgb, #fff 80%, transparent);
  color: var(--color-text);
  cursor: pointer;
}

.scor-present .bi {
  font-size: 1.2rem;
}

.scor-finalize {
  width: 100%;
  min-height: 3.25rem;
  border: 0;
  border-radius: var(--radius-lg);
  background: var(--color-accent);
  color: var(--color-on-accent, #fff);
  font: inherit;
  font-size: 1.1rem;
  font-weight: 800;
  cursor: pointer;
}

.scor-finalize:disabled {
  opacity: 0.45;
  cursor: default;
}

.scor-publish {
  position: fixed;
  inset: 0;
  z-index: 13000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.46);
}

.scor-publish__card {
  width: min(100%, 28rem);
  padding: 1.25rem 1.35rem;
  border-radius: var(--radius-lg);
  background: var(--orbit-base, #f4f6f9);
  color: var(--color-text);
}

.scor-publish__card h2 {
  margin: 0 0 0.5rem;
  font-size: 1.25rem;
}

.scor-publish__card p {
  margin: 0;
  line-height: 1.45;
}

.scor-publish__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  margin-top: 1rem;
}

.scor-stage:fullscreen .scor-mark {
  width: 3.4rem;
  height: 3.4rem;
  font-size: 1.85rem;
}

.scor-award-count {
  display: block;
  margin-top: 0.1rem;
  color: var(--color-text-muted);
  font-size: 0.7rem;
  font-weight: 700;
}

.scor-board .scor-awards {
  min-width: 0;
  table-layout: fixed;
}

.scor-awards th,
.scor-awards td {
  padding: 0.4rem 0.25rem;
  text-align: center;
}

.scor-awards tbody th {
  min-width: 0;
  width: 16%;
  text-align: left;
}

.scor-awards thead th {
  font-size: 0.78rem;
  line-height: 1.15;
  vertical-align: bottom;
}

.scor-awards .scor-sort {
  align-items: flex-end;
  justify-content: center;
  width: 100%;
  white-space: normal;
  font-size: 0.78rem;
  line-height: 1.15;
  font-weight: 700;
  text-align: center;
}

.scor-awards tbody tr:hover td,
.scor-awards tbody tr:hover th {
  background: color-mix(in srgb, var(--color-accent) 16%, var(--color-bg));
}

.scor-award-cell--result {
  flex-direction: column;
  gap: 0;
}

.scor-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  padding: 0;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1.05rem;
  cursor: pointer;
}

.scor-mark.is-on {
  color: #b45309;
}

.scor-mark--cup.is-on {
  color: #146c36;
}

.scor-mark.is-locked,
.scor-mark:disabled {
  opacity: 0.35;
  cursor: default;
}

.scor-mark:not(:disabled):hover {
  background: color-mix(in srgb, var(--color-text) 8%, transparent);
}

.scor-recap {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.scor-recap li {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-bg-muted);
}

.scor-recap__label {
  color: var(--color-text-muted);
  font-size: 0.85rem;
  font-weight: 700;
}

.scor-recap strong {
  font-size: 1.05rem;
}

.scor-recap__noms {
  color: var(--color-text-muted);
  font-size: 0.95rem;
}
</style>
