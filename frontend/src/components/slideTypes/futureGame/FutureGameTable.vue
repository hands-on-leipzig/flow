<script setup lang="ts">
import {FutureGameSlideContent, type FutureGameRound, type FutureGameTeam} from "@/models/futureGameSlideContent";
import {computed, nextTick, ref, watch} from "vue";
import {useTableFontResize} from "@/composables/useTableFontResize";

const props = defineProps<{
  name?: string,
  rounds: FutureGameRound[],
  teams: FutureGameTeam[],
  content: FutureGameSlideContent,
}>();

const wrapperRef = ref<HTMLElement | null>(null);
const titleRef = ref<HTMLElement | null>(null);
const shortTitleRef = ref<HTMLElement | null>(null);
const tableRef = ref<HTMLTableElement | null>(null);

const hasResults = computed(() => props.rounds.length > 0 && props.teams.length > 0);

const {adjustFontSize} = useTableFontResize({
  wrapperRef,
  tableRef,
  minFont: 8,
  maxFont: 40,
  getAvailableSize: () => {
    const wrapperRect = wrapperRef.value?.getBoundingClientRect();
    if (!wrapperRect) {
      return {width: 0, height: 0};
    }

    let titleRect = titleRef.value?.getBoundingClientRect();
    if (!titleRect || titleRect.height === 0) {
      titleRect = shortTitleRef.value?.getBoundingClientRect() ?? ({height: 0} as DOMRect);
    }

    return {
      width: Math.max(0, wrapperRect.width),
      height: Math.max(0, wrapperRect.height - titleRect.height - 12)
    };
  }
});

watch(() => [props.teams, props.rounds], () => {
  nextTick(adjustFontSize);
}, {deep: true});

function formatAverage(value: number) {
  if (!Number.isFinite(value)) return '–';
  if (Number.isInteger(value)) return String(value);
  return value.toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1});
}
</script>

<template>
  <div class="future-table-wrapper">
    <div class="table-container" ref="wrapperRef">
      <h1 class="slide-title hidden md:block" ref="titleRef">
        SPIEL-ERGEBNISSE{{ name ? `: ${name}` : '' }}
      </h1>
      <h1 class="slide-title md:hidden block" ref="shortTitleRef">
        {{ name }}
      </h1>

      <div v-if="!hasResults" class="scores flex items-center justify-center">
        <span>Keine Ergebnisse verfügbar.</span>
      </div>
      <div v-else class="table-inner" ref="tableRef">
        <table class="scores table-fixed">
          <thead>
          <tr>
            <th class="text-left w-auto">Team</th>
            <th v-for="round in rounds" :key="round.round" class="cell scoreCell">{{ round.label }}</th>
            <th class="cell scoreCell">Ø</th>
            <th class="cell rankCell">Platz</th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="team in teams" :key="team.id">
            <td class="teamName w-auto">{{ team.name }}</td>
            <td v-for="(points, index) in team.points" :key="index" class="cell scoreCell">
              {{ points ?? '–' }}
            </td>
            <td class="cell scoreCell average">{{ formatAverage(team.average) }}</td>
            <td class="cell rankCell">{{ team.rank }}</td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.future-table-wrapper {
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

.table-inner {
  width: 100%;
  display: block;
}

.scores {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: var(--table-font-size, 16px);
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

.scoreCell {
  width: 130px;
  min-width: 130px;
}

.rankCell {
  width: 120px;
  min-width: 120px;
  font-weight: 600;
}

.average {
  font-weight: 600;
}

td {
  border-top: 1px solid v-bind('props.content.tableBorderColor');
}

tr > td:not(:last-child),
tr > th:not(:last-child) {
  border-right: 1px solid v-bind('props.content.tableBorderColor');
}

@media (max-width: 480px), (max-height: 400px) {
  .scores {
    font-size: calc(var(--table-font-size) * 0.9);
  }
}
</style>
