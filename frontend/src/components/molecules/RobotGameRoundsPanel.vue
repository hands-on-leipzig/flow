<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'

type RobotGamePublicRounds = {
  vr1: boolean;
  vr2: boolean;
  vr3: boolean;
  vf: boolean;
  hf: boolean;
};

type RoundKey = keyof RobotGamePublicRounds

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
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

async function fetchRounds() {
  if (!eventId.value) return
  loading.value = true
  try {
    const response = await axios.get(`/contao/rounds/${eventId.value}`)
    rounds.value = response.data
  } catch (error) {
    console.error('Error fetching robot game rounds:', error)
  } finally {
    loading.value = false
  }
}

async function toggleRound(round: RoundKey) {
  if (!eventId.value || !rounds.value || saving.value) return
  const next = !rounds.value[round]
  const previous = rounds.value[round]
  rounds.value[round] = next
  saving.value = round
  try {
    await axios.put(`/contao/rounds/${eventId.value}`, rounds.value)
  } catch (error) {
    rounds.value[round] = previous
    console.error('Error updating robot game rounds:', error)
  } finally {
    saving.value = null
  }
}

watch(() => eventId.value, fetchRounds, {immediate: true})
onMounted(fetchRounds)
</script>

<template>
  <section class="rounded-xl bg-white shadow p-4 sm:p-6">
    <div class="mb-4">
      <h2 class="text-lg font-semibold text-gray-900">Robot-Game Ergebnisse</h2>
      <p class="text-sm text-gray-600">
        Wähle aus, welche Runden öffentlich sichtbar sein sollen.
      </p>
    </div>

    <div v-if="loading" class="py-8 text-center text-sm text-gray-500">Lade...</div>

    <div v-else-if="rounds" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
      <button
          v-for="round in roundOptions"
          :key="round.key"
          type="button"
          :disabled="saving !== null"
          @click="toggleRound(round.key)"
          :class="[
            'min-h-12 rounded-lg border px-4 py-3 text-sm font-semibold transition-colors',
            rounds[round.key]
              ? 'border-green-600 bg-green-600 text-white'
              : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400',
            saving === round.key ? 'opacity-70' : ''
          ]"
      >
        {{ round.label }}
      </button>
    </div>
  </section>
</template>
