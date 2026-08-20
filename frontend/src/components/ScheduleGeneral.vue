<script setup lang="ts">
import ExploreSettings from '@/components/molecules/ExploreSettings.vue'
import ChallengeSettings from '@/components/molecules/ChallengeSettings.vue'
import TimeSettings from '@/components/molecules/TimeSettings.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import { programId, type EventProgramRef } from '@/utils/eventPrograms'

defineOptions({ name: 'ScheduleGeneral' })

const {
  parameters,
  showExplore,
  showChallenge,
  attachedPrograms,
  lanesIndex,
  supportedPlanData,
  visibilityMap,
  disabledMap,
  handleParamUpdate,
} = useScheduleWorkspace()

function setShowExplore(v: boolean) {
  showExplore.value = v
}
function setShowChallenge(v: boolean) {
  showChallenge.value = v
}

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
          :show-explore="showExplore"
          :show-challenge="showChallenge"
          :lanes-index="lanesIndex"
          :supported-plan-data="supportedPlanData"
          @toggle-show="setShowExplore"
          @update-param="handleParamUpdate"
      />
      <ChallengeSettings
          v-else-if="isChallenge(program)"
          :parameters="parameters"
          :show-challenge="showChallenge"
          :lanes-index="lanesIndex"
          :supported-plan-data="supportedPlanData"
          @toggle-show="setShowChallenge"
          @update-param="handleParamUpdate"
      />
    </template>
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
.schedule-general {
  gap: 1.15rem;
}
</style>
