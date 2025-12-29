<script setup lang="ts">
import {onUnmounted, ref} from "vue";

const props = withDefaults(defineProps<{
  message: string,
}>(), {
  message: "Änderungen werden gespeichert...",
});

const visible = ref(false);
let timeoutId: NodeJS.Timeout | undefined = undefined;

function show(durationMs = 2000) {
  visible.value = true;
  // Auto-hide after duration
  if (timeoutId) {
    clearTimeout(timeoutId);
  }
  timeoutId = setTimeout(() => {
    visible.value = false;
  }, durationMs);
}

function hide() {
  visible.value = false;
  if (timeoutId) {
    clearTimeout(timeoutId);
    timeoutId = undefined;
  }
}

onUnmounted(() => {
  if (timeoutId) {
    clearTimeout(timeoutId);
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
  </div>
</template>

<style scoped>

</style>