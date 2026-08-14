<script setup lang="ts">
import ExploreSettings from '@/components/molecules/ExploreSettings.vue'
import ChallengeSettings from '@/components/molecules/ChallengeSettings.vue'
import TimeSettings from '@/components/molecules/TimeSettings.vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'

defineOptions({ name: 'ScheduleGeneral' })

const {
  parameters,
  showExplore,
  showChallenge,
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
</script>

<template>
  <div class="schedule-general flex flex-col pb-2">
    <ExploreSettings
        :parameters="parameters"
        :show-explore="showExplore"
        :show-challenge="showChallenge"
        :lanes-index="lanesIndex"
        :supported-plan-data="supportedPlanData"
        @toggle-show="setShowExplore"
        @update-param="handleParamUpdate"
    />
    <ChallengeSettings
        :parameters="parameters"
        :show-challenge="showChallenge"
        :lanes-index="lanesIndex"
        :supported-plan-data="supportedPlanData"
        @toggle-show="setShowChallenge"
        @update-param="handleParamUpdate"
    />
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
