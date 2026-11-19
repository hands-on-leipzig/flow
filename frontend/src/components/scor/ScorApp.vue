<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import axios from 'axios'
import keycloak from '@/keycloak.js'
import ScorRefereeSheet from '@/components/scor/ScorRefereeSheet.vue'
import ScorJurySheet from '@/components/scor/ScorJurySheet.vue'
import ScorResults from '@/components/scor/ScorResults.vue'
import HotGearLoader from '@/components/scor/HotGearLoader.vue'
import {useWiseRoles} from '@/composables/useWiseRoles'

const route = useRoute()
const router = useRouter()
const wiseRoles = useWiseRoles()
const needsLogin = ref(!keycloak.authenticated && !localStorage.getItem('scor_token'))
const localName = ref('')
const localPassword = ref('')
const localError = ref('')
const localBusy = ref(false)

async function localLogin() {
  localError.value = ''
  localBusy.value = true
  try {
    const {data} = await axios.post('/scor/login', {
      username: localName.value,
      password: localPassword.value,
    }, {skipAppRedirect: true})
    localStorage.removeItem('kc_token')
    localStorage.setItem('scor_token', data.token)
    needsLogin.value = false
    await load()
  } catch (e: any) {
    localError.value = e?.response?.data?.error || 'Anmeldung fehlgeschlagen.'
  } finally {
    localBusy.value = false
  }
}

const events = ref<any[]>([])
const overview = ref<any>(null)
const results = ref<any>(null)
const sheet = ref<any>(null)
const error = ref('')
const loading = ref(false)
const tab = ref<'schiri' | 'jury' | 'ergebnisse'>('schiri')
const closedRounds = ref<string[]>([])
const stagePicks = ref<number[]>([])
const stageOpen = ref(false)
const stageDragY = ref(0)
let stageDragStart = 0

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

function sichtKey(id: number) {
  return `wise-sicht-${id}`
}

function readSicht(id: number) {
  try {
    return sessionStorage.getItem(sichtKey(id))
  } catch {
    return null
  }
}

function writeSicht(id: number, sicht: string) {
  try {
    sessionStorage.setItem(sichtKey(id), sicht)
  } catch {
    /* private mode */
  }
}

function resolveSicht(asked: string | null, roles?: {referee?: boolean, jury?: boolean}) {
  if (asked === 'ergebnisse') return 'ergebnisse' as const
  if (asked === 'jury' && roles?.jury) return 'jury' as const
  if (asked === 'schiri' && roles?.referee) return 'schiri' as const
  if (roles?.referee) return 'schiri' as const
  if (roles?.jury) return 'jury' as const
  return 'ergebnisse' as const
}

function matchCount(count: number) {
  return count === 1 ? '1 Match' : `${count} Matches`
}

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

function peopleLabel(people?: string[]) {
  return people?.length ? people.join(', ') : 'niemand zugeteilt'
}

const jurySections = computed(() => {
  const catalog = overview.value?.groups?.jury || []
  const teams = overview.value?.teams || []
  const grouped = catalog.length > 0 || teams.some((team: any) => team.jury_lane)
  if (!grouped) return null

  const sections = new Map<number, {index: number, label: string, people: string[], teams: any[]}>()
  for (const group of catalog) {
    sections.set(group.index, {index: group.index, label: group.label, people: group.people || [], teams: []})
  }
  const loose: any[] = []
  for (const team of teams) {
    const lane = Number(team.jury_lane || 0)
    if (lane > 0) {
      if (!sections.has(lane)) {
        sections.set(lane, {index: lane, label: `Jury-Gruppe ${lane}`, people: [], teams: []})
      }
      sections.get(lane)!.teams.push(team)
    } else {
      loose.push(team)
    }
  }
  const list = [...sections.values()].sort((a, b) => a.index - b.index)
  if (loose.length) list.push({index: 0, label: 'ohne Gruppe', people: [], teams: loose})
  return list
})

