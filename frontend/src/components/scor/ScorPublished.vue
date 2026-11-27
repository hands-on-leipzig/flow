<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import PageTabs from '@hands-on/glass/page-tabs'

const props = defineProps<{eventId: string | number, payload?: any, title?: string, embedded?: boolean}>()
const emit = defineEmits<{exit: []}>()
const results = ref<any>(props.payload || null)
const error = ref('')
const tab = ref<'gesamt' | 'spiel'>('gesamt')

const placeNumbers: Record<string, string> = {champion: '1', silver: '2', bronze: '3'}
const categoryLabels: Record<string, string> = {
  research: 'Projekt',
  robotdesign: 'Technisches Design',
  core_values: 'Grundwerte',
}

const tabs = computed(() => [
  ...(props.embedded ? [{id: 'back', label: 'Zurück', icon: 'bi-chevron-left'}] : []),
  {id: 'gesamt', label: 'Gesamtergebnis'},
  {id: 'spiel', label: 'Spiel'},
])

function selectTab(id: string | number) {
  if (id === 'back') emit('exit')
  else tab.value = id as 'gesamt' | 'spiel'
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
    </header>

    <PageTabs
        fill
        aria-label="Ergebnisse"
        :tabs="tabs"
        :model-value="tab"
        @update:model-value="selectTab"
    />

    <p v-if="error" class="scor-public__state">{{ error }}</p>
    <p v-else-if="!results" class="scor-public__state">Wird geladen …</p>

    <template v-else>

      <section v-if="tab === 'gesamt'" class="glass-card liquid-surface-inner scor-public__section" role="tabpanel">
        <h2>Pokale</h2>
        <div v-if="awards.podium.length" class="scor-podium">
          <h3 class="scor-podium__title">Champion</h3>
          <ol class="scor-podium__stage">
            <li v-for="entry in awards.podium" :key="entry.place" class="scor-podium__step" :class="`is-${entry.place}`">
              <span class="scor-podium__team">
                <i class="bi bi-trophy-fill" aria-hidden="true"/>
                <strong>{{ entry.team.name }}</strong>
                <span class="scor-podium__number">{{ entry.team.number }}</span>
              </span>
              <span class="scor-podium__block">
                <span class="scor-podium__place">{{ placeNumbers[entry.place] }}</span>
                <span class="scor-podium__label">Platz</span>
              </span>
            </li>
          </ol>
        </div>

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
              <p class="scor-public__label scor-public__label--award">Pokal</p>
              <p class="scor-public__winner">
                <i class="bi bi-trophy-fill" aria-hidden="true"/>
                <span>{{ teamLine(category.winner) }}</span>
              </p>
            </template>
          </article>

          <article v-if="awards.coaching" class="scor-public__category">
            <h3>Coaching</h3>
            <p class="scor-public__label scor-public__label--award">Pokal</p>
            <p class="scor-public__winner">
              <i class="bi bi-trophy-fill" aria-hidden="true"/>
              <span>{{ teamLine(awards.coaching) }}</span>
            </p>
          </article>

          <article v-if="awards.game.length" class="scor-public__category scor-public__category--wide">
            <h3>Spiel</h3>
            <p class="scor-public__label scor-public__label--award">Pokal</p>
            <ol class="scor-public__places">
              <li v-for="team in awards.game" :key="team.number">
                <span class="scor-public__place">{{ team.place }}</span>
                <span>{{ teamLine(team) }}</span>
              </li>
            </ol>
          </article>
        </div>
      </section>

      <section v-else class="glass-card liquid-surface-inner scor-public__section" role="tabpanel">
        <h2>Spiel</h2>
        <ol v-if="awards.game.length" class="scor-public__places scor-public__places--bar">
          <li v-for="team in awards.game" :key="team.number">
            <span class="scor-public__place">{{ team.place }}</span>
            <span>{{ teamLine(team) }}</span>
          </li>
        </ol>
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
      </section>
    </template>
  </div>
</template>

<style scoped>
.scor-public {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  width: 100%;
  max-width: none;
  margin: 0 auto;
  padding: 1rem;
}

.scor-public.is-embedded {
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
  width: 100%;
}

.scor-public__section h2 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 800;
}

