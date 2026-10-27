<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import axios from 'axios'
import PageTabs from '@hands-on/glass/page-tabs'
import ScorRefereeSheet from '@/components/scor/ScorRefereeSheet.vue'
import ScorJurySheet from '@/components/scor/ScorJurySheet.vue'
import HotGearLoader from '@/components/scor/HotGearLoader.vue'

const route = useRoute()
const router = useRouter()

const events = ref<any[]>([])
const overview = ref<any>(null)
const sheet = ref<any>(null)
const error = ref('')
const loading = ref(false)
const tab = ref<'schiri' | 'jury'>('schiri')
const closedRounds = ref<string[]>([])

const eventId = computed(() => route.params.eventId ? Number(route.params.eventId) : null)
const matchId = computed(() => route.params.matchId ? Number(route.params.matchId) : null)
const teamId = computed(() => route.params.teamId ? Number(route.params.teamId) : null)
const juryRoute = computed(() => route.path.includes('/jury/'))

const rounds = computed(() => {
  const groups: {label: string, matches: any[]}[] = []
  for (const match of overview.value?.matches || []) {
    let group = groups.find((entry) => entry.label === match.round_label)
    if (!group) {
      group = {label: match.round_label, matches: []}
      groups.push(group)
    }
    group.matches.push(match)
  }
  return groups
})

const viewTabs = computed(() => {
  const tabs = []
  if (overview.value?.roles?.referee) tabs.push({id: 'schiri', label: 'Schiri', icon: 'bi-clipboard-check'})
  if (overview.value?.roles?.jury) tabs.push({id: 'jury', label: 'Jury', icon: 'bi-people'})
  return tabs
})

function isRoundOpen(label: string) {
  return !closedRounds.value.includes(label)
}

function toggleRound(label: string) {
  closedRounds.value = isRoundOpen(label)
      ? [...closedRounds.value, label]
      : closedRounds.value.filter((entry) => entry !== label)
}

function statusLabel(status?: string) {
  if (status === 'done') return 'fertig'
  if (status === 'draft') return 'begonnen'
  return 'offen'
}

async function load() {
  error.value = ''
  loading.value = true
  try {
    if (!eventId.value) {
      const {data} = await axios.get('/scor/events')
      events.value = data.events || []
      overview.value = null
      return
    }
    if (matchId.value && teamId.value) {
      const {data} = await axios.get(`/scor/events/${eventId.value}/matches/${matchId.value}/teams/${teamId.value}`)
      sheet.value = data
      return
    }
    if (juryRoute.value && teamId.value) {
      const {data} = await axios.get(`/scor/events/${eventId.value}/teams/${teamId.value}/jury`)
      sheet.value = data
      return
    }
    const {data} = await axios.get(`/scor/events/${eventId.value}`)
    overview.value = data
    if (route.query.sicht === 'jury' && data.roles?.jury) tab.value = 'jury'
    else if (data.roles?.referee) tab.value = 'schiri'
    else if (data.roles?.jury) tab.value = 'jury'
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Laden fehlgeschlagen.'
  } finally {
    loading.value = false
  }
}

watch(() => route.fullPath, () => {
  void load()
}, {immediate: true})

function selectSicht(next: string | number) {
  const sichtName = next === 'jury' ? 'jury' : 'schiri'
  tab.value = sichtName
  if (!eventId.value || matchId.value || juryRoute.value) return
  const sicht = sichtName === 'jury' ? 'jury' : undefined
  if ((route.query.sicht || undefined) === sicht) return
  const query = {...route.query}
  if (sicht) query.sicht = sicht
  else delete query.sicht
  void router.replace({query})
}

function openEvent(id: number) {
  void router.push(`/scor/${id}`)
}

function openMatch(matchIdValue: number, teamIdValue: number) {
  void router.push(`/scor/${eventId.value}/match/${matchIdValue}/team/${teamIdValue}`)
}