async function load() {
  if (needsLogin.value) return
  error.value = ''
  loading.value = true
  try {
    if (!eventId.value) {
      const {data} = await axios.get('/scor/events')
      events.value = data.events || []
      overview.value = null
      results.value = null
      wiseRoles.setRoles(null)
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
    const standings = await axios.get(`/scor/events/${eventId.value}/results`)
    results.value = standings.data
    stagePicks.value = [...(data.stage?.picks || [])]
    sheet.value = null
    const asked = typeof route.query.sicht === 'string' ? route.query.sicht : readSicht(eventId.value)
    tab.value = resolveSicht(asked, data.roles)
    writeSicht(eventId.value, tab.value)
    wiseRoles.setRoles({
      referee: !!data.roles?.referee,
      jury: !!data.roles?.jury,
    })
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Laden fehlgeschlagen.'
  } finally {
    loading.value = false
  }
}

watch(() => route.fullPath, () => {
  void load()
}, {immediate: true})

function openEvent(id: number) {
  void router.push(`/scor/${id}`)
}

function confirmOpen(done: boolean) {
  if (!done) return true
  return window.confirm('Dieser Bogen ist abgeschlossen. Trotzdem öffnen?')
}

function openMatch(matchIdValue: number, teamIdValue: number, done = false) {
  if (!confirmOpen(done)) return
  void router.push(`/scor/${eventId.value}/match/${matchIdValue}/team/${teamIdValue}`)
}

function openJury(teamIdValue: number, done = false) {
  if (!confirmOpen(done)) return
  void router.push(`/scor/${eventId.value}/jury/${teamIdValue}`)
}

function isNoted(teamId: number) {
  return (overview.value?.stage?.noteworthy || []).includes(teamId)
}

function isPicked(teamId: number) {
  return (overview.value?.stage?.picks || []).includes(teamId)
}

async function toggleNoted(teamId: number) {
  const {data} = await axios.put(`/scor/events/${eventId.value}/teams/${teamId}/noteworthy`, {on: !isNoted(teamId)})
  if (overview.value) overview.value.stage = data
}

function notedTeams() {
  const ids = new Set(overview.value?.stage?.noteworthy || [])
  return (overview.value?.teams || []).filter((team: any) => ids.has(team.id))
}

function togglePick(teamId: number) {
  const slots = overview.value?.stage?.slots || 0
  if (stagePicks.value.includes(teamId)) {
    stagePicks.value = stagePicks.value.filter((id) => id !== teamId)
    return
  }
  if (stagePicks.value.length >= slots) return
  stagePicks.value = [...stagePicks.value, teamId]
}

function openStage() {
  stagePicks.value = [...(overview.value?.stage?.picks || [])]
  stageDragY.value = 0
  error.value = ''
  stageOpen.value = true
}

function closeStage() {
  stageOpen.value = false
  stageDragY.value = 0
}

function onStageTouchStart(event: TouchEvent) {
  stageDragStart = event.touches[0]?.clientY ?? 0
}

function onStageTouchMove(event: TouchEvent) {
  const y = event.touches[0]?.clientY ?? stageDragStart
  stageDragY.value = Math.max(0, y - stageDragStart)
}

function onStageTouchEnd() {
  if (stageDragY.value > 90) closeStage()
  else stageDragY.value = 0
}

async function saveStage() {
  error.value = ''
  try {
    const {data} = await axios.put(`/scor/events/${eventId.value}/stage`, {teams: stagePicks.value})
    if (overview.value) overview.value.stage = data
    stagePicks.value = [...(data.picks || [])]
    closeStage()
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Auswahl speichern fehlgeschlagen.'
  }
}
</script>

<template>
  <form v-if="needsLogin" class="mx-auto max-w-md space-y-4" @submit.prevent="localLogin">
    <h1 class="text-2xl font-bold tracking-tight">WISE</h1>
    <p class="text-lg text-[var(--color-text-muted)]">Mit dem Namen und Passwort von der Veranstaltung anmelden.</p>
    <label class="block text-lg">
      Name
      <input v-model="localName" class="glass-input mt-1 w-full" autocomplete="username" placeholder="Schiri 1"/>
    </label>
    <label class="block text-lg">
      Passwort
      <input v-model="localPassword" type="password" class="glass-input mt-1 w-full" autocomplete="current-password"/>
    </label>
    <p v-if="localError" class="text-lg text-red-700">{{ localError }}</p>
    <button type="submit" class="glass-btn-accent" :disabled="localBusy">Anmelden</button>
  </form>
    <div v-else class="mx-auto max-w-5xl space-y-6">
    <p v-if="overview?.lifecycle?.playground" class="rounded-lg border border-amber-400 bg-amber-50 px-4 py-3 text-lg">
      Spielwiese. Bis zum Tag der Veranstaltung zählen die Bögen nur zum Üben und werden in der Nacht davor gelöscht. Nichts davon ist öffentlich.
    </p>
    <div>
      <button
          v-if="sheet && eventId"
          type="button"
          class="wise-back wise-back--desk"
          @click="router.push(juryRoute ? `/scor/${eventId}?sicht=jury` : `/scor/${eventId}`)"
      >
        <i class="bi bi-chevron-left" aria-hidden="true"/>
        {{ juryRoute ? 'Jury' : 'Schiri' }}
      </button>
      <h1 class="text-2xl font-bold tracking-tight">{{ sheet?.event?.name || overview?.event?.name || 'Auswertung' }}</h1>
      <p class="mt-1 text-lg text-[var(--color-text-muted)]">Future</p>
    </div>

    <HotGearLoader v-if="loading"/>
    <p v-if="error" class="text-lg text-red-700">{{ error }}</p>

    <ul v-if="!eventId" class="glass-card liquid-surface-inner divide-y divide-[var(--color-border)]">
      <li v-if="!events.length" class="p-4 text-lg text-[var(--color-text-muted)]">Keine Future-Veranstaltung mit Auswertungsrecht.</li>
      <li v-for="event in events" :key="event.id">
        <button type="button" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-[var(--color-bg-hover)]" @click="openEvent(event.id)">
          <span class="font-semibold">{{ event.name }}</span>
          <span class="text-lg text-[var(--color-text-muted)]">{{ event.date }}</span>
        </button>
      </li>
    </ul>

    <ScorRefereeSheet v-if="sheet && matchId" :sheet="sheet" @update="sheet = $event"/>
    <ScorJurySheet v-else-if="sheet && juryRoute" :sheet="sheet" @update="sheet = $event"/>

    <template v-else-if="overview">
      <p v-if="!overview.has_plan" class="text-lg text-[var(--color-text-muted)]">Für diese Veranstaltung gibt es noch keinen Matchplan.</p>

      <ScorResults v-if="tab === 'ergebnisse'" :results="results" @saved="results = $event"/>

      <div v-else-if="tab === 'schiri' && overview.roles.referee" class="scor-rounds">
        <section v-if="overview.groups?.referee?.length" class="scor-assign" aria-label="Schiri-Zuteilung">
          <h2 class="scor-assign__title">Zuteilung</h2>
          <ul class="scor-assign__list">
            <li v-for="group in overview.groups.referee" :key="group.index">
              <span class="scor-assign__group">{{ group.label }}</span>
              <span class="scor-assign__people">{{ peopleLabel(group.people) }}</span>
            </li>
          </ul>
        </section>
        <p v-if="overview.has_plan && !overview.matches.length" class="text-lg text-[var(--color-text-muted)]">Im Matchplan stehen noch keine Future-Matches.</p>
        <section v-for="round in rounds" :key="round.label" class="scor-round">
          <button
              type="button"
              class="scor-round__toggle"
              :aria-expanded="isRoundOpen(round.label)"
              @click="toggleRound(round.label)"
          >
            <span class="scor-round__title">{{ round.label }}</span>
            <span class="scor-round__meta">
              <span class="scor-round__count">{{ matchCount(round.matches.length) }}</span>
              <i class="bi" :class="isRoundOpen(round.label) ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"/>
            </span>
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
                  @click="side.id && openMatch(match.id, side.id, side.referee_status === 'done')"
              >
                <span class="scor-side__place">{{ side.table_label }}</span>
                <span v-if="side.staff?.length" class="scor-side__staff">{{ side.staff.join(', ') }}</span>
                <span class="scor-side__name">{{ side.id ? `${side.number} ${side.name}` : 'kein Team' }}</span>
                <span v-if="side.id" class="scor-side__status">{{ statusLabel(side.referee_status) }}</span>
              </button>
            </div>
          </article>
        </section>
      </div>

      <button
          v-if="tab === 'jury' && overview.roles.admin && overview.stage"
          type="button"
          class="glass-btn-secondary wise-stage-open"
          @click="openStage"
      >
        <i class="bi bi-easel" aria-hidden="true"/>
        Öffentliche Präsentation verwalten
      </button>

      <div v-if="tab === 'jury' && overview.roles.jury && jurySections" class="scor-rounds">
        <section v-for="group in jurySections" :key="group.index" class="scor-round">
          <header class="scor-assign">
            <h2 class="scor-assign__title">{{ group.label }}</h2>
            <p class="scor-assign__people">{{ peopleLabel(group.people) }}</p>
          </header>
          <ul class="glass-card liquid-surface-inner divide-y divide-[var(--color-border)]">
            <li v-if="!group.teams.length" class="p-4 text-lg text-[var(--color-text-muted)]">Keine Teams in dieser Gruppe.</li>
            <li v-for="team in group.teams" :key="team.id">
              <div class="wise-team" :class="`wise-team--${team.jury_status || 'open'}`">
                <button type="button" class="wise-team__open" @click="openJury(team.id, team.jury_status === 'done')">
                  {{ team.number }} {{ team.name }}
                </button>
                <span class="wise-team__side">
                  <i v-if="isPicked(team.id)" class="bi bi-easel wise-stage-mark" title="Öffentliche Präsentation" aria-label="Öffentliche Präsentation"/>
                  <button
                      v-if="overview.roles.jury"
                      type="button"
                      class="wise-star"
                      :class="{'is-on': isNoted(team.id)}"
                      :aria-pressed="isNoted(team.id)"
                      :aria-label="isNoted(team.id) ? 'Stern entfernen' : 'Stern für die Präsentation'"
                      @click.stop="toggleNoted(team.id)"
                  >
                    <i class="bi" :class="isNoted(team.id) ? 'bi-star-fill' : 'bi-star'" aria-hidden="true"/>
                  </button>
                  <span class="wise-team__status">{{ statusLabel(team.jury_status) }}</span>
                </span>
              </div>
            </li>
          </ul>
        </section>
      </div>

      <ul v-else-if="tab === 'jury' && overview.roles.jury" class="glass-card liquid-surface-inner divide-y divide-[var(--color-border)]">
        <li v-if="!overview.teams.length" class="p-4 text-lg text-[var(--color-text-muted)]">Keine Teams im Matchplan.</li>
        <li v-for="team in overview.teams" :key="team.id">
          <div class="wise-team" :class="`wise-team--${team.jury_status || 'open'}`">
            <button type="button" class="wise-team__open" @click="openJury(team.id, team.jury_status === 'done')">
              {{ team.number }} {{ team.name }}
            </button>
            <span class="wise-team__side">
              <button
                  v-if="overview.roles.jury"
                  type="button"
                  class="wise-star"
                  :class="{'is-on': isNoted(team.id)}"
                  :aria-pressed="isNoted(team.id)"
                  :aria-label="isNoted(team.id) ? 'Stern entfernen' : 'Stern für die Präsentation'"
                  @click.stop="toggleNoted(team.id)"
              >
                <i class="bi" :class="isNoted(team.id) ? 'bi-star-fill' : 'bi-star'" aria-hidden="true"/>
              </button>
              <span class="wise-team__status">{{ statusLabel(team.jury_status) }}</span>
            </span>
          </div>
        </li>
      </ul>
    </template>

    <Teleport to="body">
      <div v-if="stageOpen" class="wise-sheet">
        <button type="button" class="wise-sheet__backdrop" aria-label="Schließen" @click="closeStage"/>
        <div
            class="wise-sheet__panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="wise-stage-title"
            :style="stageDragY ? {transform: `translateY(${stageDragY}px)`} : undefined"
            @touchstart.passive="onStageTouchStart"
            @touchmove="onStageTouchMove"
            @touchend="onStageTouchEnd"
        >
          <button type="button" class="wise-sheet__handle" aria-label="Schließen" @click="closeStage">
            <span class="wise-sheet__handle-bar"/>
          </button>
          <h2 id="wise-stage-title" class="wise-sheet__title">Öffentliche Präsentation</h2>
          <p v-if="!overview?.stage?.slots" class="wise-sheet__hint">Im Zeitplan steht keine Anzahl für die Präsentation.</p>
          <template v-else>
            <p class="wise-sheet__hint">{{ stagePicks.length }} von {{ overview.stage.slots }} Plätzen. Nur Teams mit Stern.</p>
            <p v-if="!notedTeams().length" class="wise-sheet__hint">Noch kein Team mit Stern. Die Jury setzt den Stern in der Liste.</p>
            <ul v-else class="wise-sheet__list">
              <li v-for="team in notedTeams()" :key="team.id">
                <button
                    type="button"
                    class="wise-sheet__team"
                    :class="{'is-on': stagePicks.includes(team.id)}"
                    :aria-pressed="stagePicks.includes(team.id)"
                    @click="togglePick(team.id)"
                >
                  <span>{{ team.number }} {{ team.name }}</span>
                  <i class="bi" :class="stagePicks.includes(team.id) ? 'bi-check-circle-fill' : 'bi-circle'" aria-hidden="true"/>
                </button>
              </li>
            </ul>
            <p v-if="error" class="wise-sheet__error" role="alert">{{ error }}</p>
            <button type="button" class="glass-btn-accent wise-sheet__save" @click="saveStage">Auswahl speichern</button>
          </template>
        </div>
      </div>
    </Teleport>
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
  margin-right: auto;
  font-weight: 800;
  font-size: 1.15rem;
}

