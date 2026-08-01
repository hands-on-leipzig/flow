<script setup lang="ts">
import {computed} from 'vue'
import {useEventStore} from '@/stores/event'

const eventStore = useEventStore()

/** Pages that should stay mounted while switching tabs within the same event. */
const cachedPages = [
  'HomeOverview',
  'EventOverview',
  'Schedule',
  'Teams',
  'Rooms',
  'Logos',
  'Slots',
  'PublishControl',
  'EventDayControl',
]

const eventId = computed(() => eventStore.selectedEvent?.id ?? 0)
</script>

<template>
  <router-view v-slot="{ Component, route }">
    <keep-alive :include="cachedPages" :max="12">
      <component
          :is="Component"
          v-if="Component"
          :key="`${eventId}:${route.path}`"
      />
    </keep-alive>
  </router-view>
</template>
