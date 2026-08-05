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
  <div class="min-w-0 pb-2">
    <p class="text-xs text-[var(--color-text-muted)] mb-3">
      Freie Aktivitäten erscheinen in Plänen und Ausgaben, ändern aber den generierten Ablauf nicht.
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
