<script setup>
import { computed } from 'vue'
import { useEventStore } from '@/stores/event'
import { useRoute } from 'vue-router'
import { imageUrl } from '@/utils/images'
import { BANNER_STYLES } from '@/constants/styles'

const eventStore = useEventStore()
const route = useRoute()

const isPublicRoute = computed(() => {
  return route.meta?.public === true
})

const shouldShowBanner = computed(() => {
  // Don't show on public routes
  if (isPublicRoute.value) return false
  
  // Need event with date
  if (!eventStore.selectedEvent?.date) return false
  
  const eventDate = new Date(eventStore.selectedEvent.date)
  const today = new Date()
  
  // Reset to midnight for date-only comparison
  eventDate.setHours(0, 0, 0, 0)
  today.setHours(0, 0, 0, 0)
  
  // Calculate days difference
  const diffTime = eventDate.getTime() - today.getTime()
  const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24))
  
  // Show if event is today, tomorrow, or day after tomorrow (0, 1, or 2 days away)
  return diffDays >= 0 && diffDays <= 2
})

const pdfUrl = computed(() => {
  return imageUrl('/flow/FLOW - Wenn Teams am Tag der Veranstaltung nicht erscheinen.pdf')
})
</script>

<template>
  <div 
    v-if="shouldShowBanner"
    :class="[BANNER_STYLES.warning, 'border-b px-4 py-3 w-full']"
  >
    <div class="max-w-7xl mx-auto">
      <p class="text-sm">
        Kurzanleitung zum Thema: 
        <a 
          :href="pdfUrl" 
          target="_blank" 
          rel="noopener noreferrer"
          class="text-blue-600 hover:text-blue-800 hover:underline font-medium"
        >
          Wenn Teams am Tag der Veranstaltung nicht erscheinen.
        </a>
      </p>
    </div>
  </div>
</template>
