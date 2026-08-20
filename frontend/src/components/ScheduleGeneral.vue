<script setup lang="ts">
import ExploreSettings from '@/components/molecules/ExploreSettings.vue'
import ChallengeSettings from '@/components/molecules/ChallengeSettings.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import { programId, type EventProgramRef } from '@/utils/eventPrograms'

defineOptions({ name: 'ScheduleGeneral' })

const {
  parameters,
  attachedPrograms,
  lanesIndex,
  supportedPlanData,
  handleParamUpdate,
} = useScheduleWorkspace()

function isExplore(program: EventProgramRef): boolean {
  return String(program.name || '').toUpperCase() === 'EXPLORE'
}

function isChallenge(program: EventProgramRef): boolean {
  return String(program.name || '').toUpperCase() === 'CHALLENGE'
}
</script>

<template>
  <div class="schedule-general flex flex-col pb-2">
    <template v-for="program in attachedPrograms" :key="programId(program)">
      <ExploreSettings
          v-if="isExplore(program)"
          :parameters="parameters"
          :lanes-index="lanesIndex"
          :supported-plan-data="supportedPlanData"
          @update-param="handleParamUpdate"
      />
      <ChallengeSettings
          v-else-if="isChallenge(program)"
          :parameters="parameters"
          :lanes-index="lanesIndex"
          :supported-plan-data="supportedPlanData"
          @update-param="handleParamUpdate"
      />
    </template>
  </div>
</template>

<style scoped>
.schedule-general {
  gap: 1.15rem;
}
</style>
