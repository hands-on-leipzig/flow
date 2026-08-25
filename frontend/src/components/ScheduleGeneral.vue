<script setup lang="ts">
import ExploreSettings from '@/components/molecules/ExploreSettings.vue'
import ChallengeSettings from '@/components/molecules/ChallengeSettings.vue'
import Future8Settings from '@/components/molecules/Future8Settings.vue'
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

function isFuture8(program: EventProgramRef): boolean {
  return String(program.name || '').toUpperCase() === 'FUTURE_8'
}
</script>

<template>
  <div class="schedule-general flex flex-col pb-2">
    <p class="glass-alert-warning shrink-0 flex items-start gap-2">
      <i class="bi bi-info-circle mt-0.5 shrink-0" aria-hidden="true"/>
      <span>Für Anpassungen der Kapazitäten pro Programm, bitte in der Geschäftstelle melden.</span>
    </p>
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
      <Future8Settings
          v-else-if="isFuture8(program)"
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
