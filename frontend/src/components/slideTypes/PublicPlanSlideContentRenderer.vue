<script setup lang="ts">
import {onMounted, ref} from 'vue';
import {PublicPlanSlideContent} from "@/models/publicPlanSlideContent";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";
import axios from "axios";
import {formatTimeOnly} from "@/utils/dateTimeFormat";

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
  loading.value = true
  result.value = null
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

    result.value = data
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
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
        <!-- Eine Spalte pro Activity-Group -->
        <div class="flex flex-row items-center justify-center"
             :class="{ 'min-h-screen': !props.preview, 'min-h-100': props.preview }">
          <div
              v-for="g in (result.groups || [])"
              :key="g.activity_group_id"
              class="border rounded-lg bg-white mx-4 shadow-sm overflow-hidden"
          >
            <!-- Group-Header -->
            <div class="px-3 py-2 bg-gray-50 border-b">
              <div class="flex items-start gap-2">
                <!-- Program Icon -->
                <img
                    v-if="g.group_meta?.first_program_id === 2"
                    src="@/assets/FLL_Explore.png"
                    alt="FIRST LEGO League Explore Logo"
                    class="w-10 h-10 flex-shrink-0"
                />
                <img
                    v-else-if="g.group_meta?.first_program_id === 3"
                    src="@/assets/FLL_Challenge.png"
                    alt="FIRST LEGO League Challenge Logo"
                    class="w-10 h-10 flex-shrink-0"
                />

                <!-- Textbereich -->
                <div class="flex-1">
                  <div class="text-sm font-semibold">
                    {{ g.group_meta?.name || ('Group #' + g.activity_group_id) }}
                  </div>
                  <div v-if="g.group_meta?.description" class="text-xs text-break text-gray-500 mt-0.5 max-w-64">
                    {{ g.group_meta.description }}
                  </div>
                </div>
              </div>
            </div>
            <!-- Activities der Gruppe -->
            <ul class="divide-y">
              <li
                  v-for="a in (g.activities || [])"
                  :key="a.activity_id"
                  class="px-3 py-2"
              >
                <!-- Zeile 1: Activity-Name (kleiner) -->
                <div class="text-sm text-gray-700 font-medium">
                  {{ a.meta?.name || a.activity_name || ('Activity #' + a.activity_id) }}
                </div>

                <!-- Zeile 2: Zeit fett links, rechts Ort/Tische nicht fett -->
                <div class="mt-0.5 flex items-baseline justify-between gap-3">
                  <div class="text-base font-semibold whitespace-nowrap">
                    {{ formatTimeOnly(a.start_time, true) }}–{{ formatTimeOnly(a.end_time, true) }}
                  </div>
                  <div class="text-base text-gray-700">
                    {{ splitWith(a).right }}
                  </div>
                </div>

                <!-- Zeile 3: Teams (Lane: ein Team; Tables: Team A : Team B). Sonst leer -->
                <div v-if="splitWith(a).bottom" class="mt-0.5 text-base text-gray-800">
                  {{ splitWith(a).bottom }}
                </div>
              </li>

              <li v-if="!g.activities || g.activities.length === 0" class="px-3 py-3 text-xs text-gray-500">
                Keine Aktivitäten in dieser Gruppe.
              </li>
            </ul>

          </div>
          <!-- <div v-if="!result.groups || result.groups.length === 0" class="mt-4 text-center text-gray-500">
            Keine passenden Aktivitäten.
          </div> -->
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
