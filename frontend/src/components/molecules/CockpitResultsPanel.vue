<script setup lang="ts">
import {onMounted, ref} from 'vue'
import type {AxiosInstance} from 'axios'
import ProgramOfficialName from '@/components/atoms/ProgramOfficialName.vue'
import RoundToggle from '@/components/atoms/RoundToggle.vue'
import RobotGameRoundsPanel from '@/components/molecules/RobotGameRoundsPanel.vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'

defineOptions({name: 'CockpitResultsPanel'})

type ProgramHead = {
  program_label: string
  logo_stem: string | null
}
type GameRound = { round: number, label: string, visible: boolean }

const props = defineProps<{
  slug: string
  eventId: number | null
  http: AxiosInstance
}>()

const loading = ref(false)
const error = ref('')
const challenge = ref<ProgramHead | null>(null)
const future = ref<(ProgramHead & { rounds: GameRound[] }) | null>(null)
const savingRound = ref<number | null>(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const {data} = await props.http.get(`/cockpit/${props.slug}/results`)
    challenge.value = data?.challenge ?? null
    future.value = data?.future ? {...data.future, rounds: data.future.rounds ?? []} : null
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Ergebnisanzeige konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

async function toggleGameRound(round: GameRound) {
  if (!future.value || savingRound.value !== null) return
  const previous = round.visible
  round.visible = !previous
  savingRound.value = round.round
  try {
    const {data} = await props.http.put(`/cockpit/${props.slug}/game-rounds`, {
      rounds: future.value.rounds.filter((r) => r.visible).map((r) => r.round),
    })
    future.value.rounds = data?.rounds ?? future.value.rounds
  } catch (e: any) {
    round.visible = previous
    error.value = e?.response?.data?.error || 'Speichern fehlgeschlagen.'
  } finally {
    savingRound.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="cp-results">
    <p v-if="error" class="glass-alert-error !mb-0">{{ error }}</p>
    <p v-if="loading" class="cp-results__hint">Lade…</p>
    <p v-else-if="!challenge && !future" class="cp-results__hint">
      Für diese Veranstaltung gibt es keine Spielergebnisse.
    </p>

    <section v-if="challenge" class="cp-results__program glass-card liquid-surface-inner">
      <header class="cp-results__head">
        <img
            v-if="challenge.logo_stem"
            class="cp-results__logo"
            :src="programLogoSrc({logo_stem: challenge.logo_stem})"
            :alt="programLogoAlt({logo_stem: challenge.logo_stem})"
        >
        <div class="cp-results__heading">
          <h2 class="cp-results__title">
            <ProgramOfficialName :html="challenge.program_label"/>
          </h2>
          <p class="cp-results__sub">Robot-Game</p>
        </div>
      </header>
      <RobotGameRoundsPanel
          embedded
          :event-id="eventId"
          :rounds-api-path="`/cockpit/${slug}/rounds`"
          :http="http"
      />
    </section>

    <section v-if="future" class="cp-results__program glass-card liquid-surface-inner">
      <header class="cp-results__head">
        <img
            v-if="future.logo_stem"
            class="cp-results__logo"
            :src="programLogoSrc({logo_stem: future.logo_stem})"
            :alt="programLogoAlt({logo_stem: future.logo_stem})"
        >
        <div class="cp-results__heading">
          <h2 class="cp-results__title">
            <ProgramOfficialName :html="future.program_label"/>
          </h2>
          <p class="cp-results__sub">Spiel</p>
        </div>
      </header>
      <p v-if="!future.rounds.length" class="cp-results__hint">Noch kein Matchplan mit Wertungsrunden.</p>
      <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <RoundToggle
            v-for="round in future.rounds"
            :key="round.round"
            :on="round.visible"
            :busy="savingRound === round.round"
            :disabled="savingRound !== null"
            @toggle="toggleGameRound(round)"
        >
          {{ round.label }}
        </RoundToggle>
      </div>
    </section>
  </div>
</template>

<style scoped>
.cp-results {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.cp-results__hint {
  margin: 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
}

.cp-results__program {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  padding: 1rem;
}

.cp-results__head {
  display: flex;
  align-items: center;
  gap: 0.7rem;
}

.cp-results__logo {
  height: 2.5rem;
  width: auto;
  flex-shrink: 0;
}

.cp-results__heading {
  min-width: 0;
}

.cp-results__title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 750;
  line-height: 1.25;
  color: var(--color-text);
}

.cp-results__sub {
  margin: 0.1rem 0 0;
  font-size: 0.85rem;
  color: var(--color-text-muted);
}
</style>
