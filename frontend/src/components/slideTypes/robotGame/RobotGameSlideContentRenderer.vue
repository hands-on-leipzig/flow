<script setup lang="ts">
import {RobotGameSlideContent} from '../../../models/robotGameSlideContent.js';
import {onMounted, onUnmounted, ref, computed, watch} from "vue";
import {useScores, createTeams} from '@/services/useScores';
import type {TeamResponse, RoundResponse} from "@/models/robotGameScores";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";
import RobotGameTable from "./RobotGameTable.vue";
import {useMultiPageTable} from "@/composables/useMultiPageTable";

const props = withDefaults(defineProps<{
  content: RobotGameSlideContent,
  preview: boolean,
  eventId: number,
  visible?: boolean
}>(), {
  visible: false
});

const emit = defineEmits<{ (e: 'next'): void }>();

const {scores, loadScores, startAutoRefresh, stopAutoRefresh} = useScores(props.eventId);

const isPaused = ref(false);
const teamsPerPage = computed(() => props.content.teamsPerPage || 8);
const secondsPerPage = computed(() => props.content.secondsPerPage || 15);
const isActive = computed(() => !!props.visible && !props.preview);
const round = ref<string | undefined>(undefined);
const teams = ref([]);

const {paginatedItems, handleArrow} = useMultiPageTable({
  items: teams,
  pageSize: teamsPerPage,
  secondsPerPage,
  isActive,
  isPaused,
  onAutoEnd: () => emit('next')
});

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
onMounted(loadScores);
onMounted(() => {
  if (!props.preview) {
    startAutoRefresh();
  }
});

onMounted(() => {
  window.addEventListener('keydown', handleKeyDown);
});

onUnmounted(stopAutoRefresh);

onUnmounted(() => {
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

function handleKeyDown(event: KeyboardEvent) {
  if (!props.visible) {
    return;
  }
  if (event.key === 'Enter') {
    isPaused.value = !isPaused.value;
  }
}

defineExpose({handleArrow});
</script>

<template>
  <div class="relative w-full h-full overflow-hidden">
    <FabricSlideContentRenderer v-if="props.content.background"
                                class="absolute inset-0 z-0"
                                :content="props.content" :preview="props.preview"></FabricSlideContentRenderer>

    <div class="slide-container" :class="{ 'preview': props.preview }">
      <RobotGameTable :name="scores?.name?.toUpperCase()" :paginatedTeams="paginatedItems" :round="round"
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
