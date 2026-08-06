<script setup lang="ts">
import {computed} from 'vue'
import {useRoute} from 'vue-router'
import {useEventStore} from '@/stores/event'

const eventStore = useEventStore()
const route = useRoute()

/** Pages that should stay mounted while switching tabs within the same event. */
const cachedPages = [
  'HomeOverview',
  'Schedule',
  'Teams',
  'Rooms',
  'Logos',
  'PublishControl',
  'EventDayControl',
]

const eventId = computed(() => eventStore.selectedEvent?.id ?? 0)

/** Nested Ablauf / Ausgabe routes share one cache entry so the shell stays mounted. */
const pageKey = computed(() => {
  const path = route.path
  if (path.includes('/plan/schedule')) return `${eventId.value}:schedule`
  if (path.includes('/plan/publish')) return `${eventId.value}:publish`
  return `${eventId.value}:${path}`
})
</script>

<template>
  <router-view v-slot="{ Component }">
    <keep-alive :include="cachedPages" :max="12">
      <component
          :is="Component"
          v-if="Component"
          :key="pageKey"
      />
    </keep-alive>
  </router-view>
</template>