function openJury(teamIdValue: number) {
  void router.push(`/scor/${eventId.value}/jury/${teamIdValue}`)
}
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ sheet?.event?.name || overview?.event?.name || 'Auswertung' }}</h1>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">Future</p>
      </div>
      <button v-if="eventId" type="button" class="glass-btn-text" @click="router.push(sheet ? `/scor/${eventId}` : '/scor')">
        <i class="bi bi-arrow-left" aria-hidden="true"/>
        {{ sheet ? 'zurück zur Übersicht' : 'alle Veranstaltungen' }}
      </button>
    </div>

    <HotGearLoader v-if="loading"/>
    <p v-if="error" class="text-sm text-red-700">{{ error }}</p>

    <ul v-if="!eventId" class="glass-card liquid-surface-inner divide-y divide-[var(--color-border)]">
      <li v-if="!events.length" class="p-4 text-sm text-[var(--color-text-muted)]">Keine Future-Veranstaltung mit Auswertungsrecht.</li>
      <li v-for="event in events" :key="event.id">
        <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-[var(--color-bg-hover)]" @click="openEvent(event.id)">
          <span class="font-semibold">{{ event.name }}</span>
          <span class="text-sm text-[var(--color-text-muted)]">{{ event.date }}</span>
        </button>
      </li>
    </ul>

    <ScorRefereeSheet v-if="sheet && matchId" :sheet="sheet" @update="sheet = $event"/>
    <ScorJurySheet v-else-if="sheet && juryRoute" :sheet="sheet" @update="sheet = $event"/>

    <template v-else-if="overview">
      <p v-if="!overview.has_plan" class="text-sm text-[var(--color-text-muted)]">Für diese Veranstaltung gibt es noch keinen Matchplan.</p>
      <PageTabs
          v-if="viewTabs.length > 1"
          aria-label="Auswertung"
          :tabs="viewTabs"
          :model-value="tab"
          @update:model-value="selectSicht"
      />

      <div v-if="tab === 'schiri' && overview.roles.referee" class="scor-rounds">
        <p v-if="overview.has_plan && !overview.matches.length" class="text-sm text-[var(--color-text-muted)]">Im Matchplan stehen noch keine Future-Matches.</p>
        <section v-for="round in rounds" :key="round.label" class="scor-round">
          <button
              type="button"
              class="scor-round__toggle"
              :aria-expanded="isRoundOpen(round.label)"
              @click="toggleRound(round.label)"
          >
            <span class="scor-round__title">{{ round.label }}</span>
            <span class="scor-round__count">{{ round.matches.length }}</span>
            <i class="bi" :class="isRoundOpen(round.label) ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"/>
          </button>
          <article v-for="match in round.matches" v-show="isRoundOpen(round.label)" :key="match.id" class="scor-match glass-card liquid-surface-inner">
            <div class="scor-match__when">
              <span class="scor-match__clock">{{ match.start_label || '–' }}</span>
              <span class="scor-match__meta">Match {{ match.match_no }}</span>
            </div>
            <div class="scor-match__sides">
              <button
                  v-for="side in [match.team_1, match.team_2]"
                  :key="side.table"
                  type="button"
                  class="scor-side"
                  :class="side.id ? `scor-side--${side.referee_status || 'open'}` : 'scor-side--empty'"
                  :disabled="!side.id"
                  @click="side.id && openMatch(match.id, side.id)"
              >
                <span class="scor-side__place">{{ side.table_label }}</span>
                <span class="scor-side__name">{{ side.id ? `${side.number} ${side.name}` : 'kein Team' }}</span>
                <span v-if="side.id" class="scor-side__status">{{ statusLabel(side.referee_status) }}</span>
              </button>
            </div>
          </article>
        </section>
      </div>

      <ul v-else-if="overview.roles.jury" class="glass-card liquid-surface-inner divide-y divide-[var(--color-border)]">
        <li v-if="!overview.teams.length" class="p-4 text-sm text-[var(--color-text-muted)]">Keine Teams im Matchplan.</li>
        <li v-for="team in overview.teams" :key="team.id">
          <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-[var(--color-bg-hover)]" @click="openJury(team.id)">
            <span class="font-semibold">{{ team.number }} {{ team.name }}</span>
            <span class="text-xs text-[var(--color-text-muted)]">{{ statusLabel(team.jury_status) }}</span>
          </button>
        </li>
      </ul>
    </template>
  </div>
</template>

<style scoped>
.scor-rounds {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.scor-round {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.scor-round__toggle {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  width: 100%;
  min-height: 3.25rem;
  padding: 0.35rem 0.15rem;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
}

.scor-round__title {
  font-weight: 800;
  font-size: 1.15rem;
}

.scor-round__count {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--color-text-muted);
}

.scor-round__toggle .bi {
  margin-left: auto;
  font-size: 1.15rem;
}

.scor-match {
  display: grid;
  grid-template-columns: 5.75rem 1fr;
  gap: 0.75rem;
  align-items: stretch;
  padding: 0.85rem;
}

.scor-match__when {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 0.2rem;
}

.scor-match__clock {
  font-size: 1.85rem;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.scor-match__meta {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.scor-match__sides {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.5rem;
}

.scor-side {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 0.15rem;
  min-height: 4.75rem;
  padding: 0.75rem 0.9rem;
  border: 2px solid var(--color-border);
  border-radius: var(--radius-lg, 0.85rem);
  background: color-mix(in srgb, var(--color-bg) 70%, transparent);
  text-align: left;
}

.scor-side:not(:disabled):hover,
.scor-side:not(:disabled):focus-visible {
  border-color: var(--color-accent);
  background: var(--color-bg-hover);
}

.scor-side--draft {
  border-color: var(--color-accent);
}

.scor-side--done {
  border-color: #1f8a4c;
}

.scor-side--empty {
  opacity: 0.55;
}

.scor-side__place {
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.scor-side__name {
  font-size: 1.15rem;
  font-weight: 700;
  line-height: 1.2;
}

.scor-side__status {
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.scor-side--done .scor-side__status {
  color: #1f8a4c;
  font-weight: 700;
}

@media (max-width: 640px) {
  .scor-match {
    grid-template-columns: 1fr;
  }

  .scor-match__when {
    flex-direction: row;
    align-items: baseline;
    gap: 0.65rem;
  }
}

@media (min-width: 900px) {
  .scor-match__sides {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
