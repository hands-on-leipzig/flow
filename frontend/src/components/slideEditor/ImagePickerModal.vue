<script setup lang="ts">
import {ref} from 'vue';

interface PickerImage {
  title?: string
  url?: string
  content?: string
}

withDefaults(defineProps<{
  title: string
  images: PickerImage[]
  allowUpload?: boolean
  uploading?: boolean
}>(), {
  allowUpload: false,
  uploading: false,
});

const emit = defineEmits<{
  pick: [image: PickerImage]
  upload: [file: File]
  close: []
}>();

const fileInput = ref<HTMLInputElement | null>(null);
const dragOver = ref(false);

function onFile(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (file) emit('upload', file);
  if (fileInput.value) fileInput.value.value = '';
}

function onDrop(event: DragEvent) {
  dragOver.value = false;
  const file = event.dataTransfer?.files?.[0];
  if (file) emit('upload', file);
}
</script>

<template>
  <Teleport to="body">
    <div class="glass-scrim fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="emit('close')"
         @keydown.esc="emit('close')">
      <div class="glass-modal glass-modal-lg w-full max-w-3xl">
        <div class="glass-modal-header flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">{{ title }}</h2>
          <button type="button" class="text-2xl leading-none opacity-80 hover:opacity-100" title="Schließen"
                  @click="emit('close')">&times;
          </button>
        </div>

        <div v-if="allowUpload" class="upload-drop" :class="{ 'is-over': dragOver }"
             @dragover.prevent="dragOver = true" @dragleave="dragOver = false" @drop.prevent="onDrop">
          <i class="bi bi-cloud-arrow-up text-2xl"></i>
          <div class="text-sm">
            <span v-if="uploading">Wird hochgeladen…</span>
            <template v-else>
              Bild hierher ziehen oder
              <button type="button" class="underline font-medium" @click="fileInput?.click()">Datei auswählen</button>
              <span class="block text-xs opacity-70">PNG, JPG, SVG – max. 2 MB. Wird in den Logos gespeichert.</span>
            </template>
          </div>
          <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onFile"/>
        </div>

        <div class="picker-grid">
          <button v-for="(img, index) in images" :key="(img.url ?? img.content ?? '') + index" type="button"
                  class="picker-grid__item" :title="img.title" @click="emit('pick', img)">
            <span class="picker-grid__thumb">
              <img :src="img.content || img.url" :alt="img.title ?? ''" loading="lazy"/>
            </span>
            <span class="picker-grid__title">{{ img.title }}</span>
          </button>
        </div>

        <div class="glass-modal-footer flex items-center justify-between gap-3">
          <router-link v-if="allowUpload" to="/plan/publish/logos" class="text-sm">
            <i class="bi bi-box-arrow-up-right"></i> Logos verwalten
          </router-link>
          <span v-else></span>
          <button type="button" class="glass-btn-secondary" @click="emit('close')">Abbrechen</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.upload-drop {
  display: flex;
  align-items: center;
  gap: 0.9rem;
  margin-bottom: 1rem;
  padding: 0.9rem 1.1rem;
  border-radius: var(--radius);
  border: 1.5px dashed var(--color-border-strong);
  background: var(--color-bg-muted);
  color: var(--color-text-muted);
  transition: border-color 0.12s ease, background 0.12s ease;
}

.upload-drop.is-over {
  border-color: var(--color-accent);
  background: var(--color-accent-soft);
}

.picker-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr));
  gap: 0.75rem;
  max-height: 55vh;
  overflow-y: auto;
  padding: 0.25rem;
}

.picker-grid__item {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.5rem;
  border-radius: 12px;
  border: 1px solid transparent;
  text-align: center;
  transition: background 0.12s ease, border-color 0.12s ease, transform 0.08s ease-out;
}

.picker-grid__item:hover {
  background: var(--color-bg-hover);
  border-color: var(--color-accent-muted);
  transform: translateY(-1px);
}

.picker-grid__thumb {
  display: flex;
  align-items: center;
  justify-content: center;
  aspect-ratio: 4 / 3;
  padding: 0.5rem;
  border-radius: 8px;
  background: repeating-conic-gradient(#f1f5f9 0% 25%, #ffffff 0% 50%) 50% / 16px 16px;
  box-shadow: inset 0 0 0 1px var(--color-border-strong);
}

.picker-grid__thumb img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.picker-grid__title {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
