<script setup lang="ts">
import {TeamsMapSlideContent} from "../../models/teamsMapSlideContent";
import {onMounted, ref} from "vue";
import axios from "axios";
import FabricSlideContentRenderer from "./FabricSlideContentRenderer.vue";
import GenericLeafletMap from "../molecules/GenericLeafletMap.vue";

const props = withDefaults(defineProps<{
  content: TeamsMapSlideContent,
  preview: boolean,
  eventId: number
}>(), {
  preview: false
});

const coordinates = ref(null);

async function loadCoordinates() {
  try {
    const response = await axios.get(`events/${props.eventId}/team-coordinates`);
    coordinates.value = response.data.map(team => ({lat: team.coord.lat, long: team.coord.long, popup: team.name}));
  } catch (e) {
    console.error(e);
  }
  /*coordinates.value = [
    {lat: 48.18, lon: 12.2833, popup: "GarsControl Senior"},
    {lat: 50.5517, lon: 9.6832, popup: "1337.exe"},
    {lat: 48.06488, lon: 11.6632, popup: "Here We GO"}
  ];*/
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
        :height="props.preview ? '134px' : '100vh'"
        :hideControls="true"
        :static-map="true"
        class="relative z-10 w-full h-full">
    </GenericLeafletMap>
  </div>
</template>

<style scoped>

</style>