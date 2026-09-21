<template>
  <div class="glass-modal stats-plan-modal p-6 max-h-[90vh] overflow-auto">
    <h3 class="text-lg font-bold mb-4">
      Generierungen für Plan {{ planId }}
    </h3>
    
    <GeneratorChart :plan-id="planId" />
    
    <div class="flex justify-end gap-2 mt-6">
      <button class="px-4 py-2 text-[var(--color-text-muted)] hover:text-black" @click="$emit('close')">Schließen</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import GeneratorChart from './GeneratorChart.vue'

defineProps<{
  planId: number
  timelineModalInfo?: {
    event_name: string | null
    event_id: number | null
    plan_id: number
  } | null
}>()

defineEmits<{
  (e: 'close'): void
}>()
</script>
