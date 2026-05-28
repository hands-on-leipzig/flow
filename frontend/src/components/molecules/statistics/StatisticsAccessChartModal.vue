<template>
  <div class="glass-modal p-6 w-[90vw] max-w-6xl max-h-[90vh] overflow-auto">
    <h3 class="text-lg font-bold mb-4 text-center">
      One-Link Zugriffe für Event {{ eventId }} "{{ eventName }}"
    </h3>
    
    <AccessChart :event-id="eventId" />
    
    <div class="flex justify-end gap-2 mt-6">
      <button class="px-4 py-2 text-[var(--color-text-muted)] hover:text-black" @click="$emit('close')">Schließen</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import AccessChart from './AccessChart.vue'

const props = defineProps<{
  eventId: number
  eventName: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
}>()
</script>

