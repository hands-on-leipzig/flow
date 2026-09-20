<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import AccordionArrow from '@/components/icons/IconAccordionArrow.vue'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {flowFilename} from '@/utils/flowFilename'
import {hasChallenge} from '@/utils/eventPrograms'
import {getEventTitleLong} from '@/utils/eventTitle'

defineOptions({name: 'MatchPlanScorePrint'})

type MatchTeam = {name: string; hot_number: number; noshow?: boolean} | null
type MatchRow = {match_no: number; team_1: MatchTeam; team_2: MatchTeam}

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)
const eventDate = computed(() => event.value?.date)
const visible = computed(() => hasChallenge(event.value))
const busy = ref(false)

const showMatchPlanModal = ref(false)
const selectedRound = ref<number | null>(null)
const openRound = ref<number | null>(null)
const matches = ref<MatchRow[]>([])
const isLoadingMatches = ref(false)

const roundOptions = [
  {value: 1, label: 'Vorrunde 1'},
  {value: 2, label: 'Vorrunde 2'},
  {value: 3, label: 'Vorrunde 3'},
]

const eventTitleNormalized = computed(() => {
  const title = getEventTitleLong(event.value)
  return title.replace(/FIRST/, '<em>FIRST</em>')
})

function toggleRound(round: number) {
  if (openRound.value === round) {
    openRound.value = null
    matches.value = []
    selectedRound.value = null
  } else {
    openRound.value = round
    selectedRound.value = round
    void fetchMatches()
  }
}

async function fetchMatches() {
  if (!eventId.value || isLoadingMatches.value) return

  const planResponse = await axios.get(`/plans/event/${eventId.value}`)
  const planId = planResponse.data.id
  if (!planId || !selectedRound.value) return

  isLoadingMatches.value = true
  try {
    const {data} = await axios.get(`/export/match-teams/${planId}/${selectedRound.value}`)
    matches.value = data.matches || []
  } catch {
    matches.value = []
  } finally {
    isLoadingMatches.value = false
  }
}

watch(selectedRound, () => {
  if (showMatchPlanModal.value && selectedRound.value !== null) {
    void fetchMatches()
  }
})

function openMatchPlanModal() {
  showMatchPlanModal.value = true
  openRound.value = null
  selectedRound.value = null
  matches.value = []
}

function closeMatchPlanModal() {
  showMatchPlanModal.value = false
  openRound.value = null
  selectedRound.value = null
  matches.value = []
}

function formatTeam(team: MatchTeam): string {
  if (!team) return 'Freier Slot'
  return `${team.name} [${team.hot_number}]`
}

function isNoshow(team: MatchTeam): boolean {
  return team !== null && team.noshow === true
}

function isEmptySlot(team: MatchTeam): boolean {
  return team === null
}

