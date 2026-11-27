<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, ref, watch} from 'vue'
import axios from 'axios'
import PageTabs from '@hands-on/glass/page-tabs'

const props = defineProps<{results: any}>()
const emit = defineEmits<{saved: [results: any]}>()

const awardError = ref('')
const mailNotice = ref('')
const awardBusy = ref(false)
const publishOpen = ref(false)
const resetOpen = ref(false)
const trophies = ref<{team: number, category: string}[]>([])
const nominations = ref<{team: number, category: string}[]>([])
const coaching = ref<number | ''>('')

const podium = [
  {id: 'champion', label: 'Champion'},
  {id: 'silver', label: 'Silber'},
  {id: 'bronze', label: 'Bronze'},
]
const podiumIds = podium.map((slot) => slot.id)
const awardColumns = [
  {id: 'place', label: 'Gesamt', nominate: false, trophy: false},
  {id: 'research', label: 'Projekt', nominate: true, trophy: true},
  {id: 'robotdesign', label: 'Technisches Design', nominate: true, trophy: true},
  {id: 'core_values', label: 'Grundwerte', nominate: true, trophy: true},
  {id: 'coaching', label: 'Coaching', nominate: false, trophy: true},
  {id: 'robot', label: 'Spiel', nominate: false, trophy: false},
]
const categoryIds = ['research', 'robotdesign', 'core_values']
const presentRoot = ref<HTMLElement | null>(null)
const gameRoot = ref<HTMLElement | null>(null)
const presentMode = ref<'awards' | 'spiel' | null>(null)
const presenting = computed(() => presentMode.value === 'awards')
const gamePresenting = computed(() => presentMode.value === 'spiel')
const REVEAL_MS = 1500
const revealed = ref(0)
let revealTimer = 0
let storedAwardsKey: string | null = null
let awardSaveTimer = 0
let awardSave: Promise<void> | null = null
let awardSaveAgain = false

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

function heldCategoryTrophy(teamId: number) {
  return trophies.value.find((row) => row.team === teamId && categoryIds.includes(row.category))
}

function hasTrophy(teamId: number, category: string) {
  if (category === 'coaching') return coaching.value === teamId
  return marked(trophies.value, teamId, category)
}

const awardsEditable = computed(() => {
  return Boolean(props.results?.can_edit_awards) && !props.results?.lifecycle?.closed && !presenting.value
})

function canNominate(category: string, teamId: number) {
  if (!awardsEditable.value) return false
  if (marked(nominations.value, teamId, category)) return true
  return nominationCount(category) < nominationSlots.value
}

function trophyCount(teamId: number) {
  const cups = trophies.value.filter((row) => row.team === teamId).length
  const spiel = spielTrophy().some((row) => row.team === teamId) ? 1 : 0
  return cups + spiel + (coaching.value === teamId ? 1 : 0)
}

const podiumSlots = computed(() => Math.min(podium.length, awardTeams.value.length))

const awardsComplete = computed(() => {
  if (!nominationsReady.value || !coaching.value) return false
  if (trophies.value.filter((row) => podiumIds.includes(row.category)).length < podiumSlots.value) return false
  return ['research', 'robotdesign', 'core_values'].every((id) => trophies.value.some((row) => row.category === id))
})

function canAward(team: any, category: string) {
  if (!awardsEditable.value || category === 'robot') return false
  const on = hasTrophy(team.id, category)
  if (!on && trophyCount(team.id) >= 3) return false
  if (category === 'coaching') return true
  if (!on && !marked(nominations.value, team.id, category)) return false
  if (placeOf(team.id)?.id === 'champion') return false
  const held = heldCategoryTrophy(team.id)
  return !held || held.category === category
}

function tiedWith(team: any) {
  return awardTeams.value.filter((other) => other.champion_rank === team.champion_rank)
}

function placeOf(teamId: number) {
  return podium.find((slot) => marked(trophies.value, teamId, slot.id)) || null
}

/** Podium positions a team's rank group spans (competition ranks: group starts at rank − 1). */
function podiumReach(team: any) {
  const first = Number(team.champion_rank) - 1
  const last = first + tiedWith(team).length - 1
  return podium.map((_, index) => index).filter((index) => index >= first && index <= last && index < podiumSlots.value)
}

function isPodiumTie(team: any) {
  return tiedWith(team).length > 1 && podiumReach(team).length > 0
}

