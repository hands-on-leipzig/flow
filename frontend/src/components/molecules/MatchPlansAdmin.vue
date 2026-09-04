<script setup>
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'MatchPlansAdmin'})

/** @typedef {{ round: number, match_no: number, table_1: number, table_2: number, table_1_team: number, table_2_team: number }} MatchRow */

const programs = ref([])
const storedKeys = ref([])
const firstProgram = ref(3)
const teams = ref(8)
const tables = ref(2)
/** @type {import('vue').Ref<MatchRow[]>} */
const matches = ref([])
const existsInDb = ref(false)
const loading = ref(false)
const saving = ref(false)
const quality = ref(null)
const dirty = ref(false)

const selectedProgram = computed(() =>
  programs.value.find((p) => p.id === firstProgram.value) ?? null,
)

const maxMatchRounds = computed(() =>
  Number(selectedProgram.value?.max_match_rounds ?? 3),
)

const matchesPerRound = computed(() =>
  Math.ceil(Math.max(2, Number(teams.value) || 2) / 2),
)

const roundsPresent = computed(() => {
  const set = new Set(matches.value.map((m) => Number(m.round)))
  return [...set].sort((a, b) => a - b)
})

const scoringRounds = computed(() => roundsPresent.value.filter((r) => r >= 1))

/** How many match rows a round must have (never below R1 / densest scoring round). */
function neededMatchCount() {
  const fromFormula = matchesPerRound.value
  let fromExisting = 0
  for (const r of scoringRounds.value) {
    fromExisting = Math.max(fromExisting, matchesForRound(r).length)
  }
  // Include TR in case scoring rounds are empty/short
  fromExisting = Math.max(fromExisting, matchesForRound(0).length)
  return Math.max(fromFormula, fromExisting)
}

const canAddRound = computed(() => {
  const max = Math.max(...scoringRounds.value, 0)
  return max < maxMatchRounds.value
})

const canRemoveRound = computed(() => scoringRounds.value.length > 1)

const existsWarning = computed(() => {
  return storedKeys.value.some(
    (k) =>
      Number(k.first_program) === Number(firstProgram.value) &&
      Number(k.teams) === Number(teams.value) &&
      Number(k.tables) === Number(tables.value),
  )
})

function emptyMatch(round, matchNo, pair = [1, 2]) {
  return {
    round,
    match_no: matchNo,
    table_1: pair[0],
    table_2: pair[1],
    table_1_team: 0,
    table_2_team: 0,
  }
}

function buildEmptyPlan(roundList = [0, 1]) {
  /** @type {MatchRow[]} */
  const rows = []
  const per = matchesPerRound.value
  for (const round of roundList) {
    for (let i = 1; i <= per; i++) {
      // Start every slot on pair 1–2; user moves to 3–4 when needed.
      rows.push(emptyMatch(round, i, [1, 2]))
    }
  }
  return rows
}

function matchesForRound(round) {
  const r = Number(round)
  return matches.value
    .filter((m) => Number(m.round) === r)
    .slice()
    .sort((a, b) => Number(a.match_no) - Number(b.match_no))
}

function roundLabel(round) {
  return round === 0 ? 'Testrunde (TR)' : `Runde ${round}`
}

function isPair34(match) {
  return Number(match.table_1) === 3 && Number(match.table_2) === 4
}

/** Active pair inputs: always T1–T2 when only 2 tables; otherwise by stored pair. */
function showsPair12(match) {
  return tables.value === 2 || !isPair34(match)
}

function showsPair34(match) {
  return tables.value === 4 && isPair34(match)
}

function duplicateTeamsInRound(round) {
  const counts = new Map()
  for (const m of matchesForRound(round)) {
    for (const t of [m.table_1_team, m.table_2_team]) {
      const n = Number(t)
      if (!Number.isFinite(n) || n <= 0) continue
      counts.set(n, (counts.get(n) ?? 0) + 1)
    }
  }
  const dups = new Set()
  for (const [t, c] of counts) {
    if (c > 1) dups.add(t)
  }
  return dups
}

function teamClass(round, team) {
  const n = Number(team)
  if (!Number.isFinite(n) || n <= 0) return ''
  return duplicateTeamsInRound(round).has(n) ? 'team-num--dup' : ''
}

