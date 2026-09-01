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
  'EventDayShell',
  'Admin',
]

const eventId = computed(() => eventStore.selectedEvent?.id ?? 0)

/** Split-pane pages need bounded height for independent left/right scroll. */
const isFullHeightPage = computed(() => {
  const path = route.path.replace(/\/$/, '')
  return path.includes('/plan/admin')
    || path.includes('/plan/schedule')
    || path === '/plan/publish'
    || path === '/plan/publish/logos'
})

/** Nested Ablauf / Ausgabe / Teams routes share one cache entry so the shell stays mounted. */
const pageKey = computed(() => {
  const path = route.path
  if (path.includes('/plan/schedule')) return `${eventId.value}:schedule`
  if (path.includes('/plan/publish')) return `${eventId.value}:publish`
  if (path.includes('/plan/teams')) return `${eventId.value}:teams`
  if (path.includes('/plan/live')) return `${eventId.value}:live`
  if (path.includes('/plan/admin')) return `${eventId.value}:admin`
  return `${eventId.value}:${path}`
})
</script>

<template>
  <div :class="isFullHeightPage ? 'h-full min-h-0' : undefined">
    <router-view v-slot="{ Component }">
      <keep-alive :include="cachedPages" :max="12">
        <component
            :is="Component"
            v-if="Component"
            :key="pageKey"
            :class="isFullHeightPage ? 'h-full min-h-0' : undefined"
        />
      </keep-alive>
    </router-view>
  </div>
</template>
