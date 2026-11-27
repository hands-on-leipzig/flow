<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios, {type AxiosInstance} from 'axios'
import {useEventStore} from '@/stores/event'
import RoundToggle from '@/components/atoms/RoundToggle.vue'

type RobotGamePublicRounds = {
  vr1: boolean;
  vr2: boolean;
  vr3: boolean;
  vf: boolean;
  hf: boolean;
};

type RoundKey = keyof RobotGamePublicRounds

const props = defineProps<{
  /** Override event id (e.g. Cockpit public app). Falls back to selected event. */
  eventId?: number | null
  /** API path under /api, e.g. cockpit/my-slug/rounds */
  roundsApiPath?: string | null
  /** Custom axios client (e.g. with Cockpit session header). */
  http?: AxiosInstance
  /** Hide built-in heading when embedded in CockpitToolShell. */
  embedded?: boolean
}>()

const eventStore = useEventStore()
const resolvedEventId = computed(() => props.eventId ?? eventStore.selectedEvent?.id ?? null)
const client = computed(() => props.http ?? axios)
const rounds = ref<RobotGamePublicRounds | null>(null)
const loading = ref(false)
const saving = ref<RoundKey | null>(null)

const roundOptions: Array<{ key: RoundKey; label: string }> = [
  {key: 'vr1', label: 'VR1'},
  {key: 'vr2', label: 'VR2'},
  {key: 'vr3', label: 'VR3'},
  {key: 'vf', label: 'VF'},
  {key: 'hf', label: 'HF'},
]

function roundsUrl(): string | null {
  if (props.roundsApiPath) return props.roundsApiPath
  if (resolvedEventId.value) return `/contao/rounds/${resolvedEventId.value}`
  return null
}

function normalizeRounds(raw: Record<string, unknown>): RobotGamePublicRounds {
  const bool = (key: RoundKey) => !!raw[key]
  return {
    vr1: bool('vr1'),
    vr2: bool('vr2'),
    vr3: bool('vr3'),
    vf: bool('vf'),
    hf: bool('hf'),
  }
}

async function fetchRounds() {
  const url = roundsUrl()
  if (!url) return
  loading.value = true
  try {
    const response = await client.value.get(url)
    rounds.value = normalizeRounds(response.data)
  } catch (error) {
    console.error('Error fetching robot game rounds:', error)
  } finally {
    loading.value = false
  }
}

async function toggleRound(round: RoundKey) {
  const url = roundsUrl()
  if (!url || !rounds.value || saving.value) return
  const next = !rounds.value[round]
  const previous = rounds.value[round]
  rounds.value[round] = next
  saving.value = round
  try {
    await client.value.put(url, rounds.value)
  } catch (error) {
    rounds.value[round] = previous
    console.error('Error updating robot game rounds:', error)
  } finally {
    saving.value = null
  }
}

watch(() => [resolvedEventId.value, props.roundsApiPath] as const, fetchRounds, {immediate: true})
onMounted(fetchRounds)
</script>

<template>
  <section :class="embedded ? 'cp-rounds' : 'glass-surface-lg p-4 sm:p-6'">
    <div v-if="!embedded" class="mb-4">
      <h2 class="text-lg font-semibold text-[var(--color-text)]">Robot-Game Ergebnisse</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Wähle aus, welche Runden öffentlich sichtbar sein sollen.
      </p>
    </div>

    <div v-if="loading" class="py-8 text-center text-sm text-[var(--color-text-subtle)]">Laden…</div>

    <div v-else-if="rounds" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
      <RoundToggle
          v-for="round in roundOptions"
          :key="round.key"
          :on="rounds[round.key]"
          :busy="saving === round.key"
          :disabled="saving !== null"
          @toggle="toggleRound(round.key)"
      >
        {{ round.label }}
      </RoundToggle>
    </div>

    <p v-else class="text-sm text-[var(--color-text-muted)]">
      Runden aus HOT konnten nicht geladen werden.
    </p>
  </section>
</template>