function confirmChange(message, {irreversible = false} = {}) {
  const suffix = irreversible
    ? '\n\nDiese Änderung kann nicht rückgängig gemacht werden!'
    : ''
  return window.confirm(`${message}${suffix}`)
}

function onProgramSelect(event) {
  const next = Number(event.target.value)
  if (next === firstProgram.value) return
  const label = programs.value.find((p) => p.id === next)
  const name = label?.display_name || label?.name || String(next)
  if (!confirmChange(`Programm auf „${name}“ wechseln?`)) {
    event.target.value = String(firstProgram.value)
    return
  }
  changeProgram(next)
}

function onTeamsInput(event) {
  const raw = String(event.target.value).trim()
  const next = Number(raw)
  if (!Number.isFinite(next) || next < 2 || next === teams.value) {
    event.target.value = String(teams.value)
    return
  }
  const irreversible = next < teams.value
  if (!confirmChange(`Teams von ${teams.value} auf ${next} ändern?`, {irreversible})) {
    event.target.value = String(teams.value)
    return
  }
  resizeTeams(next)
  event.target.value = String(teams.value)
}

function onTablesSelect(event) {
  const next = Number(event.target.value)
  if (next === tables.value) return
  if (next !== 2 && next !== 4) {
    event.target.value = String(tables.value)
    return
  }
  const irreversible = next < tables.value
  if (!confirmChange(`Tische von ${tables.value} auf ${next} ändern?`, {irreversible})) {
    event.target.value = String(tables.value)
    return
  }
  changeTables(next)
}

function resizeTeams(newTeams) {
  if (newTeams === teams.value) return
  if (newTeams < 2) return
  const roundList = roundsPresent.value.length ? roundsPresent.value : [0, 1]
  const per = Math.ceil(newTeams / 2)
  /** @type {MatchRow[]} */
  const next = []
  for (const round of roundList) {
    const existing = matchesForRound(round)
    for (let i = 1; i <= per; i++) {
      const src = existing.find((m) => m.match_no === i)
      if (src) {
        next.push({
          ...src,
          match_no: i,
          table_1_team: src.table_1_team > newTeams ? 0 : src.table_1_team,
          table_2_team: src.table_2_team > newTeams ? 0 : src.table_2_team,
          ...(tables.value === 2 ? {table_1: 1, table_2: 2} : {}),
        })
      } else {
        next.push(emptyMatch(round, i))
      }
    }
  }
  teams.value = newTeams
  matches.value = next
  dirty.value = true
  void refreshQuality()
}

function changeTables(newTables) {
  if (newTables === tables.value) return
  if (newTables !== 2 && newTables !== 4) return
  if (newTables < tables.value) {
    matches.value = matches.value.map((m) => ({
      ...m,
      table_1: 1,
      table_2: 2,
    }))
  }
  tables.value = newTables
  dirty.value = true
  void refreshQuality()
}

function changeProgram(id) {
  firstProgram.value = Number(id)
  const max = maxMatchRounds.value
  if (roundsPresent.value.some((r) => r > max)) {
    matches.value = matches.value.filter((m) => m.round === 0 || m.round <= max)
    dirty.value = true
  }
  void refreshQuality()
}

function parseTeamField(match, key, event) {
  const raw = String(event.target.value).trim()
  if (raw === '') {
    match[key] = 0
    event.target.value = '0'
    onTeamEdit()
    return
  }
  const n = Number(raw)
  if (!Number.isFinite(n) || n < 0 || n > teams.value || !Number.isInteger(n)) {
    event.target.value = String(match[key] ?? 0)
    return
  }
  match[key] = n
  event.target.value = String(n)
  onTeamEdit()
}

function addRound() {
  if (!canAddRound.value) return
  const scoring = scoringRounds.value
  const nextRound = (scoring.length ? Math.max(...scoring.map(Number)) : 0) + 1
  if (nextRound > maxMatchRounds.value) return

  const per = neededMatchCount()
  const template = matchesForRound(1)
  const added = []
  for (let matchNo = 1; matchNo <= per; matchNo++) {
    const src = template.find((m) => Number(m.match_no) === matchNo)
    let pair = [1, 2]
    if (tables.value === 4 && src) {
      const t1 = Number(src.table_1)
      const t2 = Number(src.table_2)
      if (t1 === 3 && t2 === 4) {
        pair = [3, 4]
      } else if (t1 === 1 && t2 === 2) {
        pair = [1, 2]
      } else {
        pair = [1, 2]
      }
    }
    added.push(emptyMatch(nextRound, matchNo, pair))
  }
  matches.value = matches.value.concat(added)
  dirty.value = true
  void refreshQuality()
}

