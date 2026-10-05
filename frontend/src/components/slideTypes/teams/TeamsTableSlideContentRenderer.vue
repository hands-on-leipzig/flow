<script setup lang="ts">
import {computed, nextTick, onMounted, ref, watch} from "vue";
import {TeamsTableSlideContent} from "../../../models/teamsTableSlideContent";
import {useMultiPageTable} from "@/composables/useMultiPageTable";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";
import ProgramOfficialName from "@/components/atoms/ProgramOfficialName.vue";
import {useTableFontResize} from "@/composables/useTableFontResize";
import {programLogoAlt, programLogoSrc} from "../../../utils/images";
import {programId, programOfficialHtml, type EventProgramRef} from "@/utils/eventPrograms";
import {loadEventPrograms, loadTeamLanes, programForLane, selectedLanes} from "./teamLanes";

const props = withDefaults(defineProps<{
  content: TeamsTableSlideContent,
  preview: boolean,
  eventId: number,
  visible?: boolean
}>(), {
  preview: false,
  visible: false
});

const emit = defineEmits<{ (e: 'next'): void }>();

type TeamRow = {
  name: string,
  meta: string,
}

type LaneCard = {
  program: EventProgramRef,
  color: string,
  teams: TeamRow[],
}

const lanes = ref<LaneCard[]>([]);
const wrapperRef = ref<HTMLElement | null>(null);
const gridRef = ref<HTMLElement | null>(null);

const pageSize = computed(() => props.content.teamsPerPage || 8);
const secondsPerPage = computed(() => props.content.secondsPerPage || 15);
const isActive = computed(() => !!props.visible && !props.preview);

const rowIndexes = computed(() => {
  const longest = Math.max(0, ...lanes.value.map((lane) => lane.teams.length));
  return Array.from({length: longest}, (_, index) => index);
});

const {currentIndex, paginatedItems, handleArrow} = useMultiPageTable<number>({
  items: rowIndexes,
  pageSize,
  secondsPerPage,
  isActive,
  onAutoEnd: () => emit('next')
});

const visibleLanes = computed(() => lanes.value
  .map((lane) => ({
    ...lane,
    teams: lane.teams.slice(currentIndex.value, currentIndex.value + pageSize.value),
  }))
  .filter((lane) => lane.teams.length > 0));

function laneColor(program: EventProgramRef): string {
  return program.color_hex ? `#${String(program.color_hex).replace(/^#/, '')}` : '#334155';
}

async function fetchTeams() {
  try {
    const [laneRows, eventProgramRows] = await Promise.all([
      loadTeamLanes(props.eventId),
      loadEventPrograms(props.eventId),
    ]);
    lanes.value = selectedLanes(laneRows, props.content.programs).map((lane) => {
      const program = programForLane(lane, eventProgramRows);
      const list = Array.isArray(lane.teams) ? lane.teams : [];
      return {
        program,
        color: laneColor(program),
        teams: list.map((team) => ({
          name: team?.name || '–',
          meta: [team?.ref, team?.organization, team?.location].filter(Boolean).join(' · '),
        })),
      };
    });
    nextTick(adjustFontSize);
  } catch (error) {
    console.error("Error fetching teams:", error);
  }
}

const {adjustFontSize} = useTableFontResize({
  wrapperRef,
  tableRef: gridRef as any,
  minFont: 8,
  maxFont: 40,
  getAvailableSize: () => {
    const wrapperRect = wrapperRef.value?.getBoundingClientRect();
    if (!wrapperRect) {
      return {width: 0, height: 0};
    }

    return {
      width: Math.max(0, wrapperRect.width - 8),
      height: Math.max(0, wrapperRect.height - 8)
    };
  }
});

onMounted(fetchTeams);

watch(() => [paginatedItems.value], () => {
  nextTick(adjustFontSize);
}, {deep: true});


defineExpose({handleArrow});
</script>

<template>
  <div class="relative w-full h-full overflow-hidden">
    <FabricSlideContentRenderer
      v-if="props.content.background"
      class="absolute inset-0 z-0"
      :content="props.content"
      :preview="props.preview"
    />

    <div class="relative z-10 w-full h-full p-6">
      <div ref="wrapperRef" class="teams-shell">
        <div
          ref="gridRef"
          class="teams-grid"
          :style="{ '--lane-count': Math.max(1, visibleLanes.length) }"
        >
          <div v-if="!visibleLanes.length" class="teams-empty">Keine Teams vorhanden</div>
          <article
            v-for="lane in visibleLanes"
            :key="programId(lane.program)"
            class="teams-lane"
            :style="{ '--lane-color': lane.color }"
          >
            <header class="teams-lane__head">
              <img
                :src="programLogoSrc(lane.program)"
                :alt="programLogoAlt(lane.program)"
                class="teams-lane__logo"
              />
              <h3 class="teams-lane__title">
                <ProgramOfficialName :html="programOfficialHtml(lane.program)"/>
              </h3>
            </header>
            <ol class="teams-lane__list">
              <li v-for="(team, index) in lane.teams" :key="`${index}-${team.name}`" class="teams-lane__item">
                <span class="teams-lane__name">{{ team.name }}</span>
                <span v-if="team.meta" class="teams-lane__meta">{{ team.meta }}</span>
              </li>
            </ol>
          </article>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.teams-shell {
  width: 100%;
  height: 100%;
  padding: 1rem;
}

.teams-grid {
  display: grid;
  grid-template-columns: repeat(var(--lane-count, 1), minmax(0, 1fr));
  gap: 1.25em;
  align-items: start;
  font-size: var(--table-font-size, 16px);
  color: #0f172a;
}

.teams-lane {
  display: flex;
  flex-direction: column;
  gap: 0.75em;
  padding: 0.85em 1em;
  border-radius: 0.9em;
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-left: 0.2em solid var(--lane-color);
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 14px 32px rgba(15, 23, 42, 0.09),
  0 4px 12px rgba(15, 23, 42, 0.045),
  inset 0 1.5px 0 rgba(255, 255, 255, 0.98);
  overflow: hidden;
}

.teams-lane__head {
  display: flex;
  align-items: center;
  gap: 0.55em;
  min-width: 0;
}

.teams-lane__logo {
  width: 1.8em;
  height: 1.8em;
  flex-shrink: 0;
  object-fit: contain;
}

.teams-lane__title {
  margin: 0;
  flex: 1;
  min-width: 0;
  font-size: 1.05em;
  font-weight: 700;
  letter-spacing: -0.01em;
  line-height: 1.3;
}

.teams-lane__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
}

.teams-lane__item {
  display: flex;
  flex-direction: column;
  gap: 0.12em;
  padding: 0.55em 0.15em;
  border-top: 1px solid rgba(148, 163, 184, 0.28);
  min-width: 0;
}

.teams-lane__item:first-child {
  border-top: none;
  padding-top: 0.15em;
}

.teams-lane__name {
  font-weight: 600;
  line-height: 1.3;
  overflow-wrap: anywhere;
}

.teams-lane__meta {
  font-size: 0.85em;
  line-height: 1.35;
  color: #64748b;
  overflow-wrap: anywhere;
}

.teams-empty {
  grid-column: 1 / -1;
  justify-self: center;
  padding: 0.6em 1.2em;
  border-radius: 0.5em;
  background: rgba(255, 255, 255, 0.85);
  font-weight: 600;
  color: #475569;
}
</style>
