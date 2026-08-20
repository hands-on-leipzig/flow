<script setup lang="ts">
import TimeSettings from '@/components/molecules/TimeSettings.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'

defineOptions({ name: 'ScheduleTimes' })

const {
  parameters,
  showExplore,
  showChallenge,
  visibilityMap,
  disabledMap,
  handleParamUpdate,
} = useScheduleWorkspace()
</script>

<template>
  <div class="schedule-times flex flex-col pb-2">
    <TimeSettings
        :parameters="parameters"
        :visibility-map="visibilityMap"
        :disabled-map="disabledMap"
        :show-explore="showExplore"
        :show-challenge="showChallenge"
        @update-param="handleParamUpdate"
    />
  </div>
</template>

<style scoped>
.schedule-times {
  gap: 1.15rem;
}
</style>
