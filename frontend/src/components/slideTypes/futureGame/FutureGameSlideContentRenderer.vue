<script setup lang="ts">
import {FutureGameSlideContent, type FutureGameRound, type FutureGameTeam} from '@/models/futureGameSlideContent';
import {computed, onMounted, onUnmounted, ref} from "vue";
import axios from 'axios';
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";
import FutureGameTable from "./FutureGameTable.vue";
import {useMultiPageTable} from "@/composables/useMultiPageTable";

const props = withDefaults(defineProps<{
  content: FutureGameSlideContent,
  preview: boolean,
  eventId: number,
  visible?: boolean
}>(), {
  visible: false
});

const emit = defineEmits<{ (e: 'next'): void }>();

const REFRESH_MS = 60 * 1000;

const DEMO_ROUNDS: FutureGameRound[] = [
  {round: 1, label: 'Runde 1'},
  {round: 2, label: 'Runde 2'},
];
const DEMO_TEAMS: FutureGameTeam[] = [
  {id: 1, name: 'Bricktastics', points: [140, 165], average: 152.5, rank: 1},
  {id: 2, name: 'Glühwürmchen', points: [125, 150], average: 137.5, rank: 2},
  {id: 3, name: 'Robo Rangers', points: [110, 130], average: 120, rank: 3},
  {id: 4, name: 'Die Tüftler', points: [95, 120], average: 107.5, rank: 4},
];

const name = ref<string | undefined>(undefined);
const rounds = ref<FutureGameRound[]>(props.preview ? DEMO_ROUNDS : []);
const teams = ref<FutureGameTeam[]>(props.preview ? DEMO_TEAMS : []);

const isPaused = ref(false);
const teamsPerPage = computed(() => props.content.teamsPerPage || 8);
const secondsPerPage = computed(() => props.content.secondsPerPage || 15);
const isActive = computed(() => !!props.visible && !props.preview);

const {paginatedItems, handleArrow} = useMultiPageTable({
  items: teams,
  pageSize: teamsPerPage,
  secondsPerPage,
  isActive,
  isPaused,
  onAutoEnd: () => emit('next')
});

async function loadGame() {
  try {
    const {data} = await axios.get(`/scor/public/game/${props.eventId}`);
    name.value = data?.event?.name ? String(data.event.name).toUpperCase() : undefined;
    rounds.value = Array.isArray(data?.rounds) ? data.rounds : [];
    teams.value = Array.isArray(data?.teams) ? data.teams : [];
  } catch (error: any) {
    console.error(error?.message || error);
    rounds.value = [];
    teams.value = [];
  }
}

let refreshTimer: number | undefined;

function handleKeyDown(event: KeyboardEvent) {
  if (!props.visible) {
    return;
  }
  if (event.key === 'Enter') {
    isPaused.value = !isPaused.value;
  }
}

onMounted(() => {
  if (props.preview) {
    return;
  }
  loadGame();
  refreshTimer = window.setInterval(loadGame, REFRESH_MS);
  window.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
  if (refreshTimer !== undefined) {
    clearInterval(refreshTimer);
  }
  window.removeEventListener('keydown', handleKeyDown);
});

defineExpose({handleArrow});
</script>

<template>
  <div class="relative w-full h-full overflow-hidden">
    <FabricSlideContentRenderer v-if="props.content.background"
                                class="absolute inset-0 z-0"
                                :content="props.content" :preview="props.preview"></FabricSlideContentRenderer>

    <div class="slide-container relative z-10" :class="{ 'preview': props.preview }">
      <FutureGameTable :name="name" :rounds="rounds" :teams="paginatedItems" :content="content"></FutureGameTable>
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
