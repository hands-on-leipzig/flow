<script lang="ts" setup>
import {computed, ref, watch} from 'vue'

const props = defineProps<{
  planTeams: number
  registeredTeams: number
  /** Venue capacity (DRAHT or override) — label + enrolled fill only. */
  capacity: number
  minTeams: number
  /** Hard ceiling for the plan slider (parent already applies capacity). */
  maxTeams: number
  onUpdate: (value: number) => void
}>()

const trackRef = ref<HTMLElement | null>(null)
const dragging = ref(false)

const maxPlan = computed(() => {
  const maxT = Number(props.maxTeams)
  if (!Number.isFinite(maxT) || maxT <= 0) return Math.max(1, Number(props.minTeams) || 1)
  return maxT
})

const scale = computed(() => Math.max(maxPlan.value, 1))

const fillPct = computed(() => {
  if (props.capacity <= 0) return 0
  return (props.registeredTeams / props.capacity) * 100
})

const handlePct = computed(() => Math.min(100, (props.planTeams / scale.value) * 100))

const labelOnLeft = computed(() => handlePct.value > 50)

const overEnrolled = computed(() => props.registeredTeams > props.planTeams)

const planMismatch = computed(() => props.planTeams !== props.registeredTeams)

function clampPlan(value: number): number {
  return Math.min(maxPlan.value, Math.max(props.minTeams, Math.round(value)))
}

function valueFromClientX(clientX: number): number {
  const track = trackRef.value
  if (!track) return props.planTeams
  const rect = track.getBoundingClientRect()
  if (rect.width <= 0) return props.planTeams
  const ratio = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width))
  return clampPlan(ratio * scale.value)
}

function commit(value: number) {
  if (value === props.planTeams) return
  props.onUpdate(value)
}

watch(
    () => [props.planTeams, props.minTeams, props.maxTeams] as const,
    () => {
      const next = clampPlan(props.planTeams)
      if (next !== props.planTeams) commit(next)
    },
    {immediate: true}
)

function onPointerDown(event: PointerEvent) {
  if (event.button !== 0) return
  event.preventDefault()
  dragging.value = true
  ;(event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId)
  commit(valueFromClientX(event.clientX))
}

function onPointerMove(event: PointerEvent) {
  if (!dragging.value) return
  commit(valueFromClientX(event.clientX))
}

function onPointerUp() {
  dragging.value = false
}

function onTrackPointerDown(event: PointerEvent) {
  if (event.button !== 0) return
  event.preventDefault()
  dragging.value = true
  ;(event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId)
  commit(valueFromClientX(event.clientX))
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
    event.preventDefault()
    commit(clampPlan(props.planTeams - 1))
  } else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
    event.preventDefault()
    commit(clampPlan(props.planTeams + 1))
  } else if (event.key === 'Home') {
    event.preventDefault()
    commit(clampPlan(props.minTeams))
  } else if (event.key === 'End') {
    event.preventDefault()
    commit(clampPlan(maxPlan.value))
  }
}
</script>

<template>
  <div class="plan-bar">
    <div
        ref="trackRef"
        class="plan-bar__track liquid-surface-inner"
        @pointerdown="onTrackPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
    >
      <div class="plan-bar__plan" :style="{ width: handlePct + '%' }"/>
      <div
          class="plan-bar__fill"
          :class="{ 'plan-bar__fill--over': overEnrolled }"
          :style="{ width: fillPct + '%' }"
      />
      <span class="plan-bar__end plan-bar__end--min">Angemeldet {{ registeredTeams }}</span>
      <span class="plan-bar__end plan-bar__end--max">Kapazität {{ capacity }}</span>
    </div>
    <div
        class="plan-bar__handle"
        :class="{ 'is-dragging': dragging, 'is-flipped': labelOnLeft }"
        :style="{ left: handlePct + '%' }"
        role="slider"
        tabindex="0"
        :aria-valuemin="minTeams"
        :aria-valuemax="maxPlan"
        :aria-valuenow="planTeams"
        :aria-label="`Plan für ${planTeams} Teams`"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
        @keydown="onKeydown"
    >
      <div class="plan-bar__needle">
        <span class="plan-bar__triangle"/>
        <span class="plan-bar__line"/>
      </div>
      <span class="plan-bar__value glass-chip liquid-surface-inner">
        Plan für {{ planTeams }} Teams
        <span
            v-if="planMismatch"
            class="plan-bar__warning"
            title="Angemeldete Teams weichen vom Plan ab"
        />
      </span>
    </div>
  </div>
</template>

<style scoped>
.plan-bar {
  position: relative;
  padding-top: 1.35rem;
  overflow: visible;
}

.plan-bar__track {
  position: relative;
  height: 2.15rem;
  overflow: hidden;
  cursor: pointer;
  touch-action: none;
}

.plan-bar__plan {
  position: absolute;
  inset: 0 auto 0 0;
  background: color-mix(in srgb, var(--color-text) 12%, transparent);
  pointer-events: none;
}

.plan-bar__fill {
  position: absolute;
  inset: 0 auto 0 0;
  z-index: 1;
  background: #fef5e7;
  pointer-events: none;
}

.plan-bar__fill--over {
  background: color-mix(in srgb, #dc2626 16%, transparent);
}

.plan-bar__end {
  position: absolute;
  top: 50%;
  z-index: 2;
  transform: translateY(-50%);
  font-size: 0.8125rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  line-height: 1;
  color: var(--color-text);
  pointer-events: none;
}

.plan-bar__end--min {
  left: 0.55rem;
}

.plan-bar__end--max {
  right: 0.55rem;
}

.plan-bar__handle {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 0;
  z-index: 2;
  cursor: grab;
  touch-action: none;
  user-select: none;
}

.plan-bar__handle.is-dragging {
  cursor: grabbing;
}

.plan-bar__handle:focus-visible {
  outline: none;
}

.plan-bar__handle:focus-visible .plan-bar__triangle {
  filter: drop-shadow(0 0 0 2px color-mix(in srgb, var(--color-accent) 45%, transparent));
}

.plan-bar__needle {
  display: flex;
  flex-direction: column;
  align-items: center;
  height: 100%;
  width: 12px;
  margin-left: -6px;
}

.plan-bar__triangle {
  flex-shrink: 0;
  width: 0;
  height: 0;
  border-left: 6px solid transparent;
  border-right: 6px solid transparent;
  border-top: 8px solid var(--color-text);
}

.plan-bar__line {
  flex: 1 1 auto;
  width: 1.5px;
  background: var(--color-text);
}

.plan-bar__value {
  position: absolute;
  top: 0;
  left: 0.45rem;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.12rem 0.45rem !important;
  font-size: 0.75rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
  color: var(--color-text);
  white-space: nowrap;
}

.plan-bar__warning {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 9999px;
  background: #ef4444;
  flex-shrink: 0;
}

.plan-bar__handle.is-flipped .plan-bar__value {
  left: auto;
  right: 0.45rem;
}
</style>