function resolvePodium(rows: {team: number, category: string}[]) {
  const picked = new Map(rows.filter((row) => podiumIds.includes(row.category)).map((row) => [row.category, row.team]))
  const taken = new Set<number>()
  const resolved: {team: number, category: string}[] = []
  podium.slice(0, podiumSlots.value).forEach((slot, index) => {
    const open = tiedWith(awardTeams.value[index]).filter((team) => !taken.has(team.id))
    const team = open.length === 1 ? open[0] : open.find((candidate) => candidate.id === picked.get(slot.id))
    if (!team) return
    taken.add(team.id)
    resolved.push({team: team.id, category: slot.id})
  })
  const champion = resolved.find((row) => row.category === 'champion')?.team
  return [
    ...rows.filter((row) => !podiumIds.includes(row.category) && !(row.team === champion && categoryIds.includes(row.category))),
    ...resolved,
  ]
}

function podiumFilled(index: number) {
  return trophies.value.some((row) => row.category === podium[index].id)
}

/** Place shown in Gesamt: after a tie is decided, unpicked teams move behind the podium slots of their group. */
function shownPlace(team: any) {
  const slot = placeOf(team.id)
  if (slot) return podiumIds.indexOf(slot.id) + 1
  if (!isPodiumTie(team)) return Number(team.champion_rank)
  const reach = podiumReach(team)
  if (!reach.every(podiumFilled)) return Number(team.champion_rank)
  return reach[reach.length - 1] + 2
}

function canPlace(team: any) {
  if (!awardsEditable.value || !isPodiumTie(team)) return false
  if (placeOf(team.id)) return true
  if (trophyCount(team.id) >= 3) return false
  return podiumReach(team).some((index) => !podiumFilled(index))
}

function togglePlace(team: any) {
  if (!canPlace(team)) return
  const group = new Set(tiedWith(team).map((other) => other.id))
  let rows = trophies.value
  if (placeOf(team.id)) {
    rows = rows.filter((row) => !(podiumIds.includes(row.category) && group.has(row.team)))
  } else {
    const index = podiumReach(team).find((slot) => !rows.some((row) => row.category === podium[slot].id))
    if (index === undefined) return
    rows = [...rows, {team: team.id, category: podium[index].id}]
  }
  trophies.value = resolvePodium(rows)
}

function toggleNomination(teamId: number, category: string) {
  if (!canNominate(category, teamId)) return
  toggleMark(nominations.value, teamId, category)
  trophies.value = trophies.value.filter((row) => {
    if (categoryIds.includes(row.category)) return marked(nominations.value, row.team, row.category)
    return true
  })
}

function toggleAward(team: any, category: string) {
  if (!canAward(team, category)) return
  const on = hasTrophy(team.id, category)
  if (category === 'coaching') {
    coaching.value = on ? '' : team.id
    return
  }
  trophies.value = trophies.value.filter((row) => row.category !== category)
  if (!on) trophies.value.push({team: team.id, category})
}

async function startPresent(mode: 'awards' | 'spiel' = 'awards') {
  if (mode === 'spiel') resetReveal()
  presentMode.value = mode
  await nextTick()
  const node = mode === 'spiel' ? gameRoot.value : presentRoot.value
  if (!node || document.fullscreenElement) return
  try {
    await node.requestFullscreen()
  } catch {
    /* overlay still covers the page */
  }
}

function stopPresent() {
  presentMode.value = null
  stopReveal()
  if (document.fullscreenElement) {
    void document.exitFullscreen().catch(() => {})
  }
}

function onPresentKey(event: KeyboardEvent) {
  if (event.key === 'Escape') stopPresent()
  else if (gamePresenting.value && event.key.toLowerCase() === 'g' && !event.repeat) toggleReveal()
}

function onFullscreenChange() {
  if (!document.fullscreenElement) stopPresent()
}

/** Latest scoring round with at least one sheet. */
const latestRound = computed(() => {
  const played = (props.results?.rounds || [])
      .map((round: any) => Number(round.round))
      .filter((round: number) => byRobot.value.some((team) => roundCell(team, round)))
  return played.length ? Math.max(...played) : null
})

function resetReveal() {
  stopReveal()
  revealed.value = 0
}

function stopReveal() {
  window.clearInterval(revealTimer)
  revealTimer = 0
}

