<script setup lang="ts">
import SlotBlocks from '@/components/molecules/SlotBlocks.vue'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {notifyPlanPreviewReload} from '@/utils/planPreviewSync'

defineOptions({name: 'ScheduleSlotActivities'})

const {
  selectedEvent,
  selectedPlanId,
  previewReload,
} = useScheduleWorkspace()

function onSlotBlocksChanged() {
  if (!selectedPlanId.value) return
  previewReload.value += 1
  notifyPlanPreviewReload(selectedPlanId.value)
}
</script>

<template>
  <div class="schedule-slots flex flex-col min-w-0 pb-2">
    <SlotBlocks
        v-if="selectedPlanId"
        :plan-id="selectedPlanId"
        :event-date="selectedEvent?.date"
        @changed="onSlotBlocksChanged"
    />
  </div>
</template>

<style scoped>
.schedule-slots {
  gap: 1.15rem;
}
</style>
