<template>
  <div class="glass-modal p-6 w-[90vw] max-w-6xl max-h-[90vh] overflow-auto">
    <h3 class="text-lg font-bold mb-4 text-center">
      <template v-if="timelineModalInfo">
        Generierungen und Veröffentlichung Event {{ timelineModalInfo.event_id }} "{{ timelineModalInfo.event_name }}" - Plan {{ timelineModalInfo.plan_id }}
      </template>
      <template v-else>
        Timeline für Plan {{ planId }}
      </template>
    </h3>
    
    <GeneratorChart :plan-id="planId" />
    
    <div class="flex justify-end gap-2 mt-6">
      <button class="px-4 py-2 text-[var(--color-text-muted)] hover:text-black" @click="$emit('close')">Schließen</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import GeneratorChart from './GeneratorChart.vue'

const props = defineProps<{
  planId: number
  timelineModalInfo: {
    event_name: string | null
    event_id: number | null
    plan_id: number
  } | null
}>()

const emit = defineEmits<{
  (e: 'close'): void
}>()
</script>

