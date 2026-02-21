<script setup lang="ts">
import type {Team} from "@/models/robotGameScores";
import {getDemoData, roundNames} from '@/services/useScores';
import {RobotGameSlideContent} from "@/models/robotGameSlideContent";
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from "vue";


const props = defineProps<{
  name?: string,
  round?: string,
  paginatedTeams?: Team[],
  content: RobotGameSlideContent,
  demo?: boolean
}>();

const wrapperRef = ref<HTMLElement | null>(null);
const titleRef = ref<HTMLElement | null>(null);
const shortTitleRef = ref<HTMLElement | null>(null);
const tableRef = ref<HTMLTableElement | null>(null);

let ro: ResizeObserver | null = null;

const teams = computed(() => {
  if (props.paginatedTeams) {
    return props.paginatedTeams;
  } else if (props.demo) {
    const demo = getDemoData();
    return demo.slice(0, props.content.teamsPerPage);
  }
});

async function adjustFontSize() {
  if (!wrapperRef.value || !tableRef.value) {
    return;
  }

  const wrapperRect = wrapperRef.value.getBoundingClientRect();
  let titleRect = titleRef.value?.getBoundingClientRect();
  if (!titleRect || titleRect.height === 0) {
    titleRect = shortTitleRef.value?.getBoundingClientRect() ?? { height: 0 } as DOMRect;
  }

  // Platz für Tabelle: Wrapper minus Titel minus kleiner Puffer
  const availableHeight = Math.max(0, wrapperRect.height - titleRect.height - 12);
  const availableWidth = Math.max(0, wrapperRect.width);

  // Grenzen
  const MIN_FONT = 8;
  const MAX_FONT = 40;

  // Binary search for the largest font that fits both width and height
  let low = MIN_FONT;
  let high = MAX_FONT;
  let best = MIN_FONT;

  while (low <= high) {
    const mid = Math.floor((low + high) / 2);
    // apply mid font
    wrapperRef.value.style.setProperty('--table-font-size', `${mid}px`);
    // wait for DOM to update with new font
    // (use two ticks to be extra safe with rendering in complex layouts)
    await nextTick();

    const rect = tableRef.value.getBoundingClientRect();
    const fitsHeight = rect.height <= availableHeight + 1;
    const fitsWidth = rect.width <= availableWidth + 1;

    if (fitsHeight && fitsWidth) {
      best = mid; // mid fits, try bigger
      low = mid + 1;
    } else {
      // mid does not fit, try smaller
      high = mid - 1;
    }
  }

  // set best found font
  wrapperRef.value.style.setProperty('--table-font-size', `${best}px`);
}

watch(() => [props.paginatedTeams], () => {
  nextTick(adjustFontSize);
}, {deep: true});

onMounted(() => {
  nextTick(adjustFontSize);
  if (window.ResizeObserver) {
    ro = new ResizeObserver(() => {
      nextTick(adjustFontSize);
    });
    if (wrapperRef.value) ro.observe(wrapperRef.value);
  } else {
    window.addEventListener('resize', adjustFontSize);
  }
});

onUnmounted(() => {
  if (ro && wrapperRef.value) {
    ro.unobserve(wrapperRef.value);
    ro = null;
  } else {
    window.removeEventListener('resize', adjustFontSize);
  }
})

</script>

<template>
  <div class="robot-table-wrapper">
    <div class="table-container" ref="wrapperRef">
      <h1 class="slide-title hidden md:block" ref="titleRef">
        ERGEBNISSE {{ round ? roundNames[round].toUpperCase() : '' }}: {{ name }}
      </h1>
      <h1 class="slide-title md:hidden block" ref="shortTitleRef">
        {{ name }}
      </h1>


      <div v-if="!round" class="scores flex items-center justify-center">
        <span>Keine Ergebnisse verfügbar.</span>
      </div>
      <div v-if="round" class="table-inner" ref="tableRef">
        <table class="scores table-fixed">
          <thead>
          <tr>
            <th class="text-left w-auto">Team</th>
            <template v-if="round === 'VR'">
              <th class="cell w-12 min-w-[120px]">R I</th>
              <th class="cell w-12 min-w-[120px]">R II</th>
              <th class="cell w-12 min-w-[120px]">R III</th>
            </template>
            <template v-else>
              <th class="cell w-12 min-w-[130px]">Score</th>
            </template>
            <th class="cell w-12 min-w-[130px]">Rank</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="team in teams" :key="team.id">
            <td class="teamName w-auto">{{ team.name }}</td>
            <template v-for="(score, index) in team.scores" :key="index">
              <td class="cell w-12 min-w-[130px]" :class="{ highlight: score.highlight }">
                {{ score.points }}
              </td>
            </template>
            <td class="cell w-12 min-w-[130px]">{{ team.rank }}</td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.robot-table-wrapper {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  justify-content: flex-start;
  padding: 0;
  box-sizing: border-box;
}


.slide-title {
  font-size: 2.5rem;
  font-weight: bold;
  padding: 0 1rem 3rem 1rem;
  text-align: center;
}

.table-container {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
}

/* Tabelle passt sich: Zeilen umbrechen, responsive Schrift */
.table-inner {
  width: 100%;
  display: block;
}

.scores {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: var(--table-font-size);
  background-color: v-bind('props.content.tableBackgroundColor');
  color: v-bind('props.content.textColor');
}

th, td {
  padding: 0.5rem 1rem 0.5rem 1rem;
}

.teamName {
  border-right: 1px solid v-bind('props.content.tableBorderColor');
  border-top: 1px solid v-bind('props.content.tableBorderColor');
  width: auto;
  white-space: normal;
  word-break: break-word;
}

.cell {
  text-align: center;
}

td {
  border-top: 1px solid v-bind('props.content.tableBorderColor');
}

tr > td:not(:last-child),
tr > th:not(:last-child) {
  border-right: 1px solid v-bind('props.content.tableBorderColor');
}

.highlight {
  background-color: v-bind('props.content.highlightColor');
}

@media (max-width: 480px), (max-height: 400px) {
  .scores {
    font-size: calc(var(--table-font-size) * 0.9);
  }
}

</style>