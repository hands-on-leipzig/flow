<script setup lang="ts">
import {computed, nextTick, onMounted, ref, watch} from "vue";
import axios from "axios";
import {TeamsTableSlideContent} from "../../../models/teamsTableSlideContent";
import {useMultiPageTable} from "@/composables/useMultiPageTable";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";
import {useTableFontResize} from "@/composables/useTableFontResize";

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
  category: 'Explore' | 'Challenge',
  name: string,
  organization: string,
  location: string
}

const teams = ref<TeamRow[]>([]);
const wrapperRef = ref<HTMLElement | null>(null);
const tableRef = ref<HTMLTableElement | null>(null);

const pageSize = computed(() => props.content.teamsPerPage || 8);
const secondsPerPage = computed(() => props.content.secondsPerPage || 15);
const isActive = computed(() => !!props.visible && !props.preview);

const {paginatedItems, handleArrow} = useMultiPageTable<TeamRow>({
  items: teams,
  pageSize,
  secondsPerPage,
  isActive,
  onAutoEnd: () => emit('next')
});

function normalizeTeam(team: any, category: 'Explore' | 'Challenge'): TeamRow {
  return {
    category,
    name: team?.name || '-',
    organization: team?.organization || '-',
    location: team?.location || '-'
  };
}

async function fetchTeams() {
  try {
    const scheduleResponse = await axios.get(`/publish/public-information/${props.eventId}`);
    if (scheduleResponse && scheduleResponse.data) {
      const explore = (scheduleResponse.data?.teams?.explore?.list || []).map((team: any) => {
        return normalizeTeam(team, 'Explore');
      });

      const challenge = (scheduleResponse.data?.teams?.challenge?.list || []).map((team: any) => {
        return normalizeTeam(team, 'Challenge');
      });

      teams.value = [...explore, ...challenge];
      nextTick(adjustFontSize);
    }
  } catch (error) {
    console.error("Error fetching teams:", error);
  }
}

const {adjustFontSize} = useTableFontResize({
  wrapperRef,
  tableRef,
  minFont: 8,
  maxFont: 32,
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
      <div ref="wrapperRef" class="teams-table-shell">
        <table ref="tableRef" class="teams-table">
          <thead>
          <tr>
            <th>Programm</th>
            <th>Name</th>
            <th>Organisation</th>
            <th>Ort</th>
          </tr>
          </thead>
          <tbody>
          <tr v-if="!paginatedItems.length">
            <td colspan="4" class="teams-empty">Keine Teams vorhanden</td>
          </tr>
          <tr v-for="(team, index) in paginatedItems" :key="`${team.category}-${team.name}-${index}`">
            <td>{{ team.category }}</td>
            <td>{{ team.name }}</td>
            <td>{{ team.organization }}</td>
            <td>{{ team.location }}</td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.teams-table-shell {
  width: 100%;
  height: 100%;
  border-radius: 0.75rem;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.teams-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: var(--table-font-size, 16px);
}

.teams-table th,
.teams-table td {
  border: 1px solid black;
  padding: 0.5rem 0.6rem;
  text-align: left;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.teams-empty {
  text-align: center;
  opacity: 0.9;
}
</style>