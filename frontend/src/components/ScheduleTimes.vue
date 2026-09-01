<script setup lang="ts">
import CeremonyTimesList from '@/components/molecules/CeremonyTimesList.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'

defineOptions({ name: 'ScheduleTimes' })

const {
  parameters,
  selectedPlanId,
  planLocked,
  previewReload,
  disabledMap,
  handleParamUpdate,
} = useScheduleWorkspace()
</script>

<template>
  <div class="schedule-times flex flex-col pb-2">
    <p class="glass-alert-warning shrink-0 flex items-start gap-2">
      <i class="bi bi-info-circle mt-0.5 shrink-0" aria-hidden="true"/>
      <span>Startzeiten werden zum Teil automatisch bestimmt. Die Dauern können selber gewählt werden.</span>
    </p>
    <CeremonyTimesList
        :plan-id="selectedPlanId"
        :parameters="parameters"
        :disabled-map="disabledMap"
        :plan-locked="planLocked"
        :reload-token="previewReload"
        @update-param="handleParamUpdate"
    />
  </div>
</template>

<style scoped>
.schedule-times {
  gap: 1.15rem;
}
</style>
