<script setup>
import axios from "axios";

async function syncDrahtRegions() {
  await axios.get("/draht/sync-draht-regions")
}

async function syncDrahtEvents() {
  await axios.get(`/draht/sync-draht-events/2`)
}
</script>

<template>
  <button class="px-4 py-2 rounded bg-blue-500 text-white mb-4" @click="syncDrahtRegions()">Sync draht-regions</button>

  <button class="px-4 py-2 rounded bg-blue-500 text-white mb-4" @click="syncDrahtEvents()">Sync Draht</button>
</template>

<style scoped>

</style>