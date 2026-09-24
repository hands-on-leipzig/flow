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

const coordinates = ref<Array<{lat: number, lon: number, label: string}> | null>(null);

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
    const byCity = new Map();

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
        if (!byCity.has(city)) {
          byCity.set(city, {lat: point.lat, lon: point.lon, rows: []});
        }
        const src = programLogoSrc(program);
        const alt = escapeHtml(programLogoAlt(program));
        const name = escapeHtml(teamName);
        byCity.get(city).rows.push(
            `<span class="map-pin-label__row"><img src="${src}" alt="${alt}">${name}</span>`
        );
      }
    }

    coordinates.value = [...byCity.values()].map((city) => ({
      lat: city.lat,
      lon: city.lon,
      label: city.rows.join(''),
    }));
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
    <div class="map-slide">
      <GenericLeafletMap
          v-if="coordinates"
          :markers="coordinates"
          height="100%"
          min-height="0"
          :hideControls="true"
          :static-map="true"
          class="w-full h-full">
      </GenericLeafletMap>
    </div>
  </div>
</template>

<style scoped>
.map-slide {
  position: absolute;
  z-index: 10;
  left: 10%;
  right: 10%;
  top: 10%;
  bottom: 10%;
}

.map-slide :deep(.map-pin-label__row) {
  display: flex;
  align-items: center;
  gap: 0.35em;
}

.map-slide :deep(.map-pin-label__row img) {
  width: 1.25rem;
  height: 1.25rem;
  object-fit: contain;
  flex-shrink: 0;
}
</style>
