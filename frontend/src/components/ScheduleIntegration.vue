<script setup lang="ts">
/**
 * Ablauf → Integration: program-coupling tiles (Challenge ↔ Future 8+, later more).
 */
import {computed} from 'vue'
import ChallengeFutureIntegration from '@/components/molecules/ChallengeFutureIntegration.vue'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {hasChallenge, hasFuture} from '@/utils/eventPrograms'
import {useEventStore} from '@/stores/event'

defineOptions({name: 'ScheduleIntegration'})

const eventStore = useEventStore()

const {
  parameters,
  handleParamUpdate,
} = useScheduleWorkspace()

const hasChallengeAndFuture = computed(() =>
    hasChallenge(eventStore.selectedEvent) && hasFuture(eventStore.selectedEvent)
)
</script>

<template>
  <div class="schedule-integration flex flex-col pb-2">
    <ChallengeFutureIntegration
        v-if="hasChallengeAndFuture"
        :parameters="parameters"
        @update-param="handleParamUpdate"
    />
    <p
        v-else
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