/** G starts the reveal from the bottom row, G again pauses or resumes it. */
function toggleReveal() {
  if (revealTimer) {
    stopReveal()
    return
  }
  if (revealed.value >= byRobot.value.length) return
  revealNext()
  if (revealed.value < byRobot.value.length) revealTimer = window.setInterval(revealNext, REVEAL_MS)
}

function revealNext() {
  revealed.value = Math.min(revealed.value + 1, byRobot.value.length)
  const row = gameRoot.value?.querySelectorAll('tbody tr')[byRobot.value.length - revealed.value]
  row?.scrollIntoView({block: 'nearest', behavior: 'smooth'})
  if (revealed.value >= byRobot.value.length) stopReveal()
}

function covered(index: number) {
  return gamePresenting.value && index < byRobot.value.length - revealed.value
}

function justRevealed(index: number) {
  return gamePresenting.value && revealed.value > 0 && index === byRobot.value.length - revealed.value
}

watch(presentMode, (on) => {
  if (on) {
    document.addEventListener('fullscreenchange', onFullscreenChange)
    document.addEventListener('keydown', onPresentKey)
  } else {
    document.removeEventListener('fullscreenchange', onFullscreenChange)
    document.removeEventListener('keydown', onPresentKey)
  }
})

function awardsKey(
    trophyRows: {team: number, category: string}[] = trophies.value,
    nominationRows: {team: number, category: string}[] = nominations.value,
    coachingTeam: number | '' = coaching.value,
) {
  const rows = (list: {team: number, category: string}[]) => list.map((row) => `${row.team}:${row.category}`).sort().join(',')
  return `${rows(trophyRows)}|${rows(nominationRows)}|${coachingTeam}`
}

watch(() => props.results, (results) => {
  const incoming = {
    trophies: [...(results?.awards?.trophies || [])].filter((row) => row.category !== 'robot'),
    nominations: [...(results?.awards?.nominations || [])],
    coaching: results?.awards?.coaching || '',
  }
  const clickedAhead = storedAwardsKey !== null && awardsKey() !== storedAwardsKey && !results?.lifecycle?.closed
  if (clickedAhead) {
    storedAwardsKey = awardsKey(incoming.trophies, incoming.nominations, incoming.coaching)
  } else {
    trophies.value = incoming.trophies
    nominations.value = incoming.nominations
    coaching.value = incoming.coaching
    storedAwardsKey = awardsKey()
  }
  awardError.value = ''
  if (!results?.lifecycle?.closed) trophies.value = resolvePodium(trophies.value)
  queueAwardSave()
}, {immediate: true})

watch([trophies, nominations, coaching], queueAwardSave, {deep: true})

function queueAwardSave() {
  window.clearTimeout(awardSaveTimer)
  if (!props.results?.can_edit_awards || props.results?.lifecycle?.closed) return
  if (awardsKey() === storedAwardsKey) return
  awardSaveTimer = window.setTimeout(() => {
    void saveAwards()
  }, 400)
}

function marked(rows: {team: number, category: string}[], team: number, category: string) {
  return rows.some((row) => row.team === team && row.category === category)
}

function toggleMark(rows: {team: number, category: string}[], team: number, category: string) {
  const index = rows.findIndex((row) => row.team === team && row.category === category)
  if (index >= 0) rows.splice(index, 1)
  else rows.push({team, category})
}

async function closeEvent() {
  window.clearTimeout(awardSaveTimer)
  if (awardSave) {
    await saveAwards()
    await nextTick()
  }
  if (awardsKey() !== storedAwardsKey) {
    await saveAwards()
    if (awardError.value) return
  }
  window.clearTimeout(awardSaveTimer)
  const {data} = await axios.post(`/scor/events/${props.results.event.id}/close`)
  emit('saved', {...props.results, lifecycle: data})
}

const awardsTouched = computed(() => {
  const podiumOnly = resolvePodium([]).map((row) => `${row.team}:${row.category}`).sort().join(',')
  return awardsKey() !== `${podiumOnly}||`
})

function confirmReset() {
  resetOpen.value = false
  if (!awardsEditable.value) return
  nominations.value = []
  coaching.value = ''
  trophies.value = resolvePodium([])
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
  if (awardSave) {
    awardSaveAgain = true
    return awardSave
  }
  awardSave = sendAwards()
  try {
    await awardSave
  } finally {
    awardSave = null
  }
  await nextTick()
  if (awardSaveAgain) {
    awardSaveAgain = false
    queueAwardSave()
  }
}

