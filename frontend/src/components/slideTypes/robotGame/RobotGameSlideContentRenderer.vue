<script setup lang="ts">
import {RobotGameSlideContent} from '../../../models/robotGameSlideContent.js';
import {onMounted, onUnmounted, ref, computed, toRef, watch, shallowRef} from "vue";
import {useScores, createTeams} from '@/services/useScores';
import type {TeamResponse, RoundResponse} from "@/models/robotGameScores";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";
import RobotGameTable from "./RobotGameTable.vue";

const props = defineProps<{
  content: RobotGameSlideContent,
  preview: boolean,
  eventId: number
}>();

const emit = defineEmits<{ (e: 'next'): void }>();

const {scores, error, loadScores, startAutoRefresh, stopAutoRefresh} = useScores(props.eventId);

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

// Sichtbarkeit der Folie via Observer feststellen -> Auf Seite 1 beginnen
const root = shallowRef<HTMLElement | null>(null);
let io = new IntersectionObserver((entries) => {
  for (const entry of entries) {
    if (entry.isIntersecting && entry.target === root.value) { // true -> Element aktuell in Viewport sichtbar (Folie aktiv)
      currentIndex.value = 0;
      startAutoAdvance();
      return;
    }
  }
  clearInterval(autoAdvanceInterval);
}, {threshold: 0.01});

onMounted(() => {
  startAutoAdvance();
  io.observe(root.value);
  window.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
  clearInterval(autoAdvanceInterval);
  if (io && root.value) {
    io.unobserve(root.value);
    io.disconnect();
    io = null;
  }
  window.removeEventListener('keydown', handleKeyDown);
});

function startAutoAdvance() {
  const secondsPerPage = props.content.secondsPerPage || 15;
  autoAdvanceInterval = setInterval(() => {
    if (!isPaused.value) {
      advancePage();
    }
  }, secondsPerPage * 1000);
}

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
  const nextIndex = currentIndex.value + teamsPerPage.value;
  const willWrapAround = nextIndex >= teams.value.length;

  if (willWrapAround) {
    // switch to next slide
    clearInterval(autoAdvanceInterval);
    emit('next')
  } else {
    currentIndex.value = nextIndex;
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
  <div ref="root" class="relative w-full h-full overflow-hidden">
    <FabricSlideContentRenderer v-if="props.content.background"
                                class="absolute inset-0 z-0"
                                :content="props.content" :preview="props.preview"></FabricSlideContentRenderer>

    <div class="slide-container" :class="{ 'preview': props.preview }">
      <RobotGameTable :name="scores?.name?.toUpperCase()" :paginatedTeams="paginatedTeams" :round="round"
                      :content="content"></RobotGameTable>
    </div>
  </div>
</template>

<style scoped>

.slide-container {
  width: 100%;
  height: 100%;
  min-height: 0;
  position: relative;
  display: flex;
  align-items: stretch;
  background-size: cover;
  background-position: center;
  padding: 2em;
  color: v-bind('props.content.textColor');
}

.preview {
  zoom: 0.15;
}
</style>
