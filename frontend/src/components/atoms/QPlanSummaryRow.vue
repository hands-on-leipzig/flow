<script setup>
import { computed } from 'vue'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import QPlanDetails from '@/components/atoms/QPlanDetails.vue'
import { useQualityMetrics } from '@/composables/useQualityMetrics'
import {
  evaluationReasonsTooltip,
  evaluationStatusChipClass,
  evaluationStatusLabel,
} from '@/composables/useQualityEvaluationStatus'

const props = defineProps({
  planId: {
    type: Number,
    required: true,
  },
  program: {
    type: Object,
    required: true,
  },
  expanded: {
    type: Boolean,
    default: false,
  },
  expandable: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['toggle'])

const {
  ampelfarbeQ1Q4,
  ampelfarbeQ2,
  ampelfarbeQ3,
  formatDistribution,
  farbeQ5Idle,
  farbeQ5Stddev,
  formatDuration,
} = useQualityMetrics()

const qplan = computed(() => props.program.q_plan)
const isFuture8 = computed(() => props.program.first_program === 8)
const evaluationStatus = computed(() => qplan.value?.evaluation_status ?? null)
const evaluationLabel = computed(() => evaluationStatusLabel(evaluationStatus.value))
const evaluationTooltip = computed(() => evaluationReasonsTooltip(qplan.value))
const metricsMuted = computed(() =>
  evaluationStatus.value === 'not_evaluable' || evaluationStatus.value === 'incomplete',
)

function onRowClick() {
  if (!props.expandable) return
  emit('toggle')
}
</script>

<template>
  <div class="border-b border-[var(--color-border)]">
    <div
      class="grid grid-cols-13 text-sm py-1 items-center"
      :class="[
        expandable ? 'hover:bg-[var(--color-bg-hover)] cursor-pointer' : 'opacity-60',
        metricsMuted ? 'opacity-75' : '',
      ]"
      @click="onRowClick"
    >
      <div class="flex items-center gap-2 pl-4">
        <ProgramLogo :program="program.first_program" size="xs" />
        <span class="text-xs text-[var(--color-text-muted)]">{{ program.label }}</span>
        <span
          v-if="evaluationLabel"
          class="text-[10px] px-1.5 py-0.5 rounded"
          :class="evaluationStatusChipClass(evaluationStatus)"
          :title="evaluationTooltip"
        >
          {{ evaluationLabel }}
        </span>
      </div>

      <template v-if="qplan">
        <div>{{ qplan.c_teams }}</div>
        <div>{{ qplan.j_lanes }}</div>
        <div>{{ qplan.r_tables }}</div>
        <div>{{ qplan.j_rounds }}</div>
        <div>{{ qplan.r_asym ? 'Ja' : 'Nein' }}</div>
        <div>{{ isFuture8 ? '—' : (qplan.r_robot_check ? 'An' : 'Aus') }}</div>
        <div>{{ formatDuration(qplan.q6_duration) }}</div>
        <div class="flex items-center gap-1">
          <span>{{ ampelfarbeQ1Q4(qplan.q1_ok_count, qplan.c_teams) }}</span>
          <span>{{ qplan.q1_ok_count ?? '–' }}</span>
        </div>
        <div class="flex items-center gap-1">
          <span>{{ ampelfarbeQ1Q4(qplan.q4_ok_count, qplan.c_teams) }}</span>
          <span>{{ qplan.q4_ok_count ?? '–' }}</span>
        </div>
        <div class="flex items-center gap-1">
          <span>{{ ampelfarbeQ2(qplan.q2_1_count, qplan.q2_2_count, qplan.r_tables) }}</span>
          <span class="text-xs">{{ formatDistribution(qplan.q2_1_count, qplan.q2_2_count, qplan.q2_3_count, qplan.q2_score_avg) }}</span>
        </div>
        <div class="flex items-center gap-1">
          <span>{{ ampelfarbeQ3(qplan.q3_1_count, qplan.q3_2_count) }}</span>
          <span class="text-xs">{{ formatDistribution(qplan.q3_1_count, qplan.q3_2_count, qplan.q3_3_count, qplan.q3_score_avg) }}</span>
        </div>
        <div class="flex items-center gap-2">
          <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: farbeQ5Idle(qplan.q5_idle_avg, qplan.c_teams) }" />
          <span>{{ qplan.q5_idle_avg ? qplan.q5_idle_avg.toFixed(2) : '–' }}</span>
          <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: farbeQ5Stddev(qplan.q5_idle_stddev) }" />
          <span>{{ qplan.q5_idle_stddev ? qplan.q5_idle_stddev.toFixed(2) : '–' }}</span>
        </div>
      </template>

      <template v-else>
        <div v-for="n in 12" :key="n" class="text-[var(--color-text-subtle)]">—</div>
      </template>
    </div>

    <div
      v-if="expandable && expanded"
      class="bg-[var(--color-bg-muted)] px-2 py-1 border-t border-[var(--color-border)] ml-4"
    >
      <QPlanDetails :plan-id="planId" :first-program="program.first_program" />
    </div>
  </div>
</template>