.scor-round__meta {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  color: var(--color-text-muted);
}

.scor-round__count {
  font-size: 1.125rem;
  font-weight: 700;
}

.scor-round__toggle .bi {
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
  font-size: 1.125rem;
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
  font-size: 1.05rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.scor-side__staff {
  font-size: 1.05rem;
  font-weight: 700;
  line-height: 1.25;
}

.scor-assign__title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
}

.scor-assign__list {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin: 0.35rem 0 0;
  padding: 0;
  list-style: none;
}

.scor-assign__group,
.scor-assign__people {
  display: block;
}

.scor-assign__group {
  font-weight: 800;
}

.scor-assign__people {
  color: var(--color-text-muted);
  font-size: 1.125rem;
}

.wise-stage-open {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  min-height: 3.25rem;
}

.wise-team {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding-right: 0.65rem;
  background: #f3f5f8;
}

.wise-team--draft {
  background: #fff3dc;
}

.wise-team--done {
  background: #e5f6ec;
}

.wise-team--draft .wise-team__status,
.wise-team--done .wise-team__status {
  font-weight: 800;
}

.wise-team--draft .wise-team__status {
  color: #9a6700;
}

.wise-team--done .wise-team__status {
  color: #146c36;
}

.wise-team__open {
  flex: 1;
  min-width: 0;
  min-height: 3.4rem;
  padding: 0.75rem 0.35rem 0.75rem 1rem;
  border: 0;
  background: transparent;
  color: inherit;
  font-size: 1.05rem;
  font-weight: 700;
  text-align: left;
}