async function downloadPdf() {
  if (!eventId.value || busy.value) return
  busy.value = true
  try {
    const response = await axios.post(
      `/print/${eventId.value}/match-plan-score`,
      {},
      {responseType: 'blob'},
    )
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename'] || flowFilename('Match-Plan', 'pdf', eventDate.value)
    link.click()
    window.URL.revokeObjectURL(url)
  } catch {
    showGlassToast('PDF erzeugen fehlgeschlagen', 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article v-if="visible" class="liquid-surface-inner role-sheets">
    <header class="role-sheets__head">
      <h2 class="role-sheets__title">Match-Plan SCORE</h2>
      <p class="role-sheets__sub">
        Vorrunden-Matches zum Übernehmen nach
        <a
          href="https://evaluation.hands-on-technology.org/"
          target="_blank"
          rel="noopener noreferrer"
          class="text-[var(--color-accent)] underline hover:opacity-80"
        >SCORE</a>.
      </p>
    </header>
    <p class="role-sheets__note">Reihenfolge in SCORE an FLOW anpassen.</p>
    <footer class="role-sheets__actions">
      <button type="button" class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm" @click="openMatchPlanModal">
        Online
      </button>
      <button
          type="button"
          class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
          :disabled="!eventId || busy"
          @click="downloadPdf"
      >
        <Spinner v-if="busy" size="sm"/>
        <span>{{ busy ? 'Erzeuge…' : 'PDF erzeugen' }}</span>
      </button>
    </footer>
  </article>

  <Teleport to="body">
    <div
      v-if="showMatchPlanModal"
      class="match-plan-modal"
      @click="closeMatchPlanModal"
    >
      <div
        class="match-plan-modal__dialog"
        role="dialog"
        aria-modal="true"
        @click.stop
      >
        <header class="match-plan-modal__head">
          <h3 class="match-plan-modal__title" v-html="eventTitleNormalized"></h3>
          <button
            type="button"
            class="match-plan-modal__close"
            aria-label="Schließen"
            @click="closeMatchPlanModal"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </header>
        <div class="match-plan-modal__body">
          <div class="space-y-2">
            <template v-for="option in roundOptions" :key="option.value">
              <div class="bg-white border rounded-lg shadow">
                <button
                  type="button"
                  class="w-full text-left px-4 py-2 bg-[var(--color-bg-muted)] font-semibold text-black uppercase flex justify-between items-center"
                  @click="toggleRound(option.value)"
                >
                  {{ option.label }}
                  <AccordionArrow :opened="openRound === option.value"/>
                </button>
                <transition name="fade">
                  <div v-if="openRound === option.value" class="p-4">
                    <div v-if="isLoadingMatches" class="flex items-center justify-center py-8">
                      <Spinner size="md"/>
                      <span class="ml-3 text-[var(--color-text-muted)]">Lade Matches…</span>
                    </div>
                    <div v-else-if="matches.length === 0" class="text-center py-8 text-[var(--color-text-subtle)]">
                      Keine Matches gefunden
                    </div>
                    <div v-else class="match-plan-modal__matches">
                      <template v-for="match in matches" :key="match.match_no">
                        <div
                          class="px-4 py-2 rounded text-white text-sm font-medium"
                          :class="[
                            isEmptySlot(match.team_1) ? 'bg-gray-300 text-[var(--color-text-muted)]' : 'bg-blue-600',
                            isNoshow(match.team_1) ? 'line-through' : ''
                          ]"
                        >
                          {{ formatTeam(match.team_1) }}
                        </div>
                        <div
                          class="px-4 py-2 rounded text-white text-sm font-medium"
                          :class="[
                            isEmptySlot(match.team_2) ? 'bg-gray-300 text-[var(--color-text-muted)]' : 'bg-blue-600',
                            isNoshow(match.team_2) ? 'line-through' : ''
                          ]"
                        >
                          {{ formatTeam(match.team_2) }}
                        </div>
                      </template>
                    </div>
                  </div>
                </transition>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.role-sheets {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  min-width: 0;
  padding: 1rem 1.05rem 1.05rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 90%, var(--liquid-tile-bg-inner));
  box-shadow:
    0 8px 20px rgba(15, 23, 42, 0.045),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.role-sheets__title {
  margin: 0;
  font-size: 0.98rem;
  font-weight: 650;
  letter-spacing: -0.015em;
  line-height: 1.3;
}

.role-sheets__sub {
  margin: 0.28rem 0 0;
  font-size: 0.8rem;
  line-height: 1.4;
  color: var(--color-text-muted);
}

.role-sheets__note {
  margin: 0;
  font-size: 0.78rem;
  line-height: 1.35;
  color: var(--color-text-muted);
}

.role-sheets__actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 0.45rem;
}

.match-plan-modal {
  position: fixed;
  inset: 0;
  z-index: 200;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgb(0 0 0 / 0.5);
}

.match-plan-modal__dialog {
  display: flex;
  flex-direction: column;
  width: max-content;
  max-width: calc(100vw - 2rem);
  max-height: calc(100vh - 2rem);
  overflow: hidden;
  background: #fff;
  border-radius: 0.5rem;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.22);
}

.match-plan-modal__head {
  flex-shrink: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--color-border);
}

.match-plan-modal__title {
  margin: 0;
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-text);
}

.match-plan-modal__close {
  flex-shrink: 0;
  color: var(--color-text-subtle);
}

.match-plan-modal__close:hover {
  color: var(--color-text-muted);
}

.match-plan-modal__body {
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
  padding: 1rem 1.5rem;
}

.match-plan-modal__matches {
  display: grid;
  grid-template-columns: minmax(0, max-content) minmax(0, max-content);
  gap: 0.75rem;
}

.match-plan-modal__matches > * {
  min-width: 0;
  overflow-wrap: anywhere;
}

.fade-enter-active, .fade-leave-active {
  transition: all 0.2s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}
</style>