function removeLastRound() {
  if (!canRemoveRound.value) return
  const last = Math.max(...scoringRounds.value)
  if (last <= 1) return
  matches.value = matches.value.filter((m) => m.round !== last)
  dirty.value = true
  void refreshQuality()
}

function moveMatch(round, matchNo, direction) {
  const swapWith = matchNo + direction
  const per = matchesPerRound.value
  if (swapWith < 1 || swapWith > per) return
  const a = matches.value.find((m) => m.round === round && m.match_no === matchNo)
  const b = matches.value.find((m) => m.round === round && m.match_no === swapWith)
  if (!a || !b) return
  const aTeams = {table_1_team: a.table_1_team, table_2_team: a.table_2_team, table_1: a.table_1, table_2: a.table_2}
  a.table_1_team = b.table_1_team
  a.table_2_team = b.table_2_team
  a.table_1 = b.table_1
  a.table_2 = b.table_2
  b.table_1_team = aTeams.table_1_team
  b.table_2_team = aTeams.table_2_team
  b.table_1 = aTeams.table_1
  b.table_2 = aTeams.table_2
  dirty.value = true
  void refreshQuality()
}

function togglePair(match) {
  if (tables.value !== 4) return
  if (isPair34(match)) {
    match.table_1 = 1
    match.table_2 = 2
  } else {
    match.table_1 = 3
    match.table_2 = 4
  }
  dirty.value = true
  void refreshQuality()
}

function swapTeamsInMatch(match) {
  const a = match.table_1_team
  match.table_1_team = match.table_2_team
  match.table_2_team = a
  dirty.value = true
  void refreshQuality()
}

function onTeamEdit() {
  dirty.value = true
  void refreshQuality()
}

async function loadPrograms() {
  const {data} = await axios.get('/admin/match-plans/programs')
  programs.value = data.programs ?? []
  if (!programs.value.some((p) => p.id === firstProgram.value) && programs.value.length) {
    firstProgram.value = programs.value[0].id
  }
}

async function loadKeys() {
  const {data} = await axios.get('/admin/match-plans/keys')
  storedKeys.value = data.keys ?? []
}

async function loadFromDb() {
  loading.value = true
  try {
    const {data} = await axios.get('/admin/match-plans', {
      params: {
        first_program: firstProgram.value,
        teams: teams.value,
        tables: tables.value,
      },
    })
    existsInDb.value = Boolean(data.exists)
    if (data.exists && Array.isArray(data.matches) && data.matches.length) {
      matches.value = data.matches.map((m) => ({
        round: Number(m.round),
        match_no: Number(m.match_no),
        table_1: Number(m.table_1),
        table_2: Number(m.table_2),
        table_1_team: Number(m.table_1_team),
        table_2_team: Number(m.table_2_team),
      }))
      showGlassToast('Plan geladen', 'success')
    } else {
      matches.value = buildEmptyPlan()
      showGlassToast('Kein gespeicherter Plan — leeres Raster', 'info')
    }
    dirty.value = false
    await refreshQuality()
  } catch (e) {
    showGlassToast(e?.response?.data?.error || 'Laden fehlgeschlagen', 'error')
  } finally {
    loading.value = false
  }
}

async function saveToDb() {
  if (existsWarning.value) {
    const ok = window.confirm(
      `Plan für Programm ${firstProgram.value}, ${teams.value} Teams, ${tables.value} Tische existiert bereits und wird überschrieben. Fortfahren?`,
    )
    if (!ok) return
  }
  saving.value = true
  try {
    const {data} = await axios.put('/admin/match-plans', {
      first_program: firstProgram.value,
      teams: teams.value,
      tables: tables.value,
      matches: matches.value,
    })
    existsInDb.value = true
    dirty.value = false
    await loadKeys()
    showGlassToast(`Gespeichert (${data.matches?.length ?? 0} Zeilen)`, 'success')
  } catch (e) {
    showGlassToast(e?.response?.data?.error || 'Speichern fehlgeschlagen', 'error')
  } finally {
    saving.value = false
  }
}

