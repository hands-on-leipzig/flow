<script lang="ts" setup>
import {computed, ref} from 'vue'

const props = defineProps<{
  total: number
  leftTeams: number
  onUpdate: (leftTeams: number) => void
}>()

const trackRef = ref<HTMLElement | null>(null)
const dragging = ref(false)

const rightTeams = computed(() => Math.max(0, props.total - props.leftTeams))

const handlePct = computed(() => {
  if (props.total <= 0) return 0
  return (props.leftTeams / props.total) * 100
})

const labelOnLeft = computed(() => handlePct.value > 50)

function clampLeft(value: number): number {
  return Math.min(props.total, Math.max(0, Math.round(value)))
}

function valueFromClientX(clientX: number): number {
  const track = trackRef.value
  if (!track || props.total <= 0) return props.leftTeams
  const rect = track.getBoundingClientRect()
  if (rect.width <= 0) return props.leftTeams
  const ratio = (clientX - rect.left) / rect.width
  return clampLeft(ratio * props.total)
}

function commit(value: number) {
  if (value === props.leftTeams) return
  props.onUpdate(value)
}

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
</script>

<template>
  <div class="plan-bar">
    <div
        ref="trackRef"
        class="plan-bar__track liquid-surface-inner"
        aria-hidden="true"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
    >
      <div class="plan-bar__fill plan-bar__fill--left" :style="{ width: handlePct + '%' }"/>
      <div class="plan-bar__fill plan-bar__fill--right" :style="{ width: (100 - handlePct) + '%' }"/>
      <span class="plan-bar__end plan-bar__end--min">Vormittag {{ leftTeams }}</span>
      <span class="plan-bar__end plan-bar__end--max">Nachmittag {{ rightTeams }}</span>
    </div>
    <div
        class="plan-bar__handle"
        :class="{ 'is-dragging': dragging, 'is-flipped': labelOnLeft }"
        :style="{ left: handlePct + '%' }"
        role="slider"
        tabindex="0"
        :aria-valuemin="0"
        :aria-valuemax="total"
        :aria-valuenow="leftTeams"
        aria-label="Teamverteilung"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="onPointerUp"
    >
      <div class="plan-bar__needle">
        <span class="plan-bar__triangle"/>
        <span class="plan-bar__line"/>
      </div>
      <span class="plan-bar__value glass-chip liquid-surface-inner">Teamverteilung</span>
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
  cursor: ew-resize;
  touch-action: none;
  user-select: none;
}

.plan-bar__fill {
  position: absolute;
  pointer-events: none;
}

.plan-bar__fill--left {
  inset: 0 auto 0 0;
  background: color-mix(in srgb, #1e40af 22%, transparent);
}

.plan-bar__fill--right {
  inset: 0 0 0 auto;
  background: color-mix(in srgb, #93c5fd 55%, transparent);
}

.plan-bar__end {
  position: absolute;
  top: 50%;
  z-index: 1;
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
  padding: 0.12rem 0.45rem !important;
  font-size: 0.75rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  line-height: 1.2;
  color: var(--color-text);
  white-space: nowrap;
}

.plan-bar__handle.is-flipped .plan-bar__value {
  left: auto;
  right: 0.45rem;
}
</style>
