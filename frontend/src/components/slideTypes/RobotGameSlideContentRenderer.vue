<script setup lang="ts">
import {RobotGameSlideContent} from '../../models/robotGameSlideContent.js';
import {onMounted, onUnmounted, ref, computed, toRef, watch} from "vue";
import { useScores, expectedScores, roundNames, createTeams } from '@/services/useScores';
import type {Team, TeamResponse, RoundResponse} from "@/models/robotGameScores";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";

const props = defineProps<{
  content: RobotGameSlideContent,
  preview: boolean,
  eventId: number
}>();

const { scores, error, loadScores, startAutoRefresh, stopAutoRefresh } = useScores(props.eventId);

const currentIndex = ref(0);
const isPaused = ref(false);
const teamsPerPage = toRef(props.content, 'teamsPerPage') || ref(8);
const round = ref<string | undefined>(undefined);
const teams = ref([]);

watch(() => scores.value, (newScores) => {
  if (newScores) {
    const category = getRoundToShow(newScores.rounds);
    if (!category) {
      round.value = undefined;
    }
    teams.value = createTeams(category, round.value) || [];
  } else {
    teams.value = [];
    round.value = undefined;
  }
});
const paginatedTeams = computed(() => {
  return teams.value.slice(currentIndex.value, currentIndex.value + teamsPerPage.value);
});

onMounted(loadScores);
onMounted(() => {
  if (!props.preview) {
    startAutoRefresh();
  }
});
onUnmounted(stopAutoRefresh);

let autoAdvanceInterval;

onMounted(() => {
  const secondsPerPage = props.content.secondsPerPage || 15;
  autoAdvanceInterval = setInterval(() => {
    if (!isPaused.value) {
      advancePage();
    }
  }, secondsPerPage * 1000);

  // Add keydown event listener
  window.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
  clearInterval(autoAdvanceInterval);
  window.removeEventListener('keydown', handleKeyDown);
});

function getRoundToShow(rounds: RoundResponse): TeamResponse {
  if (!rounds) {
    return undefined;
  }
  if (rounds.HF) {
    round.value = 'HF';
    return rounds.HF;
  }
  if (rounds.VF) {
    round.value = 'VF';
    return rounds.VF;
  }
  if (rounds.VR) {
    round.value = 'VR';
    return rounds.VR;
  }
  return undefined;
}

function advancePage() {
  if (currentIndex.value + teamsPerPage.value > teams.value.length) {
    currentIndex.value = 0;
  } else {
    currentIndex.value = (currentIndex.value + teamsPerPage.value) % teams.value.length;
  }
}

// Previous page function
function previousPage() {
  if (currentIndex.value === 0) {
    const teamsLastPage = teams.value.length % teamsPerPage.value;
    if (teamsLastPage === 0) {
      currentIndex.value = teams.value.length - teamsPerPage.value;
    } else {
      currentIndex.value = teams.value.length - teamsLastPage;
    }
  } else {
    currentIndex.value = Math.max(currentIndex.value - teamsPerPage.value, 0);
  }
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Enter') {
    console.log('pausing');
    isPaused.value = !isPaused.value;
  } else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
    advancePage();
  } else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
    previousPage();
  }
}
</script>

<template>
  <div class="relative w-full h-full overflow-hidden">
    <FabricSlideContentRenderer v-if="props.content.background"
                                class="absolute inset-0 z-0"
                                :content="props.content" :preview="props.preview"></FabricSlideContentRenderer>

    <div class="slide-container" :class="{ 'preview': props.preview }">
      <h1 class="slide-title">
        ERGEBNISSE {{ round ? roundNames[round].toUpperCase() : '' }}: {{ scores?.name?.toUpperCase() }}
      </h1>

      <div v-if="!round" class="scores flex items-center justify-center">
        <span>Keine Ergebnisse verfügbar.</span>
      </div>
      <div v-if="round">
        <table class="scores">
          <thead>
          <tr>
            <th>Team</th>
            <template v-if="round === 'VR'">
              <th class="cell">R I</th>
              <th class="cell">R II</th>
              <th class="cell">R III</th>
            </template>
            <template v-else>
              <th class="cell">Score</th>
            </template>
            <th class="cell">Rank</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="team in paginatedTeams" :key="team.id">
            <td class="teamName">{{ team.name }}</td>
            <template v-for="(score, index) in team.scores" :key="index">
              <td class="cell" :class="{ highlight: score.highlight }">
                {{ score.points }}
              </td>
            </template>
            <td class="cell">{{ team.rank }}</td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>

.slide-container {
  height: 100%;
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: start;
  background-size: cover;
  background-position: center;
  padding: 5em;
  color: v-bind('props.content.textColor');
}

.slide-title {
  font-size: 2.5rem;
  font-weight: bold;
  padding: 0 1rem 3rem 1rem;
}

.scores {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 2.5rem;
}

th, td {
  padding: 0.5rem 1rem 0.5rem 1rem;
}

.teamName {
  border-right: 1px solid black;
  border-top: 1px solid black;
  width: auto;
}

.cell {
  width: 9rem;
  text-align: center;
}

td {
  border-top: 1px solid black;
}

tr > td:not(:last-child),
tr > th:not(:last-child) {
  border-right: 1px solid black;
}

.highlight {
  background-color: v-bind('props.content.highlightColor');
}

.preview {
  zoom: 0.15;
}
</style>
