<script setup lang="ts">
import {TeamsMapSlideContent} from "../../../models/teamsMapSlideContent";
import {onMounted, ref} from "vue";
import FabricSlideContentRenderer from "../FabricSlideContentRenderer.vue";
import GenericLeafletMap from "../../molecules/GenericLeafletMap.vue";
import {programLogoAlt, programLogoSrc} from "../../../utils/images";
import {loadCityCoordinates, loadEventPrograms, loadTeamLanes, programForLane, selectedLanes} from "./teamLanes";

const props = withDefaults(defineProps<{
  content: TeamsMapSlideContent,
  preview: boolean,
  eventId: number
}>(), {
  preview: false
});

const coordinates = ref<Array<{lat: number, lon: number, popup: string}> | null>(null);

function escapeHtml(value: string): string {
  return value
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
}

async function loadCoordinates() {
  try {
    const [lanes, eventProgramRows] = await Promise.all([
      loadTeamLanes(props.eventId),
      loadEventPrograms(props.eventId),
    ]);
    const selected = selectedLanes(lanes, props.content.programs);
    const cities = new Set<string>();
    for (const lane of selected) {
      for (const team of lane.teams ?? []) {
        const city = String(team?.location ?? '').trim();
        if (city !== '') {
          cities.add(city);
        }
      }
    }
    const points = await loadCityCoordinates([...cities]);
    const markers: Array<{lat: number, lon: number, popup: string}> = [];

    for (const lane of selected) {
      const program = programForLane(lane, eventProgramRows);
      const teams = Array.isArray(lane.teams) ? lane.teams : [];
      for (const team of teams) {
        const teamName = String(team?.name ?? '').trim();
        const city = String(team?.location ?? '').trim();
        const point = city === '' ? undefined : points[city];
        if (teamName === '' || !point) {
          continue;
        }
        const src = programLogoSrc(program, 'hs');
        const alt = escapeHtml(programLogoAlt(program));
        const name = escapeHtml(teamName);
        markers.push({
          lat: point.lat,
          lon: point.lon,
          popup: `<span style="display:inline-flex;align-items:center;gap:0.4em"><img src="${src}" alt="${alt}" style="height:1em;width:1em;object-fit:contain">${name}</span>`,
        });
      }
    }

    coordinates.value = markers;
  } catch (e) {
    console.error(e);
  }
}

onMounted(loadCoordinates)

</script>

<template>
  <div class="relative w-full h-full overflow-hidden">
    <FabricSlideContentRenderer
        v-if="props.content.background"
        class="absolute inset-0 z-0"
        :content="props.content"
        :preview="props.preview"
    />
    <GenericLeafletMap
        v-if="coordinates"
        :markers="coordinates"
        :height="props.preview ? '9rem' : '100vh'"
        :hideControls="true"
        :static-map="true"
        class="relative z-10 w-full h-full">
    </GenericLeafletMap>
  </div>
</template>

<style scoped>

</style>
