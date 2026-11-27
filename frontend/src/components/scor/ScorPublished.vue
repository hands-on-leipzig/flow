<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import PageTabs from '@hands-on/glass/page-tabs'

const props = defineProps<{eventId: string | number, payload?: any, title?: string, embedded?: boolean}>()
const emit = defineEmits<{exit: []}>()
const results = ref<any>(props.payload || null)
const error = ref('')
const tab = ref<'gesamt' | 'spiel'>('gesamt')

const placeLabels: Record<string, string> = {champion: '1. Platz', silver: '2. Platz', bronze: '3. Platz'}
const categoryLabels: Record<string, string> = {
  research: 'Projekt',
  robotdesign: 'Technisches Design',
  core_values: 'Grundwerte',
}

const awards = computed(() => results.value?.awards || null)
const game = computed(() => results.value?.game || {rounds: [], teams: []})

const roundBest = computed(() => {
  const best: Record<number, number> = {}
  for (const team of game.value.teams) {
    for (const cell of team.rounds || []) {
      if (cell && cell.points > 0 && cell.points > (best[cell.round] ?? 0)) best[cell.round] = cell.points
    }
  }
  return best
})

onMounted(async () => {
  if (results.value) return
  try {
    const {data} = await axios.get(`/scor/public/results/${props.eventId}`)
    results.value = data
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Die Ergebnisse sind nicht veröffentlicht.'
  }
})

function teamLine(team: any) {
  return team ? `${team.number} ${team.name}` : ''
}

function roundPoints(team: any, round: number) {
  return (team.rounds || []).find((cell: any) => cell && cell.round === round)?.points ?? null
}

function formatPoints(value: number | null) {
  if (value === null || !Number.isFinite(value)) return '–'
  if (Number.isInteger(value)) return String(value)
  return value.toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1})
}
</script>

