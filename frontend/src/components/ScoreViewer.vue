<script setup lang="ts">
import {computed, onMounted, onUnmounted, unref} from 'vue';
import {useScores, roundNames, createTeams} from '@/services/useScores';

const props = defineProps<{
  eventId: number | string
}>();
const highlightColor = '#FFD700';

const allScores = computed(() => {
  const result: Record<string, any[]> = {};
  const rounds = unref(scores)?.rounds ?? {};
  for (const key of Object.keys(rounds)) {
    result[key] = createTeams(rounds[key], key);
  }
  return result;
})

const {
  scores,
  error,
  loadScores,
} = useScores(Number(props.eventId));

onMounted(() => {
  loadScores();
});
</script>

<template>
  <div class="p-4 mx-auto">
    <h2 class="text-xl font-bold mb-3 text-center">Ergebnisse Robot-Game {{ scores?.name || '' }}</h2>

    <div v-if="error" class="text-red-600 text-sm mb-3">Beim Laden der Daten ist ein Fehler aufgetreten.</div>
    <div v-if="!scores && !error" class="text-gray-500 text-center">Lade...</div>

    <div v-if="scores && scores.rounds" class="flex flex-col md:flex-row md:space-x-6">
      <div v-for="(category, key) in scores.rounds" :key="key" class="mb-4 md:mb-0 md:flex-1">
        <div class="flex items-center justify-between mb-2 px-2">
          <div class="font-semibold text-lg">{{ roundNames[key] || key }}</div>
        </div>

        <div>
          <div v-for="team in allScores[key]" :key="team.id"
               class="bg-white rounded-lg shadow-sm mb-2 p-3 flex flex-row">
            <div class="flex-1">
              <div class="font-medium text-base truncate">{{ team.name }}</div>
              <div class="text-sm text-gray-500">Rang: {{ team.rank }}</div>
            </div>

            <div class="mt-2 md:ml-4 overflow-x-auto md:overflow-visible flex-shrink-0">
              <div class="flex space-x-2">
                <span v-for="(s, idx) in team.scores" :key="idx"
                      :class="['px-3 py-1 rounded-full text-sm font-semibold whitespace-nowrap', s.highlight ? 'text-white' : 'text-gray-800']"
                      :style="s.highlight ? { backgroundColor: highlightColor } : { backgroundColor: 'rgba(0,0,0,0.05)' }">
                  {{ s.points }}
                </span>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<style scoped>

</style>
