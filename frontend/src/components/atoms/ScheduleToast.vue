<script setup lang="ts">
import { computed } from "vue";

const props = withDefaults(defineProps<{
  message?: string,
  countdown?: number | null,
  isGenerating?: boolean,
  onImmediateSave?: (() => void) | undefined,
  /** Ablauf regenerates the plan; extra activities only refresh it. */
  action?: 'generate' | 'update',
}>(), {
  message: "Änderungen werden gespeichert…",
  countdown: null,
  isGenerating: false,
  onImmediateSave: undefined,
  action: 'generate',
});

const actionLabel = computed(() => props.action === 'update' ? 'Aktualisieren' : 'Generieren')
const busyLabel = computed(() => props.action === 'update' ? 'Zeitplan wird aktualisiert' : 'Ablauf wird generiert')

const displayCountdownText = computed(() => {
  const seconds = props.countdown;
  if (seconds === null || seconds === undefined || seconds <= 0) return null;
  // Two-digit format (e.g., "03", "02", "01")
  return String(Math.max(0, Math.ceil(seconds))).padStart(2, '0');
});

const visible = computed(() => {
  return props.countdown !== null && props.countdown !== undefined && props.countdown > 0 || props.isGenerating;
});

const isClickable = computed(() => {
  return !props.isGenerating && displayCountdownText.value !== null && props.onImmediateSave !== undefined;
});

function handleClick() {
  if (isClickable.value && props.onImmediateSave) {
    props.onImmediateSave();
  }
}
</script>

<template>
  <Teleport to="body">
    <div
        v-show="visible"
        class="schedule-toast"
    >
      <button
          type="button"
          :class="isGenerating ? 'glass-btn-secondary' : 'glass-btn-accent'"
          :disabled="!isClickable"
          @click="handleClick"
      >
        <div v-if="isGenerating" class="flex items-center gap-2">
          <span class="schedule-toast__pulse" aria-hidden="true"/>
          <span>{{ busyLabel }}</span>
        </div>
        <template v-else-if="displayCountdownText !== null">
          <span class="schedule-toast__count">{{ displayCountdownText }}</span>
          <span>{{ actionLabel }}</span>
        </template>
        <template v-else>
          <span>{{ message }}</span>
        </template>
      </button>
    </div>
  </Teleport>
</template>

<style scoped>
.schedule-toast {
  position: fixed;
  top: 1rem;
  right: 1rem;
  z-index: 200;
  min-width: 12rem;
}

.schedule-toast__count {
  font-size: 1.5rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  line-height: 1;
}

.schedule-toast__pulse {
  width: 0.75rem;
  height: 0.75rem;
  border-radius: 9999px;
  background: currentColor;
  animation: schedule-toast-pulse 1s ease-in-out infinite;
}

@keyframes schedule-toast-pulse {
  0%, 100% { opacity: 0.35; }
  50% { opacity: 1; }
}
</style>
