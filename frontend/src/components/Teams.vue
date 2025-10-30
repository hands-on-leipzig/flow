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

  // Helper function to convert teams object/array to array format
  const normalizeTeams = (teams) => {
    if (!teams) return []
    // If it's already an array, use it
    if (Array.isArray(teams)) {
      return teams.map(t => ({
        number: t.ref || t.number || null, // ref is the team number from DRAHT
        name: t.name || '',
        organization: t.organization || null,
        location: t.location || null,
        id: t.id || null
      }))
    }
    // If it's an object, convert to array
    return Object.values(teams).map(t => ({
      number: t.ref || t.number || null, // ref is the team number from DRAHT
      name: t.name || '',
      organization: t.organization || null,
      location: t.location || null,
      id: t.id || null
    }))
  }

  // teams_explore and teams_challenge can be objects (with team numbers as keys) or arrays
  exploreTeamsDraht.value = normalizeTeams(drahtData.data.teams_explore)
  challengeTeamsDraht.value = normalizeTeams(drahtData.data.teams_challenge)
})
</script>

<template>
  <div class="grid grid-cols-2 gap-4 mt-4">
    <!-- Explore -->
    <div v-if="event?.drahtCapacityExplore > 0">
      <TeamList :remoteTeams="exploreTeamsDraht" program="explore"/>
    </div>

    <!-- Challenge -->
    <div v-if="event?.drahtCapacityChallenge > 0" class="col-start-2">
      <TeamList :remoteTeams="challengeTeamsDraht" program="challenge"/>
    </div>
  </div>
</template>