<script setup lang="ts">
import {TeamsMapSlideContent} from "../../../models/teamsMapSlideContent";
import {onMounted, ref} from "vue";
import axios from "axios";
import FabricSlideContentRenderer from "../FabricSlideContentRenderer.vue";
import GenericLeafletMap from "../../molecules/GenericLeafletMap.vue";
import {programLogoAlt, programLogoSrc} from "../../../utils/images";
import {loadEventPrograms, loadTeamLanes, programForLane, selectedLanes} from "./teamLanes";

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
    const [lanes, eventProgramRows, response] = await Promise.all([
      loadTeamLanes(props.eventId),
      loadEventPrograms(props.eventId),
      axios.get(`/events/${props.eventId}/team-coordinates`),
    ]);
    const points = Array.isArray(response.data) ? response.data : [];
    const markers: Array<{lat: number, lon: number, popup: string}> = [];

    for (const lane of selectedLanes(lanes, props.content.programs)) {
      const program = programForLane(lane, eventProgramRows);
      const teams = Array.isArray(lane.teams) ? lane.teams : [];
      for (const team of teams) {
        const teamName = String(team?.name ?? '').trim();
        if (teamName === '') {
          continue;
        }
        const point = points.find((candidate) => {
          const pointName = String(candidate?.name ?? '').trim();
          return pointName !== ''
              && Number(candidate?.program_id) === Number(lane.program_id)
              && pointName === teamName;
        });
        if (!point?.coord) {
          continue;
        }
        const src = programLogoSrc(program, 'h');
        const alt = escapeHtml(programLogoAlt(program));
        const name = escapeHtml(teamName);
        markers.push({
          lat: point.coord.lat,
          lon: point.coord.lon,
          popup: `<span style="display:inline-flex;align-items:center;gap:0.4em"><img src="${src}" alt="${alt}" style="height:1em;width:auto">${name}</span>`,
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
