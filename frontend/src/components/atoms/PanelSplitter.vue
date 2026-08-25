<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'

const props = withDefaults(defineProps<{
  /** Current left pane width in % (0–100) */
  modelValue?: number
  min?: number
  max?: number
  storageKey?: string
}>(), {
  modelValue: 50,
  min: 28,
  max: 72,
  storageKey: 'flow-schedule-split',
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: number): void
}>()

const dragging = ref(false)
const root = ref<HTMLElement | null>(null)

function clamp(value: number) {
  return Math.min(props.max, Math.max(props.min, value))
}

function persist(value: number) {
  if (!props.storageKey) return
  try {
    localStorage.setItem(props.storageKey, String(value))
  } catch {
    /* ignore */
  }
}

function readInitial(): number {
  if (props.storageKey) {
    try {
      const raw = localStorage.getItem(props.storageKey)
      if (raw != null) {
        const n = Number(raw)
        if (Number.isFinite(n)) return clamp(n)
      }
    } catch {
      /* ignore */
    }
  }
  return clamp(props.modelValue)
}

emit('update:modelValue', readInitial())

function onPointerDown(event: PointerEvent) {
  dragging.value = true
  ;(event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId)
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', onPointerUp)
  window.addEventListener('pointercancel', onPointerUp)
}

function onPointerMove(event: PointerEvent) {
  if (!dragging.value || !root.value) return
  const parent = root.value.parentElement
  if (!parent) return
  const rect = parent.getBoundingClientRect()
  if (rect.width <= 0) return
  const pct = clamp(((event.clientX - rect.left) / rect.width) * 100)
  emit('update:modelValue', pct)
}

function onPointerUp() {
  dragging.value = false
  persist(props.modelValue)
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', onPointerUp)
  window.removeEventListener('pointercancel', onPointerUp)
}

function nudge(delta: number) {
  const next = clamp(props.modelValue + delta)
  emit('update:modelValue', next)
  persist(next)
}

onBeforeUnmount(() => {
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', onPointerUp)
  window.removeEventListener('pointercancel', onPointerUp)
})
</script>

<template>
  <div
      ref="root"
      class="panel-splitter"
      :class="{ 'panel-splitter--dragging': dragging }"
      role="separator"
      aria-orientation="vertical"
      aria-label="Bereichsbreite anpassen"
      title="Ziehen, um die Breite anzupassen"
      :aria-valuenow="Math.round(modelValue)"
      :aria-valuemin="min"
      :aria-valuemax="max"
      tabindex="0"
      @pointerdown="onPointerDown"
      @keydown.left.prevent="nudge(-2)"
      @keydown.right.prevent="nudge(2)"
  >
    <span class="panel-splitter__rail" aria-hidden="true"/>
    <span class="panel-splitter__handle" aria-hidden="true">
      <i class="bi bi-grip-vertical"/>
    </span>
  </div>
</template>

<style scoped>
.panel-splitter {
  flex: 0 0 0.9rem;
  position: relative;
  cursor: col-resize;
  touch-action: none;
  user-select: none;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 3;
}

.panel-splitter__rail {
  position: absolute;
  top: 0.75rem;
  bottom: 0.75rem;
  left: 50%;
  width: 1px;
  transform: translateX(-50%);
  background: color-mix(in srgb, var(--color-border-strong) 55%, transparent);
  transition: background 0.12s ease, width 0.12s ease, box-shadow 0.12s ease;
}

.panel-splitter__handle {
  position: relative;
  z-index: 1;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.15rem;
  height: 2.35rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 70%, transparent);
  background: color-mix(in srgb, var(--liquid-bg-strong, #fff) 92%, transparent);
  color: var(--color-text-muted);
  font-size: 0.95rem;
  line-height: 1;
  box-shadow:
    0 1px 2px rgba(15, 23, 42, 0.06),
    0 4px 10px rgba(15, 23, 42, 0.06),
    inset 0 1px 0 rgba(255, 255, 255, 0.85);
  transition:
    color 0.12s ease,
    background 0.12s ease,
    border-color 0.12s ease,
    box-shadow 0.12s ease,
    transform 0.12s ease;
}

.panel-splitter:hover .panel-splitter__rail,
.panel-splitter--dragging .panel-splitter__rail,
.panel-splitter:focus-visible .panel-splitter__rail {
  width: 2px;
  background: color-mix(in srgb, var(--color-accent) 75%, transparent);
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--color-accent) 20%, transparent);
}

.panel-splitter:hover .panel-splitter__handle,
.panel-splitter--dragging .panel-splitter__handle,
.panel-splitter:focus-visible .panel-splitter__handle {
  color: var(--color-accent);
  border-color: color-mix(in srgb, var(--color-accent) 55%, var(--color-border));
  background: #fff;
  transform: scale(1.06);
  box-shadow:
    0 2px 4px rgba(15, 23, 42, 0.08),
    0 6px 14px rgba(15, 23, 42, 0.1),
    inset 0 1px 0 rgba(255, 255, 255, 1);
}

.panel-splitter--dragging .panel-splitter__handle {
  cursor: col-resize;
}

.panel-splitter:focus-visible {
  outline: none;
}
</style>
