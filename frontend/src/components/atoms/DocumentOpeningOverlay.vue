<script lang="ts" setup>
import Spinner from '@/components/atoms/Spinner.vue'

defineProps<{
  open: boolean
  fileName?: string
}>()
</script>

<template>
  <Teleport to="body">
    <Transition name="doc-opening-fade">
      <div
          v-if="open"
          class="fixed inset-0 z-[10100] flex items-center justify-center bg-black/45 backdrop-blur-[2px] p-4"
          role="status"
          aria-live="polite"
          aria-busy="true"
      >
        <div class="flex flex-col items-center gap-3 max-w-xs px-6 py-5 text-center bg-white rounded-lg shadow-xl border border-[var(--color-border)]">
          <Spinner size="lg"/>
          <p class="text-sm font-semibold text-gray-900 m-0">Dokument wird geladen…</p>
          <p v-if="fileName" class="text-xs text-gray-500 m-0 truncate max-w-full">{{ fileName }}</p>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.doc-opening-fade-enter-active,
.doc-opening-fade-leave-active {
  transition: opacity 0.15s ease;
}

.doc-opening-fade-enter-from,
.doc-opening-fade-leave-to {
  opacity: 0;
}
</style>