.scor-podium {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem 1rem 0;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: linear-gradient(180deg, color-mix(in srgb, #f5c542 10%, transparent) 0%, transparent 70%);
}

.scor-podium__title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 800;
  text-align: center;
}

.scor-podium__stage {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  align-items: end;
  gap: 0.6rem;
  width: 100%;
  margin: 0;
  padding: 0;
  list-style: none;
}

.scor-podium__step {
  grid-row: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.6rem;
  min-width: 0;
  text-align: center;
}

.scor-podium__step.is-silver {
  grid-column: 1;
}

.scor-podium__step.is-champion {
  grid-column: 2;
}

.scor-podium__step.is-bronze {
  grid-column: 3;
}

.scor-podium__team {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.15rem;
  min-width: 0;
  max-width: 100%;
}

.scor-podium__team .bi {
  font-size: 2rem;
}

.scor-podium__team strong {
  font-size: clamp(0.9rem, 2.6vw, 1.3rem);
  font-weight: 800;
  line-height: 1.2;
  overflow-wrap: break-word;
  hyphens: auto;
}

.scor-podium__number {
  color: var(--color-text-muted);
  font-size: 0.95rem;
  font-weight: 700;
}

.scor-podium__block {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  padding-top: 0.6rem;
  border-radius: 0.9rem 0.9rem 0 0;
  box-shadow: inset 0 1.5px 0 rgba(255, 255, 255, 0.6);
}

.scor-podium__place {
  font-size: 2.6rem;
  font-weight: 900;
  line-height: 1;
}

.scor-podium__label {
  font-size: 0.85rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.is-champion .scor-podium__block {
  height: 9rem;
  background: linear-gradient(180deg, #f7d461 0%, #d4a017 100%);
  color: #4a3600;
}

.is-champion .scor-podium__team .bi {
  font-size: 2.6rem;
  color: #c08b00;
}

.is-champion .scor-podium__team strong {
  font-size: clamp(1rem, 3vw, 1.55rem);
}

.is-silver .scor-podium__block {
  height: 6.5rem;
  background: linear-gradient(180deg, #e8edf3 0%, #9aa8ba 100%);
  color: #273445;
}

.is-silver .scor-podium__team .bi {
  color: #7b8899;
}

.is-bronze .scor-podium__block {
  height: 5rem;
  background: linear-gradient(180deg, #f1b47c 0%, #b5602a 100%);
  color: #fff;
}

.is-bronze .scor-podium__team .bi {
  color: #b5602a;
}

.scor-public__label {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.9rem;
  font-weight: 700;
}

.scor-public__label--award {
  margin-top: 0.85rem;
}

.scor-public__categories {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 1rem;
  width: 100%;
}

.scor-public__category--wide {
  grid-column: 1 / -1;
}

.scor-public__category {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  padding: 1.1rem 1.2rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
}

.scor-public__category h3 {
  margin: 0 0 0.3rem;
  font-size: 1.3rem;
  font-weight: 800;
}

.scor-public__category ul {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.scor-public__category li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 1.1rem;
}

.scor-public__category li .bi {
  color: #b45309;
}

.scor-public__winner {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0;
  padding: 0.65rem 0.8rem;
  border-radius: 0.8rem;
  background: color-mix(in srgb, #146c36 10%, transparent);
  font-size: 1.25rem;
  line-height: 1.25;
}

.scor-public__winner span {
  font-weight: 800;
}

.scor-public__winner .bi {
  flex: none;
  font-size: 1.55rem;
  color: #146c36;
}

.scor-public__places {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.6rem;
  width: 100%;
  margin: 0;
  padding: 0;
  list-style: none;
}

.scor-public__places li {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
  margin: 0;
  padding: 0.65rem 0.8rem;
  border-radius: 0.8rem;
  background: color-mix(in srgb, #146c36 10%, transparent);
  font-size: 1.15rem;
  font-weight: 800;
  line-height: 1.25;
}

.scor-public__places--bar {
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
}

.scor-public__place {
  flex: none;
  display: grid;
  place-items: center;
  width: 2rem;
  height: 2rem;
  border-radius: 999px;
  background: #146c36;
  color: #fff;
  font-size: 1.05rem;
  font-weight: 800;
}

@media (max-width: 640px) {
  .scor-public__places {
    grid-template-columns: 1fr;
  }
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
