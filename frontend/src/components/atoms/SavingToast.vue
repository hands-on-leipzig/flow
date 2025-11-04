<script setup lang="ts">
import { computed } from "vue";

const props = withDefaults(defineProps<{
  message?: string,
  countdown?: number | null,
  isGenerating?: boolean,
  onImmediateSave?: (() => void) | undefined,
}>(), {
  message: "Änderungen werden gespeichert...",
  countdown: null,
  isGenerating: false,
  onImmediateSave: undefined,
});

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

const buttonClass = computed(() => {
  if (props.isGenerating) {
    return "bg-gray-300 text-gray-600 cursor-not-allowed";
  }
  return "bg-green-500 hover:bg-green-600 text-white cursor-pointer";
});

function handleClick() {
  if (isClickable.value && props.onImmediateSave) {
    props.onImmediateSave();
  }
}

// Legacy API support (for components that still use show/hide)
function show() {
  // This is now handled via props.countdown
}
function hide() {
  // This is now handled via props.countdown
}

defineExpose({ show, hide });
</script>

<template>
  <div v-show="visible"
       class="fixed top-4 right-4 z-50 min-w-48">
    <button
      :class="[
        'w-full rounded-lg shadow-lg px-4 py-3 font-medium transition-colors flex items-center justify-center gap-3',
        buttonClass
      ]"
      :disabled="!isClickable"
      @click="handleClick"
    >
      <div v-if="isGenerating" class="flex items-center gap-2">
        <div class="w-3 h-3 bg-gray-600 rounded-full animate-pulse"></div>
        <span>Plan wird generiert</span>
      </div>
      <template v-else-if="displayCountdownText !== null">
        <span class="text-2xl font-bold font-mono">{{ displayCountdownText }}</span>
        <span>Generieren</span>
      </template>
      <template v-else>
        <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
        <span>{{ message }}</span>
      </template>
    </button>
  </div>
</template>

<style scoped>
</style>