<template>
  <div class="scor-public" :class="{'is-embedded': embedded}">
    <header class="glass-card liquid-surface-inner scor-public__head">
      <div>
        <p class="scor-public__kicker">Ergebnisse</p>
        <h1>{{ title || results?.event?.name || 'Ergebnisse' }}</h1>
      </div>
      <button v-if="embedded" type="button" class="glass-btn-secondary" @click="emit('exit')">
        Zurück zur öffentlichen Seite
      </button>
    </header>

    <p v-if="error" class="scor-public__state">{{ error }}</p>
    <p v-else-if="!results" class="scor-public__state">Wird geladen …</p>

    <template v-else>
      <PageTabs
          aria-label="Ergebnisse"
          :tabs="[
            {id: 'gesamt', label: 'Gesamtergebnis'},
            {id: 'spiel', label: 'Spiel'},
          ]"
          :model-value="tab"
          @update:model-value="tab = $event"
      />

      <section v-if="tab === 'gesamt'" class="glass-card liquid-surface-inner scor-public__section" role="tabpanel">
        <h2>Auszeichnungen</h2>
        <ul v-if="awards.podium.length" class="scor-public__podium">
          <li v-for="entry in awards.podium" :key="entry.place" :class="`is-${entry.place}`">
            <i class="bi bi-trophy-fill" aria-hidden="true"/>
            <span class="scor-public__label">{{ placeLabels[entry.place] }}</span>
            <strong>{{ teamLine(entry.team) }}</strong>
          </li>
        </ul>

        <div class="scor-public__categories">
          <article v-for="category in awards.categories" :key="category.category" class="scor-public__category">
            <h3>{{ categoryLabels[category.category] || category.category }}</h3>
            <p class="scor-public__label">Nominiert</p>
            <ul v-if="category.nominees.length">
              <li v-for="team in category.nominees" :key="team.number">
                <i class="bi bi-star-fill" aria-hidden="true"/>
                <span>{{ teamLine(team) }}</span>
              </li>
            </ul>
            <p v-else class="scor-public__muted">Keine Nominierung.</p>
            <template v-if="category.winner">
              <p class="scor-public__label scor-public__label--award">Auszeichnung</p>
              <p class="scor-public__winner">
                <i class="bi bi-trophy-fill" aria-hidden="true"/>
                <span>{{ teamLine(category.winner) }}</span>
              </p>
            </template>
          </article>

          <article v-if="awards.coaching" class="scor-public__category">
            <h3>Coaching</h3>
            <p class="scor-public__label scor-public__label--award">Auszeichnung</p>
            <p class="scor-public__winner">
              <i class="bi bi-trophy-fill" aria-hidden="true"/>
              <span>{{ teamLine(awards.coaching) }}</span>
            </p>
          </article>

          <article v-if="awards.game.length" class="scor-public__category">
            <h3>Spiel</h3>
            <p class="scor-public__label scor-public__label--award">Auszeichnung</p>
            <p v-for="team in awards.game" :key="team.number" class="scor-public__winner">
              <i class="bi bi-trophy-fill" aria-hidden="true"/>
              <span>{{ teamLine(team) }}</span>
            </p>
          </article>
        </div>
        <p class="scor-public__muted">Nominierte Teams stehen nach Teamnummer.</p>
      </section>

      <section v-else class="glass-card liquid-surface-inner scor-public__section" role="tabpanel">
        <h2>Spiel</h2>
        <div class="scor-public__table">
          <table>
            <thead>
              <tr>
                <th scope="col">Platz</th>
                <th scope="col">Team</th>
                <th v-for="round in game.rounds" :key="round.round" scope="col">{{ round.label }}</th>
                <th scope="col">Bester Lauf</th>
                <th scope="col">Punktedurchschnitt</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="team in game.teams" :key="team.number">
                <td class="scor-public__rank">{{ team.rank }}</td>
                <th scope="row">
                  <span class="scor-public__team">{{ team.name }}</span>
                  <span class="scor-public__muted">{{ team.number }}</span>
                </th>
                <td
                    v-for="round in game.rounds"
                    :key="round.round"
                    :class="{'is-round-best': roundPoints(team, round.round) !== null && roundPoints(team, round.round) === roundBest[round.round]}"
                >
                  {{ roundPoints(team, round.round) ?? '–' }}
                </td>
                <td>{{ formatPoints(team.best) }}</td>
                <td class="scor-public__average">ø {{ formatPoints(team.average) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="scor-public__muted">
          Der Platz folgt dem Punktedurchschnitt aller Wertungsrunden. Eine fehlende Runde zählt als 0.
          Hervorgehoben ist das höchste Ergebnis jeder Runde.
        </p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.scor-public {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 56rem;
  margin: 0 auto;
  padding: 1rem;
}

.scor-public.is-embedded {
  max-width: none;
  padding: 0;
}

.scor-public__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.scor-public__head h1 {
  margin: 0;
  font-size: 1.6rem;
  font-weight: 800;
}

.scor-public__kicker {
  margin: 0 0 0.2rem;
  color: var(--color-text-muted);
  font-size: 0.85rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.scor-public__state {
  font-size: 1.1rem;
}

.scor-public__section {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.scor-public__section h2 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 800;
}

.scor-public__podium {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: 0.75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.scor-public__podium li {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-bg-muted);
}

.scor-public__podium .bi {
  font-size: 1.5rem;
}

.scor-public__podium .is-champion .bi {
  color: #a16207;
}

.scor-public__podium .is-silver .bi {
  color: #64748b;
}

.scor-public__podium .is-bronze .bi {
  color: #9a3412;
}

.scor-public__podium strong {
  font-size: 1.1rem;
}

.scor-public__label {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
  font-weight: 700;
}

.scor-public__label--award {
  margin-top: 0.75rem;
}

.scor-public__categories {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0.75rem;
}

.scor-public__category {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.85rem 1rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}

.scor-public__category h3 {
  margin: 0 0 0.25rem;
  font-size: 1rem;
  font-weight: 800;
}

.scor-public__category ul {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.scor-public__category li {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}

.scor-public__category li .bi {
  color: #b45309;
}

.scor-public__winner {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  font-weight: 800;
}

.scor-public__winner .bi {
  color: #146c36;
}

.scor-public__muted {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.scor-public__table {
  overflow-x: auto;
}

.scor-public__table table {
  width: 100%;
  border-collapse: collapse;
}

.scor-public__table th,
.scor-public__table td {
  padding: 0.6rem 0.7rem;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  vertical-align: top;
  font-variant-numeric: tabular-nums;
}

.scor-public__table thead th {
  font-size: 0.9rem;
  font-weight: 700;
}

.scor-public__table td.is-round-best {
  font-weight: 800;
  color: #146c36;
}

.scor-public__team {
  display: block;
  font-weight: 800;
}

.scor-public__rank,
.scor-public__average {
  font-weight: 800;
}
</style>
