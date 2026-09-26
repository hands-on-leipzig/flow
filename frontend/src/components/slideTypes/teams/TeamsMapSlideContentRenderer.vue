<script setup lang="ts">
import {TeamsMapSlideContent} from "../../../models/teamsMapSlideContent";
import {
  loadCityCoordinates,
  loadEventPrograms,
  loadTeamLanes,
  loadVenuePoint,
  programForLane,
  selectedLanes,
  type TeamLane
} from "./teamLanes";
import type {EventProgramRef} from "../../../utils/eventPrograms";
import {computed, onMounted, ref} from "vue";
import FabricSlideContentRenderer from "../FabricSlideContentRenderer.vue";
import GenericLeafletMap from "../../molecules/GenericLeafletMap.vue";
import {programLogoAlt, programLogoSrc} from "../../../utils/images";
import {sameOriginSrc} from "../../../utils/sameOriginSrc";

const props = withDefaults(defineProps<{
  content: TeamsMapSlideContent,
  preview: boolean,
  eventId: number
}>(), {
  preview: false
});

const coordinates = ref<Array<{lat: number, lon: number, label: string}> | null>(null);

const MAX_GEOCODE_ROUNDS = 12;

const backgroundSrc = computed(() => slideBackgroundSrc(props.content.background));

function slideBackgroundSrc(background: unknown): string {
  let value = background;
  if (typeof value === 'string') {
    try {
      value = JSON.parse(value);
    } catch {
      return '';
    }
  }
  if (!value || typeof value !== 'object') {
    return '';
  }
  const src = (value as {backgroundImage?: {src?: unknown}}).backgroundImage?.src;
  return typeof src === 'string' ? sameOriginSrc(src) : '';
}

function escapeHtml(value: string): string {
  return value
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#39;');
}

function venueMarker(point: {lat: number, lon: number}) {
  return {
    lat: point.lat,
    lon: point.lon,
    venue: true,
    label: '<div class="map-pin-label__box"><span class="map-pin-label__row">Wir sind hier</span></div>',
  };
}

function teamMarkers(lanes: TeamLane[], programRows: EventProgramRef[], points: Record<string, {lat: number, lon: number}>) {
  const byCity = new Map();

  for (const lane of lanes) {
    const program = programForLane(lane, programRows);
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

  return [...byCity.values()].map((city) => ({
    lat: city.lat,
    lon: city.lon,
    label: `<div class="map-pin-label__box">${city.rows.join('')}</div>`,
  }));
}

async function loadCoordinates() {
  const venuePromise = loadVenuePoint(props.eventId);
  let venue: {lat: number, lon: number} | null = null;
  venuePromise.then((point) => {
    venue = point;
    if (point && coordinates.value) {
      coordinates.value = [...coordinates.value, venueMarker(point)];
    }
  });

  const show = (markers: Array<{lat: number, lon: number, label: string}>) => {
    coordinates.value = venue ? [...markers, venueMarker(venue)] : markers;
  };

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

    // The backend answers as many towns as its rate limit allows and reports the
    // rest, so a big event fills the map over a few rounds instead of timing out.
    const points: Record<string, {lat: number, lon: number}> = {};
    let pending = [...cities];
    for (let round = 0; round < MAX_GEOCODE_ROUNDS && pending.length > 0; round++) {
      const resolved = await loadCityCoordinates(pending);
      Object.assign(points, resolved.points);
      show(teamMarkers(selected, eventProgramRows, points));
      pending = resolved.missing;
    }
    if (!coordinates.value) {
      show([]);
    }
  } catch (e) {
    console.error(e);
    await venuePromise;
    show([]);
  }
}

onMounted(loadCoordinates)

</script>

<template>
  <div class="relative w-full h-full overflow-hidden">
    <img v-if="backgroundSrc" :src="backgroundSrc" alt="" class="map-backdrop"/>
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
    <FabricSlideContentRenderer
        v-if="props.content.background"
        class="map-foreground"
        overlay
        :content="props.content"
        :preview="props.preview"
    />
  </div>
</template>

<style scoped>
.map-backdrop {
  position: absolute;
  inset: 0;
  z-index: 1;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
  pointer-events: none;
}

.map-slide {
  position: absolute;
  z-index: 10;
  left: 10%;
  right: 10%;
  top: 10%;
  bottom: 10%;
  box-sizing: border-box;
  border: 8px solid #fff;
  overflow: hidden;
  background: #fff;
}

.map-foreground {
  position: absolute;
  inset: 0;
  z-index: 20;
  pointer-events: none;
}

.map-slide :deep(.leaflet-container) {
  background: transparent;
}

.map-slide :deep(.map-pin-label__row) {
  display: flex;
  align-items: center;
  gap: 0.35em;
  white-space: nowrap;
}

.map-slide :deep(.map-pin-label__row img) {
  width: 1.25rem;
  height: 1.25rem;
  object-fit: contain;
  flex-shrink: 0;
}
</style>