async function sendAwards() {
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
const awardSortKey = ref('place')
const awardSortDir = ref<'asc' | 'desc'>('asc')
const sortDir = ref<'asc' | 'desc'>('asc')
const board = ref<'spiel' | 'jury' | 'awards'>('spiel')
onBeforeUnmount(() => {
  window.clearTimeout(awardSaveTimer)
  stopReveal()
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
  if (key === 'robot') return Number(team.robot_rank ?? 999)
  if (key === 'place') {
    const slot = podium.findIndex((entry) => marked(trophies.value, team.id, entry.id))
    return shownPlace(team) + (slot >= 0 ? slot : podium.length) / 10
  }
  if (key === 'coaching') return hasTrophy(team.id, 'coaching') ? 1 : 0
  const nominated = marked(nominations.value, team.id, key) ? 1 : 0
  return nominated + (hasTrophy(team.id, key) ? 1 : 0)
}

const sortedAwardTeams = computed(() => {
  const teams = [...awardTeams.value]
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
  if (awardSortKey.value === key) {
    awardSortDir.value = awardSortDir.value === 'asc' ? 'desc' : 'asc'
    return
  }
  awardSortKey.value = key
  awardSortDir.value = ['number', 'robot', 'place'].includes(key) ? 'asc' : 'desc'
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
    <PageTabs
        fill
        aria-label="Tabelle"
        :tabs="[
          {id: 'spiel', label: 'Spiel'},
          {id: 'jury', label: 'Jury-Bewertungen'},
          {id: 'awards', label: 'Auszeichnungen'},
        ]"
        :model-value="board"
        @update:model-value="board = $event"
    />

    <section v-if="board === 'spiel'" class="scor-results__block">
      <div class="scor-award-tools">
        <p class="scor-results__note">
          Im Vollbild sind alle Zeilen verdeckt. Drücke G, damit sie von unten nach oben nacheinander erscheinen.
          G hält an und läuft weiter.
        </p>
        <button type="button" class="scor-present" :aria-label="gamePresenting ? 'Vollbild beenden' : 'Vollbild'" @click="gamePresenting ? stopPresent() : startPresent('spiel')">
          <i class="bi" :class="gamePresenting ? 'bi-fullscreen-exit' : 'bi-fullscreen'" aria-hidden="true"/>
        </button>
      </div>
      <div ref="gameRoot" class="scor-stage scor-game" :class="{'is-present': gamePresenting}">
        <div class="scor-board glass-card liquid-surface-inner">
          <table>
            <thead>
              <tr>
                <th scope="col">Team</th>
                <th
                    v-for="round in results.rounds"
                    :key="round.round"
                    scope="col"
                    :class="{'is-latest': round.round === latestRound}"
                >{{ round.label }}</th>
                <th scope="col">Punktedurchschnitt</th>
              </tr>
            </thead>
            <tbody>
              <tr
                  v-for="(team, index) in byRobot"
                  :key="team.id"
                  :class="{'is-covered': covered(index), 'is-current': justRevealed(index)}"
                  :aria-hidden="covered(index) ? 'true' : undefined"
              >
                <th scope="row">
                  <span class="scor-team__name">{{ team.name }}</span>
                  <span class="scor-team__number">{{ team.number }}</span>
                </th>
                <td v-for="round in results.rounds" :key="round.round" :class="{'is-latest': round.round === latestRound}">
                  <template v-if="roundCell(team, round.round)">
                    <span class="scor-points">{{ roundCell(team, round.round).points }}</span>
                    <span v-if="!gamePresenting && !roundCell(team, round.round).confirmed" class="scor-points__state">begonnen</span>
                  </template>
                  <span v-else class="scor-points__state">–</span>
                </td>
                <td>
                  <span class="scor-points scor-points--best">ø {{ formatPoints(team.robot) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p class="scor-results__note">
        Gespeicherte Bögen zählen mit. „begonnen“ heißt: Schiri und Team haben noch nicht beide bestätigt.
        Die Testrunde steht hier nicht. Der Punktedurchschnitt lässt eine fehlende Runde als 0 zählen.
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
        <div class="scor-award-tools">
          <p class="scor-results__note">
            Champion, Silber und Bronze folgen der Gesamtwertung. Bei Gleichstand wähle die Teams in Platz-Reihenfolge.
            Je Kategorie {{ nominationSlots }} Sterne, der Pokal geht an ein nominiertes Team.
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
                  <button type="button" class="scor-sort" :class="{ 'is-active': awardSortKey === 'number' }" :aria-label="`Team, ${awardSortLabel('number')}`" @click="toggleAwardSort('number')">
                    Team
                    <i v-if="awardSortKey === 'number'" class="bi" :class="awardSortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill'" aria-hidden="true"/>
                  </button>
                </th>
                <th v-for="column in awardColumns" :key="column.id" scope="col">
                  <button type="button" class="scor-sort" :class="{ 'is-active': awardSortKey === column.id }" :aria-label="`${column.label}, ${awardSortLabel(column.id)}`" @click="toggleAwardSort(column.id)">
                    {{ column.label }}
                    <span v-if="column.nominate" class="scor-award-count">{{ nominationCount(column.id) }}/{{ nominationSlots }}</span>
                    <i v-if="awardSortKey === column.id" class="bi" :class="awardSortDir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill'" aria-hidden="true"/>
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
                <td v-for="column in awardColumns" :key="column.id">
                  <div v-if="column.id === 'robot'" class="scor-award-cell scor-award-cell--result">
                    <span class="scor-place">{{ team.robot_rank }}</span>
                    <span class="scor-place__points">ø {{ formatPoints(team.robot) }}</span>
                  </div>
                  <div v-else-if="column.id === 'place'" class="scor-award-cell scor-award-cell--result">
                    <span class="scor-place">{{ shownPlace(team) }}</span>
                    <span class="scor-place__points">{{ formatPoints(team.champion) }}</span>
                    <button
                        v-if="placeOf(team.id) || isPodiumTie(team)"
                        type="button"
                        class="scor-medal"
                        :class="[placeOf(team.id) ? `is-${placeOf(team.id)?.id}` : '', {'is-tie': isPodiumTie(team)}]"
                        :disabled="!canPlace(team)"
                        :aria-pressed="isPodiumTie(team) ? Boolean(placeOf(team.id)) : undefined"
                        :aria-label="placeOf(team.id)?.label || 'Platz bei Gleichstand wählen'"
                        @click="togglePlace(team)"
                    >
                      <i class="bi" :class="placeOf(team.id) ? 'bi-trophy-fill' : 'bi-trophy'" aria-hidden="true"/>
                      <span>{{ placeOf(team.id)?.label || 'gleichauf' }}</span>
                    </button>
                  </div>
                  <div v-else class="scor-award-cell">
                    <button
                        v-if="column.nominate"
                        type="button"
                        class="scor-mark"
                        :class="{'is-on': marked(nominations, team.id, column.id)}"
                        :disabled="!canNominate(column.id, team.id)"
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
                        :class="{'is-on': hasTrophy(team.id, column.id)}"
                        :disabled="!canAward(team, column.id)"
                        :aria-pressed="hasTrophy(team.id, column.id)"
                        :aria-label="`${column.label} Pokal`"
                        @click="toggleAward(team, column.id)"
                    >
                      <i class="bi" :class="hasTrophy(team.id, column.id) ? 'bi-trophy-fill' : 'bi-trophy'" aria-hidden="true"/>
                    </button>
                    <span v-if="column.nominate" class="scor-place__points">{{ formatPoints(team[column.id]) }}</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p v-if="awardError" class="text-lg text-red-700">{{ awardError }}</p>
      <template v-if="results.can_edit_awards && results.lifecycle">
        <div v-if="!results.lifecycle.closed" class="scor-award-actions">
          <button
              type="button"
              class="glass-btn-secondary scor-reset"
              :disabled="!awardsEditable || !awardsTouched || awardBusy"
              @click="resetOpen = true"
          >
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"/>
            Tabelle zurücksetzen
          </button>
          <button
              type="button"
              class="scor-finalize"
              :disabled="!awardsComplete || awardBusy"
              @click="closeEvent"
          >
            Auswertung festschreiben
          </button>
        </div>
        <p v-if="results.lifecycle.closed && !results.lifecycle.released" class="scor-results__note">
          Die Auswertung ist festgeschrieben. Änderungen sind nicht mehr möglich. Öffentlich sichtbar ist sie noch nicht.
          <template v-if="!results.flow_admin">Veröffentlichen kann nur ein FLOW-Admin.</template>
        </p>
        <button
            v-if="results.flow_admin && results.lifecycle.closed && !results.lifecycle.released"
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
      <div v-if="resetOpen" class="scor-publish" role="dialog" aria-modal="true" aria-labelledby="scor-reset-title">
        <div class="scor-publish__card">
          <h2 id="scor-reset-title">Tabelle zurücksetzen</h2>
          <p>Alle Sterne, Pokale und gewählten Plätze bei Gleichstand werden entfernt. Champion, Silber und Bronze ohne Gleichstand bleiben gesetzt.</p>
          <div class="scor-publish__actions">
            <button type="button" class="glass-btn-secondary" @click="resetOpen = false">Abbrechen</button>
            <button type="button" class="glass-btn-accent" @click="confirmReset">Zurücksetzen</button>
          </div>
        </div>
      </div>
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

.scor-award-tools {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.scor-sort:disabled {
  cursor: default;
}

.scor-stage {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.scor-award-actions {
  display: flex;
  align-items: stretch;
  gap: 0.75rem;
}

.scor-award-actions .scor-finalize {
  flex: 1 1 auto;
  width: auto;
}

.scor-reset {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  flex: none;
  min-height: 3.25rem;
  font-weight: 700;
}

.scor-reset:disabled {
  opacity: 0.45;
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

.scor-stage:fullscreen .scor-mark {
  width: 3.4rem;
  height: 3.4rem;
  font-size: 1.85rem;
}

.scor-game.is-present .scor-board table,
.scor-game:fullscreen .scor-board table {
  min-width: 0;
}

.scor-game:fullscreen .scor-board th,
.scor-game:fullscreen .scor-board td {
  padding: 1rem 1.25rem;
  vertical-align: middle;
}

.scor-game:fullscreen thead th {
  font-size: 1.4rem;
}

.scor-game:fullscreen .scor-team__name {
  font-size: 2rem;
}

.scor-game:fullscreen .scor-team__number {
  font-size: 1.2rem;
}

.scor-game:fullscreen .scor-points {
  font-size: 2.4rem;
}

.scor-game tbody tr > * {
  transition: opacity 0.6s ease-out, transform 0.6s ease-out, background-color 0.4s ease;
}

.scor-game tbody tr.is-covered > * {
  opacity: 0;
  transform: translateY(0.6rem);
}

.scor-game .is-latest {
  background: color-mix(in srgb, var(--color-accent) 12%, var(--color-bg));
}

.scor-game thead th.is-latest {
  color: var(--color-accent);
  box-shadow: inset 0 -3px 0 var(--color-accent);
}

.scor-game tbody tr.is-current td,
.scor-game tbody tr.is-current th {
  background: color-mix(in srgb, var(--color-accent) 18%, var(--color-bg));
}

.scor-game tbody tr.is-current td.is-latest {
  background: color-mix(in srgb, var(--color-accent) 30%, var(--color-bg));
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

.scor-mark:disabled {
  opacity: 0.35;
  cursor: default;
}

.scor-medal {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  margin-top: 0.2rem;
  padding: 0.15rem 0.45rem;
  border: 1px solid transparent;
  border-radius: 999px;
  background: transparent;
  color: var(--color-text-muted);
  font: inherit;
  font-size: 0.78rem;
  font-weight: 800;
  cursor: pointer;
}

.scor-medal.is-tie {
  border-color: var(--color-border);
}

.scor-medal.is-champion {
  color: #a16207;
}

.scor-medal.is-silver {
  color: #64748b;
}

.scor-medal.is-bronze {
  color: #9a3412;
}

.scor-medal:disabled {
  cursor: default;
}

.scor-medal.is-tie:disabled:not([aria-pressed='true']) {
  opacity: 0.35;
}

.scor-medal:not(:disabled):hover {
  background: color-mix(in srgb, var(--color-text) 8%, transparent);
}

.scor-stage:fullscreen .scor-medal {
  font-size: 1.05rem;
}

.scor-mark:not(:disabled):hover {
  background: color-mix(in srgb, var(--color-text) 8%, transparent);
}
</style>
