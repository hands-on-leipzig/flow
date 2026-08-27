<script setup lang="ts">
/**
 * Ablauf → Integration: program-coupling tiles (Explore ↔ others, Challenge ↔ Future 8+).
 */
import {computed} from 'vue'
import ExploreIntegration from '@/components/molecules/ExploreIntegration.vue'
import ChallengeFutureIntegration from '@/components/molecules/ChallengeFutureIntegration.vue'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {programId} from '@/utils/eventPrograms'

const EXPLORE_ID = 2

defineOptions({name: 'ScheduleIntegration'})

const {
  parameters,
  attachedPrograms,
  handleParamUpdate,
} = useScheduleWorkspace()

function programName(row: {name?: string | null}): string {
  return String(row.name || '').toUpperCase()
}

/** Explore tile: Explore attached and at least one other program (coupling target). */
const showExploreIntegration = computed(() => {
  const programs = attachedPrograms.value
  const exploreOn = programs.some((p) => programName(p) === 'EXPLORE')
  if (!exploreOn) return false
  return programs.some((p) => programId(p) !== EXPLORE_ID)
})

/** Challenge + Future 8+ tile: both attached (Future 8+ only, not Future 5+). */
const showChallengeFutureIntegration = computed(() => {
  const programs = attachedPrograms.value
  const challengeOn = programs.some((p) => programName(p) === 'CHALLENGE')
  const future8On = programs.some((p) => programName(p) === 'FUTURE_8')
  return challengeOn && future8On
})

const hasAnyTile = computed(() =>
    showExploreIntegration.value || showChallengeFutureIntegration.value
)
</script>

<template>
  <div class="schedule-integration flex flex-col pb-2">
    <ExploreIntegration
        v-if="showExploreIntegration"
        :parameters="parameters"
        @update-param="handleParamUpdate"
    />
    <ChallengeFutureIntegration
        v-if="showChallengeFutureIntegration"
        :parameters="parameters"
        @update-param="handleParamUpdate"
    />
    <p
        v-if="!hasAnyTile"
        class="glass-settings-hint !not-italic"
    >
      Für dieses Event sind noch keine Integrations-Einstellungen verfügbar.
    </p>
  </div>
</template>

<style scoped>
.schedule-integration {
  gap: 1.15rem;
}
</style>
