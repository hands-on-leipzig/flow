<script setup lang="ts">
import {onUnmounted, ref} from "vue";

const props = withDefaults(defineProps<{
  message: string,
}>(), {
  message: "Änderungen werden gespeichert...",
});

const visible = ref(false);
const progress = ref(100);
let intervalId: NodeJS.Timeout | undefined = undefined;

function startProgress(duration = 2000) {
  if (intervalId) {
    clearInterval(intervalId);
  }
  progress.value = 100;
  const step = 100 / (duration / 50);

  intervalId = setInterval(() => {
    progress.value -= step;
    if (progress.value <= 0) {
      progress.value = 0;
      if (intervalId) {
        clearInterval(intervalId);
        intervalId = null;
      }
      // kurz sichtbar lassen, dann ausblenden
      setTimeout(() => { visible.value = false; }, 220);
    }
  }, 50);
}

function show(durationMs = 2000) {
  visible.value = true;
  startProgress(durationMs);
}

function hide() {
  visible.value = false;
  if (intervalId) {
    clearInterval(intervalId);
    intervalId = null
  }
  progress.value = 100;
}

onUnmounted(() => {
  if (intervalId) {
    clearInterval(intervalId);
  }
});

defineExpose({ show, hide });
</script>

<template>
  <div v-if="visible"
       class="fixed top-4 right-4 z-50 bg-green-50 border border-green-200 rounded-lg shadow-lg p-4 min-w-80 max-w-md">
    <div class="flex items-center gap-3">
      <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
      <span class="text-green-800 font-medium"> {{ message }}</span>
    </div>
    <div class="mt-3 bg-green-200 rounded-full h-2 overflow-hidden">
      <div class="bg-green-500 h-full transition-all duration-75 ease-linear"
           :style="{ width: progress + '%' }"></div>
    </div>
  </div>
</template>

<style scoped>

</style>