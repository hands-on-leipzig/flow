<script setup>

import TeamList from "@/components/molecules/TeamList.vue";
import {computed, onMounted, ref} from "vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const exploreTeamsDraht = ref([])
const challengeTeamsDraht = ref([])

onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  const drahtData = await axios.get(`/events/${event.value?.id}/draht-data`)

  exploreTeamsDraht.value = Object.entries(drahtData.data.teams_explore || {}).map(([id, t]) => ({
    id: Number(id),
    number: id,
    name: t.name
  }))

  challengeTeamsDraht.value = Object.entries(drahtData.data.teams_challenge || {}).map(([id, t]) => ({
    id: Number(id),
    number: id,
    name: t.name
  }))
})
</script>

<template>
  <div class="grid grid-cols-2 gap-4 mt-4">
    <div v-if="exploreTeamsDraht.length">
      <TeamList :remoteTeams="exploreTeamsDraht" :program="'explore'"/>
    </div>
    <div v-if="challengeTeamsDraht.length">
      <TeamList :remoteTeams="challengeTeamsDraht" :program="'challenge'"/>
    </div>
  </div>
</template>

<style scoped>

</style>