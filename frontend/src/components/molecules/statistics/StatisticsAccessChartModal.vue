<template>
  <div class="glass-modal stats-plan-modal p-6 max-h-[90vh] overflow-auto">
    <h3 class="text-lg font-bold mb-4">
      Zugriffe für Event {{ eventId }}
    </h3>
    
    <AccessChart :event-id="eventId" />
    
    <div class="flex justify-end gap-2 mt-6">
      <button class="px-4 py-2 text-[var(--color-text-muted)] hover:text-black" @click="$emit('close')">Schließen</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import AccessChart from './AccessChart.vue'

defineProps<{
  eventId: number
  eventName?: string
}>()

defineEmits<{
  (e: 'close'): void
}>()
</script>
