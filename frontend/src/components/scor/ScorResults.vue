<script setup lang="ts">
import {computed, ref} from 'vue'

const props = defineProps<{results: any}>()

const sortKey = ref('champion_rank')
const sortDir = ref<'asc' | 'desc'>('asc')

const columns = [
  {key: 'champion_rank', label: 'Champion', note: '*'},
  {key: 'robot_rank', label: 'Robot-Game', note: '**', points: 'robot'},
  {key: 'research_rank', label: 'Forschung', points: 'research'},
  {key: 'robotdesign_rank', label: 'Roboterdesign', points: 'robotdesign'},
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
                <span v-if="column.points" class="scor-place__points">{{ team[column.points] }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="scor-results__note">
        * Gesamtwertung aus Robot-Game, Forschung, Roboterdesign und Grundwerten.
        Gleiche Punktzahl, gleicher Platz.
        <br>
        ** Beste Runde, ohne Testrunde. Darunter steht die Punktzahl.
      </p>
    </section>

    <section class="scor-results__block">
      <h2 class="scor-results__title">Robot-Game</h2>
      <div class="scor-board glass-card liquid-surface-inner">
        <table>
          <thead>
            <tr>
              <th scope="col">Team</th>
              <th v-for="round in results.rounds" :key="round.round" scope="col">{{ round.label }}</th>
              <th scope="col">Beste Runde</th>
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
                <span class="scor-points scor-points--best">{{ team.robot }}</span>
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
</style>