.wise-team__open:hover,
.wise-team__open:focus-visible {
  background: var(--color-bg-hover);
}

.wise-team__side {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex: none;
}

.wise-team__status {
  font-size: 1.05rem;
  color: var(--color-text-muted);
}

.wise-star {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1.35rem;
}

.wise-star.is-on {
  color: #e85d04;
}

.wise-stage-mark {
  color: var(--color-accent, #e85d04);
  font-size: 1.15rem;
}

.wise-sheet {
  position: fixed;
  inset: 0;
  z-index: 1200;
}

.wise-sheet__backdrop {
  position: absolute;
  inset: 0;
  border: 0;
  background: rgba(15, 23, 42, 0.36);
}

.wise-sheet__panel {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  max-height: min(88dvh, 40rem);
  overflow: auto;
  padding: 0.35rem 1.1rem calc(1.1rem + env(safe-area-inset-bottom));
  border-radius: 1.4rem 1.4rem 0 0;
  background: #f7f8fb;
  box-shadow: 0 -18px 40px rgba(15, 23, 42, 0.18);
}

.wise-sheet__handle {
  display: flex;
  justify-content: center;
  width: 100%;
  padding: 0.45rem 0 0.7rem;
  border: 0;
  background: transparent;
}

.wise-sheet__handle-bar {
  width: 2.6rem;
  height: 0.28rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-text-muted) 45%, transparent);
}

