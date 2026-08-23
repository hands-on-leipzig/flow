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
  'PublishControl',
  'EventDayControl',
  'Admin',
]

const eventId = computed(() => eventStore.selectedEvent?.id ?? 0)

/** Nested Ablauf / Ausgabe / Teams routes share one cache entry so the shell stays mounted. */
const pageKey = computed(() => {
  const path = route.path
  if (path.includes('/plan/schedule')) return `${eventId.value}:schedule`
  if (path.includes('/plan/publish')) return `${eventId.value}:publish`
  if (path.includes('/plan/teams')) return `${eventId.value}:teams`
  if (path.includes('/plan/admin')) return `${eventId.value}:admin`
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