async function deleteFromDb() {
  if (!existsWarning.value && !existsInDb.value) {
    showGlassToast('Kein Plan in der DB für diesen Schlüssel', 'info')
    return
  }
  if (!window.confirm('Plan in der DB löschen?')) return
  try {
    await axios.delete('/admin/match-plans', {
      data: {
        first_program: firstProgram.value,
        teams: teams.value,
        tables: tables.value,
      },
    })
    existsInDb.value = false
    await loadKeys()
    showGlassToast('Plan gelöscht', 'success')
  } catch (e) {
    showGlassToast(e?.response?.data?.error || 'Löschen fehlgeschlagen', 'error')
  }
}

let qualityTimer = null
async function refreshQuality() {
  if (qualityTimer) clearTimeout(qualityTimer)
  qualityTimer = setTimeout(async () => {
    try {
      const {data} = await axios.post('/admin/match-plans/quality', {
        teams: teams.value,
        tables: tables.value,
        matches: matches.value,
      })
      quality.value = data
    } catch {
      quality.value = null
    }
  }, 200)
}

function formatTeam(n) {
  return n === 0 || n === null || n === undefined ? '–' : String(n)
}

onMounted(async () => {
  try {
    await loadPrograms()
    await loadKeys()
    matches.value = buildEmptyPlan()
    await refreshQuality()
  } catch (e) {
    showGlassToast(e?.response?.data?.error || 'Initialisierung fehlgeschlagen', 'error')
  }
})

watch([firstProgram, teams, tables], () => {
  // existence flag for current key
  existsInDb.value = existsWarning.value
})
</script>