.wise-sheet__title {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  font-weight: 800;
}

.wise-sheet__hint {
  margin: 0 0 0.85rem;
  color: var(--color-text-muted);
  font-size: 1.05rem;
}

.wise-sheet__list {
  margin: 0 0 1rem;
  padding: 0;
  list-style: none;
}

.wise-sheet__team {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  min-height: 3.4rem;
  padding: 0.65rem 0.15rem;
  border: 0;
  border-bottom: 1px solid var(--color-border);
  background: transparent;
  color: inherit;
  font-size: 1.1rem;
  font-weight: 700;
  text-align: left;
}

.wise-sheet__team .bi {
  font-size: 1.35rem;
  color: var(--color-text-muted);
}

.wise-sheet__team.is-on .bi {
  color: #1f8a4c;
}

.wise-sheet__error {
  margin: 0 0 0.75rem;
  color: #b42318;
  font-weight: 700;
}

.wise-sheet__save {
  width: 100%;
  min-height: 3.25rem;
  justify-content: center;
}

html[data-theme='dark'] .wise-sheet__panel {
  background: #2a2725;
}

html[data-theme='dark'] .wise-team {
  background: #3a3532;
}

html[data-theme='dark'] .wise-team--draft {
  background: #4a3b24;
}

html[data-theme='dark'] .wise-team--done {
  background: #1d3a2a;
}

.scor-side__name {
  font-size: 1.15rem;
  font-weight: 700;
  line-height: 1.2;
}

.scor-side__status {
  font-size: 1.125rem;
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

@media (min-width: 760px) {
  .scor-match__sides {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
