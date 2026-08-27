<script setup lang="ts">
/**
 * Ablauf → Integration: program-coupling tiles (Explore ↔ others, Challenge ↔ Future 8+).
 */
import {computed} from 'vue'
import ExploreIntegration from '@/components/molecules/ExploreIntegration.vue'
import ChallengeFutureIntegration from '@/components/molecules/ChallengeFutureIntegration.vue'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {
  eventPrograms,
  hasChallenge,
  hasExplore,
  hasFuture,
  programId,
} from '@/utils/eventPrograms'
import {useEventStore} from '@/stores/event'

const EXPLORE_ID = 2

defineOptions({name: 'ScheduleIntegration'})

const eventStore = useEventStore()

const {
  parameters,
  handleParamUpdate,
} = useScheduleWorkspace()

const showExploreIntegration = computed(() =>
    hasExplore(eventStore.selectedEvent)
    && eventPrograms(eventStore.selectedEvent).some((program) => programId(program) !== EXPLORE_ID)
)

const hasChallengeAndFuture = computed(() =>
    hasChallenge(eventStore.selectedEvent) && hasFuture(eventStore.selectedEvent)
)

const hasAnyTile = computed(() =>
    showExploreIntegration.value || hasChallengeAndFuture.value
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
        v-if="hasChallengeAndFuture"
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
