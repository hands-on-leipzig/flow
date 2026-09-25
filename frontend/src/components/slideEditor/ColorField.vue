<script setup lang="ts">
import {computed, ref} from 'vue';
import {COLOR_PALETTE, isTransparent, toHexColor} from './constants';

const props = withDefaults(defineProps<{
  label: string
  modelValue: string | null
  allowTransparent?: boolean
}>(), {
  allowTransparent: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();

const open = ref(false);
const transparent = computed(() => isTransparent(props.modelValue));
const hex = computed(() => toHexColor(props.modelValue, '#000000'));

function pick(value: string | null) {
  emit('update:modelValue', value);
}
</script>

<template>
  <div class="color-field">
    <div class="flex items-center justify-between gap-2">
      <span class="se-label">{{ label }}</span>
      <button type="button" class="color-field__trigger" :aria-expanded="open" :title="`${label} wählen`"
              @click="open = !open">
        <span class="color-field__swatch" :class="{ 'is-transparent': transparent }"
              :style="transparent ? {} : { background: modelValue ?? undefined }"></span>
        <span class="text-xs tabular-nums uppercase">{{ transparent ? 'Keine' : hex }}</span>
        <i class="bi text-[10px]" :class="open ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
      </button>
    </div>
    <div v-if="open" class="color-field__palette">
      <button v-if="allowTransparent" type="button" class="color-field__chip is-transparent"
              :class="{ 'is-active': transparent }" title="Keine Farbe" @click="pick(null)"></button>
      <button v-for="color in COLOR_PALETTE" :key="color" type="button" class="color-field__chip"
              :class="{ 'is-active': !transparent && hex === color.toLowerCase() }"
              :style="{ background: color }" :title="color" @click="pick(color)"></button>
      <label class="color-field__chip color-field__custom" title="Eigene Farbe">
        <i class="bi bi-plus-lg"></i>
        <input type="color" :value="hex" @input="pick(($event.target as HTMLInputElement).value)"/>
      </label>
    </div>
  </div>
</template>

<style scoped>
.color-field__trigger {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.25rem 0.5rem 0.25rem 0.3rem;
  border-radius: 8px;
  border: 1px solid var(--color-border-strong);
  background: var(--color-bg-elevated);
  color: var(--color-text);
}

.color-field__trigger:hover {
  background: var(--color-bg-hover);
}

.color-field__swatch {
  width: 1.25rem;
  height: 1.25rem;
  border-radius: 6px;
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.18);
}

.color-field__palette {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 0.35rem;
  margin-top: 0.5rem;
  padding: 0.5rem;
  border-radius: 10px;
  background: var(--color-bg-muted);
  border: 1px solid var(--color-border-strong);
}

.color-field__chip {
  position: relative;
  aspect-ratio: 1;
  border-radius: 6px;
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.18);
  cursor: pointer;
  transition: transform 0.08s ease-out;
}

.color-field__chip:hover {
  transform: scale(1.08);
}

.color-field__chip.is-active {
  outline: 2px solid var(--color-accent);
  outline-offset: 1px;
}

.is-transparent {
  background: linear-gradient(135deg, transparent 45%, var(--color-danger) 45%, var(--color-danger) 55%, transparent 55%), #fff;
}

.color-field__custom {
  display: flex;
  align-items: center;
  justify-content: center;
  background: conic-gradient(#ff7a00, #ffd700, #00a651, #51bfb4, #0066b3, #662d91, #ed1c24, #ff7a00);
  color: #fff;
  overflow: hidden;
}

.color-field__custom i {
  text-shadow: 0 0 3px rgba(0, 0, 0, 0.6);
  pointer-events: none;
}

.color-field__custom input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}
</style>
