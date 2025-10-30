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
  startAutoRefresh,
  stopAutoRefresh,
  setDemoData
} = useScores(Number(props.eventId));

onMounted(() => {
  loadScores();
  startAutoRefresh();

  setDemoData({
    "name": "RPT Demo",
    "rounds": {
      "VR": {
        "1": {
          "name": "TechKids",
          "scores": [
            {"points": 100, "highlight": true},
            {"points": 80, "highlight": false},
            {"points": 60, "highlight": false}
          ],
          "rank": 1,
          "id": 1
        },
        "2": {
          "name": "RoboExplorers",
          "scores": [
            {"points": 70, "highlight": true},
            {"points": 50, "highlight": false},
            {"points": 30, "highlight": false}
          ],
          "rank": 2,
          "id": 2
        },
        "3": {
          "name": "FutureScientists",
          "scores": [
            {"points": 10, "highlight": false},
            {"points": 20, "highlight": false},
            {"points": 60, "highlight": true}
          ],
          "rank": 3,
          "id": 3
        },
        "4": {
          "name": "DiscoversSquad",
          "scores": [
            {"points": 40, "highlight": true},
            {"points": 40, "highlight": true},
            {"points": 40, "highlight": true}
          ],
          "rank": 4,
          "id": 4
        },
      }
    }
  });
});

onUnmounted(() => {
  stopAutoRefresh();
});
</script>

<template>
  <div class="p-4 mx-auto">
    <h2 class="text-xl font-bold mb-3 text-center">RobotGame {{ scores?.name || '' }}</h2>

    <div v-if="error" class="text-red-600 text-sm mb-3">Beim Laden der Daten ist ein Fehler aufgetreten.</div>
    <div v-if="!scores && !error" class="text-gray-500 text-center">Lade...</div>

    <div v-if="scores && scores.rounds">
      <div v-for="(category, key) in scores.rounds" :key="key" class="mb-4">
        <div class="flex items-center justify-between mb-2">
          <div class="font-semibold text-lg">{{ roundNames[key] || key }}</div>
        </div>

        <div>
          <div v-for="team in allScores[key]" :key="team.id"
               class="bg-white rounded-lg shadow-sm mb-2 p-3 flex flex-col sm:flex-row sm:items-center">
            <div class="flex-1">
              <div class="font-medium text-base truncate">{{ team.name }}</div>
              <div class="text-sm text-gray-500">Rang: {{ team.rank }}</div>
            </div>

            <div class="mt-2 sm:mt-0 sm:ml-4 overflow-x-auto">
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
