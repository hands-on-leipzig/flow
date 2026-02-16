<script setup>
import TeamList from "@/components/molecules/TeamList.vue";
import {computed, onMounted, ref} from "vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";
import LoaderFlow from "@/components/atoms/LoaderFlow.vue";
import LoaderText from "@/components/atoms/LoaderText.vue";

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const exploreTeamsDraht = ref([])
const challengeTeamsDraht = ref([])
const loading = ref(true)


onMounted(async () => {
  loading.value = true
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  const drahtData = await axios.get(`/events/${event.value?.id}/draht-data`)

  // Helper function to convert teams object/array to array format
  const normalizeTeams = (teams) => {
    if (!teams) return []
    // If it's already an array, use it
    if (Array.isArray(teams)) {
      return teams.map(t => ({
        // ref is the team number from DRAHT - use nullish coalescing to handle ref=0 (valid)
        number: t.ref ?? t.number ?? null,
        name: t.name || '',
        organization: t.organization || null,
        location: t.location || null,
        id: t.id || null
      }))
    }
    // If it's an object, convert to array
    return Object.values(teams).map(t => ({
      // ref is the team number from DRAHT - use nullish coalescing to handle ref=0 (valid)
      number: t.ref ?? t.number ?? null,
      name: t.name || '',
      organization: t.organization || null,
      location: t.location || null,
      id: t.id || null
    }))
  }

  // teams_explore and teams_challenge can be objects (with team numbers as keys) or arrays
  exploreTeamsDraht.value = normalizeTeams(drahtData.data.teams_explore)
  challengeTeamsDraht.value = normalizeTeams(drahtData.data.teams_challenge)
  loading.value = false
})
</script>

<template>
  <div v-if="loading" class="flex items-center justify-center h-full flex-col text-gray-600 min-h-[400px]">
    <LoaderFlow/>
    <LoaderText/>
  </div>
  <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
    <!-- Explore -->
    <div v-if="event?.drahtCapacityExplore > 0">
      <TeamList :remoteTeams="exploreTeamsDraht" program="explore"/>
    </div>

    <!-- Challenge -->
    <div v-if="event?.drahtCapacityChallenge > 0">
      <TeamList :remoteTeams="challengeTeamsDraht" program="challenge"/>
    </div>
  </div>
</template>