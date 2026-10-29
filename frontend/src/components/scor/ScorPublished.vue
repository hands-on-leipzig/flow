<script setup lang="ts">
import {onMounted, ref} from 'vue'
import axios from 'axios'

const props = defineProps<{eventId: string}>()
const results = ref<any>(null)
const error = ref('')

onMounted(async () => {
  try {
    const {data} = await axios.get(`/scor/public/results/${props.eventId}`)
    results.value = data
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Die Ergebnisse sind nicht veröffentlicht.'
  }
})
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4 p-4">
    <h1 class="text-2xl font-bold">{{ results?.event?.name || 'Ergebnisse' }}</h1>
    <p v-if="error" class="text-lg">{{ error }}</p>
    <ol v-else-if="results" class="space-y-2">
      <li v-for="team in results.teams" :key="team.id" class="glass-card liquid-surface-inner flex justify-between px-4 py-3">
        <span class="font-semibold">{{ team.champion_rank }}. {{ team.number }} {{ team.name }}</span>
        <span class="text-[var(--color-text-muted)]">Spiel {{ team.robot }} · Forschung {{ team.research }} · Roboterdesign {{ team.robotdesign }} · Grundwerte {{ team.core_values }}</span>
      </li>
    </ol>
  </div>
</template>
