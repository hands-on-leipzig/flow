<script lang="ts" setup>
import {watch} from 'vue'

const props = defineProps<{
  show: boolean
  url: string
  title: string
  mode?: 'pdf' | 'image'
}>()

const emit = defineEmits<{
  close: []
}>()

function onBackdropClick(e: MouseEvent) {
  if (e.target === e.currentTarget) emit('close')
}

watch(
  () => props.show,
  (visible) => {
    document.body.style.overflow = visible ? 'hidden' : ''
  },
)
</script>

<template>
  <Teleport to="body">
    <Transition name="doc-viewer-fade">
      <div
          v-if="show"
          class="fixed inset-0 z-50 flex flex-col bg-black/60 p-0 sm:p-8"
          role="dialog"
          aria-modal="true"
          :aria-label="title || 'Dokument'"
          @click="onBackdropClick"
      >
        <div
            class="flex flex-1 min-h-0 flex-col bg-white sm:rounded-lg shadow-xl overflow-hidden"
            @click.stop
        >
          <div class="flex items-center justify-between gap-4 px-4 py-3 border-b border-gray-200 shrink-0">
            <span v-if="title" class="font-semibold text-gray-900 truncate">{{ title }}</span>
            <button
                type="button"
                class="ml-auto text-gray-400 hover:text-gray-600 shrink-0"
                aria-label="Schließen"
                @click="emit('close')"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
              </svg>
            </button>
          </div>
          <div class="flex-1 min-h-0 flex flex-col">
            <iframe
                v-if="url && (mode === 'pdf' || !mode)"
                :src="url"
                class="flex-1 w-full min-h-0 border-0"
                :title="title || 'PDF'"
            />
            <img
                v-else-if="url && mode === 'image'"
                :src="url"
                :alt="title"
                class="flex-1 w-full min-h-0 object-contain bg-gray-50"
            />
            <p v-else class="p-8 text-gray-500">Kein Dokument verfügbar.</p>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.doc-viewer-fade-enter-active,
.doc-viewer-fade-leave-active {
  transition: opacity 0.2s ease;
}

.doc-viewer-fade-enter-from,
.doc-viewer-fade-leave-to {
  opacity: 0;
}
</style>
