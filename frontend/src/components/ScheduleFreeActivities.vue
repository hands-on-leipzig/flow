<script setup lang="ts">
import FreeBlocks from '@/components/molecules/FreeBlocks.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import { notifyPlanPreviewReload } from '@/utils/planPreviewSync'

defineOptions({ name: 'ScheduleFreeActivities' })

const {
  selectedEvent,
  selectedPlanId,
  showExplore,
  showChallenge,
  previewReload,
} = useScheduleWorkspace()

function onFreeBlocksChanged() {
  if (!selectedPlanId.value) return
  previewReload.value += 1
  notifyPlanPreviewReload(selectedPlanId.value)
}
</script>

<template>
  <div class="schedule-free flex flex-col min-w-0 pb-2">
    <p class="glass-alert-warning shrink-0 flex items-start gap-2">
      <i class="bi bi-info-circle mt-0.5 shrink-0" aria-hidden="true"/>
      <span>Freie Blöcke erscheinen in Plänen und Ausgaben, ändern aber den generierten Ablauf nicht.</span>
    </p>
    <FreeBlocks
        v-if="selectedPlanId"
        :plan-id="selectedPlanId"
        :event-date="selectedEvent?.date"
        :event-days="selectedEvent?.days"
        :show-explore="showExplore"
        :show-challenge="showChallenge"
        @changed="onFreeBlocksChanged"
    />
  </div>
</template>

<style scoped>
.schedule-free {
  gap: 1.15rem;
}
</style>