<template>
  <div class="match-plans-admin space-y-6">
    <!-- Section 1: Plan header -->
    <section class="glass-card liquid-surface-inner !p-4 space-y-3">
      <div class="text-sm font-semibold text-[var(--color-text-muted)]">Plan</div>
      <div class="flex flex-wrap gap-3 items-end">
        <label class="flex flex-col gap-1 text-sm">
          <span>Programm</span>
          <select
            class="glass-input min-w-[10rem]"
            :value="firstProgram"
            @change="onProgramSelect"
          >
            <option v-for="p in programs" :key="p.id" :value="p.id">
              {{ p.display_name || p.name }} (max {{ p.max_match_rounds }})
            </option>
          </select>
        </label>
        <label class="flex flex-col gap-1 text-sm">
          <span>Teams</span>
          <input
            type="text"
            inputmode="numeric"
            class="glass-input w-24"
            :value="teams"
            @change="onTeamsInput"
          />
        </label>
        <label class="flex flex-col gap-1 text-sm">
          <span>Tische</span>
          <select
            class="glass-input w-24"
            :value="tables"
            @change="onTablesSelect"
          >
            <option :value="2">2</option>
            <option :value="4">4</option>
          </select>
        </label>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="glass-btn-secondary !px-3 !py-1.5 !text-sm" :disabled="loading" @click="loadFromDb">
            Aus DB laden
          </button>
          <button type="button" class="glass-btn-accent !px-3 !py-1.5 !text-sm" :disabled="saving" @click="saveToDb">
            In DB speichern
          </button>
          <button type="button" class="glass-btn-secondary !px-3 !py-1.5 !text-sm" @click="deleteFromDb">
            Löschen
          </button>
        </div>
      </div>
      <p v-if="existsWarning" class="text-sm text-amber-700">
        Warnung: Für diese Kombination (Programm / Teams / Tische) existiert bereits ein Plan — Speichern überschreibt.
      </p>
      <p v-if="dirty" class="text-sm text-[var(--color-text-muted)]">Ungespeicherte Änderungen</p>
    </section>

    <!-- Section 2: Match grid -->
    <section class="glass-card liquid-surface-inner !p-4 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="text-sm font-semibold text-[var(--color-text-muted)]">Matches pro Runde</div>
        <div class="flex gap-2">
          <button type="button" class="glass-btn-secondary !px-3 !py-1 !text-sm" :disabled="!canAddRound" @click="addRound">
            + Runde
          </button>
          <button type="button" class="glass-btn-secondary !px-3 !py-1 !text-sm" :disabled="!canRemoveRound" @click="removeLastRound">
            − Letzte Runde
          </button>
        </div>
      </div>

      <div class="flex flex-row gap-4 overflow-x-auto pb-2">
        <div v-for="round in roundsPresent" :key="round" class="min-w-max">
          <div class="text-sm font-semibold text-[var(--color-text-muted)] mb-1">
            {{ roundLabel(round) }}
          </div>
          <table class="table-auto text-sm border-collapse glass-list match-grid">
            <thead class="bg-[color-mix(in_srgb,var(--color-bg-muted)_70%,transparent)]">
              <tr>
                <th class="px-0.5 py-1"></th>
                <th class="px-1 py-1">T1 ↔ T2</th>
                <th class="px-1 py-1" :class="tables === 2 ? 'opacity-40' : ''">T3 ↔ T4</th>
                <th v-if="tables === 4" class="px-0.5 py-1"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="match in matchesForRound(round)"
                :key="`${round}-${match.match_no}`"
                class="border-t"
              >
                <td class="px-0.5 py-0.5 whitespace-nowrap">
                  <button
                    type="button"
                    class="px-0.5 text-[var(--color-text-muted)] hover:text-[var(--color-text)]"
                    title="Nach oben"
                    :disabled="match.match_no <= 1"
                    @click="moveMatch(round, match.match_no, -1)"
                  >↑</button>
                  <button
                    type="button"
                    class="px-0.5 text-[var(--color-text-muted)] hover:text-[var(--color-text)]"
                    title="Nach unten"
                    :disabled="match.match_no >= matchesPerRound"
                    @click="moveMatch(round, match.match_no, 1)"
                  >↓</button>
                </td>
                <td class="px-0.5 py-0.5">
                  <div v-if="showsPair12(match)" class="match-pair">
                    <input
                      type="text"
                      inputmode="numeric"
                      maxlength="2"
                      class="team-num"
                      :class="teamClass(round, match.table_1_team)"
                      :value="match.table_1_team"
                      @change="parseTeamField(match, 'table_1_team', $event)"
                    />
                    <button
                      type="button"
                      class="swap-btn"
                      title="Teams tauschen"
                      @click="swapTeamsInMatch(match)"
                    >⇄</button>
                    <input
                      type="text"
                      inputmode="numeric"
                      maxlength="2"
                      class="team-num"
                      :class="teamClass(round, match.table_2_team)"
                      :value="match.table_2_team"
                      @change="parseTeamField(match, 'table_2_team', $event)"
                    />
                  </div>
                  <span v-else class="opacity-30 px-1">–</span>
                </td>
                <td class="px-0.5 py-0.5" :class="tables === 2 ? 'opacity-40' : ''">
                  <div v-if="showsPair34(match)" class="match-pair">
                    <input
                      type="text"
                      inputmode="numeric"
                      maxlength="2"
                      class="team-num"
                      :class="teamClass(round, match.table_1_team)"
                      :value="match.table_1_team"
                      @change="parseTeamField(match, 'table_1_team', $event)"
                    />
                    <button
                      type="button"
                      class="swap-btn"
                      title="Teams tauschen"
                      @click="swapTeamsInMatch(match)"
                    >⇄</button>
                    <input
                      type="text"
                      inputmode="numeric"
                      maxlength="2"
                      class="team-num"
                      :class="teamClass(round, match.table_2_team)"
                      :value="match.table_2_team"
                      @change="parseTeamField(match, 'table_2_team', $event)"
                    />
                  </div>
                  <span v-else class="opacity-30 px-1">–</span>
                </td>
                <td v-if="tables === 4" class="px-0.5 py-0.5">
                  <button
                    type="button"
                    class="glass-btn-secondary !px-1.5 !py-0.5 !text-sm"
                    :title="isPair34(match) ? 'Zu Tischen 1–2' : 'Zu Tischen 3–4'"
                    @click="togglePair(match)"
                  >
                    {{ isPair34(match) ? '←1/2' : '3/4→' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- Section 3: Quality -->
    <section class="glass-card liquid-surface-inner !p-4 space-y-4">
      <div class="text-sm font-semibold text-[var(--color-text-muted)]">Planqualität</div>

      <div
        v-if="quality?.meeting_matrix || quality?.match_summary"
        class="flex flex-col xl:flex-row gap-4 items-start"
      >
        <div v-if="quality?.meeting_matrix" class="overflow-x-auto min-w-0">
          <div class="text-sm font-semibold text-[var(--color-text-muted)] mb-1">
            Begegnungsmatrix
          </div>
          <table class="table-auto text-sm border-collapse glass-list">
            <thead>
              <tr>
                <th class="px-1 py-1"></th>
                <th
                  v-for="col in quality.meeting_matrix.labels"
                  :key="`h-${col}`"
                  class="px-1 py-1 text-center"
                >{{ col }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(row, ri) in quality.meeting_matrix.cells"
                :key="`r-${ri}`"
              >
                <th class="px-1 py-1 text-left">{{ quality.meeting_matrix.labels[ri] }}</th>
                <td
                  v-for="(cell, ci) in row"
                  :key="`c-${ri}-${ci}`"
                  class="px-1 py-1 text-center border-t"
                  :class="ri === ci ? 'bg-[color-mix(in_srgb,var(--color-bg-muted)_50%,transparent)]' : ''"
                >
                  {{ cell || '·' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="quality?.match_summary" class="overflow-x-auto min-w-0 flex-1">
          <div class="text-sm font-semibold text-[var(--color-text-muted)] mb-1">
            Testrunde, Tische und Teams gegenüber
          </div>
          <table class="table-auto text-sm border-collapse glass-list">
            <thead class="bg-[color-mix(in_srgb,var(--color-bg-muted)_70%,transparent)]">
              <tr>
                <th class="px-2 py-1 text-left">Team</th>
                <th class="px-2 py-1">TR</th>
                <th
                  v-for="r in (quality.scoring_rounds || [])"
                  :key="`th-t-${r}`"
                  class="px-2 py-1"
                >R{{ r }}</th>
                <th class="px-2 py-1">Tische</th>
                <th
                  v-for="r in (quality.scoring_rounds || [])"
                  :key="`th-o-${r}`"
                  class="px-2 py-1"
                >R{{ r }}</th>
                <th class="px-2 py-1">Teams</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in quality.match_summary" :key="row.team" class="border-t">
                <td class="px-2 py-1">{{ row.team }}</td>
                <td
                  class="text-center"
                  :class="row.tr_table !== row.r1_table ? 'text-red-600 font-semibold' : ''"
                >{{ formatTeam(row.tr_table) }}</td>
                <td
                  v-for="r in (quality.scoring_rounds || [])"
                  :key="`td-t-${row.team}-${r}`"
                  class="text-center"
                  :class="r === 1 && row.tr_table !== row.r1_table ? 'text-red-600 font-semibold' : ''"
                >{{ formatTeam(row[`r${r}_table`]) }}</td>
                <td class="text-center">
                  <span :class="row.q2_ok ? '' : 'text-amber-700'">{{ row.q2_ok ? '✓' : '⚠️' }}</span>
                  {{ row.tables ?? '–' }}
                </td>
                <td
                  v-for="r in (quality.scoring_rounds || [])"
                  :key="`td-o-${row.team}-${r}`"
                  class="text-center"
                >{{ formatTeam(row[`r${r}_opponent`]) }}</td>
                <td class="text-center">
                  <span :class="row.q3_ok ? '' : 'text-amber-700'">{{ row.q3_ok ? '✓' : '⚠️' }}</span>
                  {{ row.teams ?? '–' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.match-pair {
  display: inline-flex;
  align-items: center;
  gap: 1px;
}

.team-num {
  width: 2rem;
  min-width: 2rem;
  max-width: 2rem;
  padding: 0.15rem 0;
  margin: 0;
  border: 1px solid var(--color-border);
  border-radius: 0.25rem;
  background: #fff;
  color: #111;
  font-size: 0.875rem;
  line-height: 1.25;
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.team-num:focus {
  outline: 1px solid var(--color-accent, #3b82f6);
  outline-offset: 0;
}

.team-num--dup {
  background: #fecaca;
  border-color: #ef4444;
  color: #991b1b;
  font-weight: 600;
}

.swap-btn {
  padding: 0 0.15rem;
  border: none;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 0.875rem;
  line-height: 1;
  cursor: pointer;
}

.swap-btn:hover {
  color: var(--color-text);
}
</style>
