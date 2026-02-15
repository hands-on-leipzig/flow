<script setup lang="ts">
import {onMounted, ref} from 'vue';
import {PublicPlanSlideContent} from "@/models/publicPlanSlideContent";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";
import axios from "axios";
import PublicPlanTable from "@/components/slideTypes/publicPlan/PublicPlanTable.vue";

const props = withDefaults(defineProps<{
  content: PublicPlanSlideContent,
  preview: boolean
}>(), {
  preview: false
});

const loading = ref(false);
const result = ref(null);

function getFormattedDateTime() {
  const now = new Date();

  // Get time components
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');

  return `${hours}:${minutes}`;
}

function buildPointInTimeParam() {
  return {point_in_time: getFormattedDateTime(), role: props.content.role};
}

async function callNow() {
  loading.value = true;
  result.value = null;
  try {
    const params = buildPointInTimeParam();
    const {data} = await axios.get(`/plans/action-now/${props.content.planId}`, {params});

    // Rollen-Filter anwenden
    if (data && data.groups) {
      if (props.content.role === 6) {
        data.groups = data.groups.filter((g: any) => g.group_meta?.first_program_id !== 2);
      } else if (props.content.role === 10) {
        data.groups = data.groups.filter((g: any) => g.group_meta?.first_program_id !== 3);
      }
    }

    result.value = data;
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
}

// bleibt wie gehabt – wird noch genutzt
const padTeam = (n: any) =>
    typeof n === 'number' || /^\d+$/.test(String(n))
        ? String(Number(n)).padStart(2, '0')
        : String(n ?? '').trim()

const teamLabel = (name?: string | null, num?: any) => {
  const nm = (name ?? '').trim()
  if (nm) return nm
  if (num != null && String(num).trim() !== '') return `Team ${padTeam(num)}`
  return ''
}

// neu: zerlegt "mit wem/wo" in (rechts in Zeile 2) und (Teams in Zeile 3)
const splitWith = (a: any) => {
  const roomName: string | null = a?.room?.room_name ?? a?.room_name ?? null


  // Lane
  if (a?.lane) {
    const right = (a?.room?.room_name ?? a?.room_name ?? null) || `Lane ${a.lane}`
    const bottom = teamLabel(a?.team_name, a?.team) || ''  // <-- team_name
    return {right, bottom}
  }

  // Table-Fall
  if (a?.table_1 || a?.table_2) {
    const t1Right = a?.table_1 ? `Tisch ${a.table_1}` : ''
    const t2Right = a?.table_2 ? `Tisch ${a.table_2}` : ''
    const right = [t1Right, t2Right].filter(Boolean).join(' : ')

    const t1Team = a?.table_1
        ? (teamLabel(a?.table_1_team_name, a?.table_1_team) || (a?.table_1_team ? `Team ${padTeam(a.table_1_team)}` : ''))
        : ''
    const t2Team = a?.table_2
        ? (teamLabel(a?.table_2_team_name, a?.table_2_team) || (a?.table_2_team ? `Team ${padTeam(a.table_2_team)}` : ''))
        : ''
    const bottom = [t1Team, t2Team].filter(Boolean).join(' : ')

    return {right, bottom}
  }

  // Sonst: nur Raum rechts, keine Teams unten
  return {right: roomName || '', bottom: ''}
}

onMounted(() => {
  callNow();
  setInterval(callNow, 5 * 60 * 1000); // every 5 minutes
})

</script>

<template>
  <!-- <iframe :srcDoc="data" /> -->
  <!-- <object :data="planUrl"/> -->
  <div class="relative w-full h-full overflow-hidden">

    <FabricSlideContentRenderer v-if="props.content.background"
                                class="absolute inset-0 z-0"
                                :content="props.content" :preview="props.preview"></FabricSlideContentRenderer>

    <div class="z-10 relative" :class="{ 'preview': props.preview }">
      <div v-if="result" class="result">
        <div class="flex flex-row items-center justify-center"
             :class="{ 'min-h-screen': !props.preview, 'min-h-100': props.preview }">
          <PublicPlanTable :result="result"/>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
iframe, object, .result {
  width: 100%;
  height: 100%;
  margin: 0;
  overflow: hidden;
  background: transparent;
}

.preview {
  zoom: 0.15;
  height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.min-h-100 {
  min-height: 100%;
}
</style>
