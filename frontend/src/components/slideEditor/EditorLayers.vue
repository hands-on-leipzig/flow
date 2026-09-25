<script setup lang="ts">
import draggable from 'vuedraggable';
import {nextTick, ref, watch} from 'vue';
import type {LayerItem} from './selection';

const props = defineProps<{
  layers: LayerItem[]
  activeIds: number[]
}>();

const emit = defineEmits<{
  select: [id: number, additive: boolean]
  'toggle-visible': [id: number]
  'toggle-lock': [id: number]
  reorder: [id: number, displayIndex: number]
  rename: [id: number, name: string]
}>();

const list = ref<LayerItem[]>([]);
watch(() => props.layers, layers => {
  list.value = [...layers];
}, {immediate: true});

function onEnd(event: { oldIndex: number, newIndex: number }) {
  if (event.oldIndex === event.newIndex) return;
  emit('reorder', list.value[event.newIndex].id, event.newIndex);
}

const editingId = ref<number | null>(null);
let editInput: HTMLInputElement | null = null;

async function startRename(layer: LayerItem) {
  editingId.value = layer.id;
  await nextTick();
  editInput?.select();
}

function finishRename(layer: LayerItem, event: Event) {
  if (editingId.value !== layer.id) return;
  editingId.value = null;
  const name = (event.target as HTMLInputElement).value.trim();
  if (name !== layer.label) emit('rename', layer.id, name);
}
</script>

<template>
  <div class="layers">
    <p v-if="!list.length" class="se-hint px-4 py-6 text-center">
      Noch keine Elemente. Füge über die Werkzeugleiste Text, Formen oder Bilder hinzu.
    </p>
    <draggable v-else v-model="list" item-key="id" handle=".layers__grip" :animation="150" tag="ul"
               class="layers__list" @end="onEnd">
      <template #item="{ element: layer }">
        <li class="layers__item" :class="{ 'is-active': activeIds.includes(layer.id), 'is-hidden': !layer.visible }"
            @click="emit('select', layer.id, $event.shiftKey || $event.metaKey || $event.ctrlKey)">
          <i class="bi bi-grip-vertical layers__grip" title="Ziehen zum Umsortieren"></i>
          <i class="bi layers__icon" :class="layer.icon"></i>
          <input v-if="editingId === layer.id" :ref="el => editInput = el as HTMLInputElement | null" class="layers__rename" :value="layer.label"
                 @click.stop @keydown.enter="($event.target as HTMLInputElement).blur()"
                 @keydown.esc="editingId = null" @blur="finishRename(layer, $event)"/>
          <span v-else class="layers__label" title="Doppelklick zum Umbenennen" @dblclick.stop="startRename(layer)">
            {{ layer.label }}
          </span>
          <button type="button" class="layers__toggle" :class="{ 'is-on': layer.locked }"
                  :title="layer.locked ? 'Entsperren' : 'Sperren'" @click.stop="emit('toggle-lock', layer.id)">
            <i class="bi" :class="layer.locked ? 'bi-lock-fill' : 'bi-unlock'"></i>
          </button>
          <button type="button" class="layers__toggle" :class="{ 'is-on': !layer.visible }"
                  :title="layer.visible ? 'Ausblenden' : 'Einblenden'" @click.stop="emit('toggle-visible', layer.id)">
            <i class="bi" :class="layer.visible ? 'bi-eye' : 'bi-eye-slash'"></i>
          </button>
        </li>
      </template>
    </draggable>
    <p v-if="list.length" class="se-hint px-4 pb-3">
      Oben in der Liste = vorne auf der Folie. Umschalt + Klick wählt mehrere Elemente aus.
    </p>
  </div>
</template>

<style scoped>
.layers__list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 0.5rem;
}

.layers__item {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-height: 2.25rem;
  padding: 0 0.35rem 0 0.25rem;
  border-radius: 8px;
  cursor: pointer;
  color: var(--color-text);
  transition: background 0.1s ease;
}

.layers__item:hover {
  background: var(--color-bg-hover);
}

.layers__item.is-active {
  background: var(--color-accent-soft);
  box-shadow: inset 0 0 0 1px var(--color-accent-muted);
}

.layers__item.is-hidden .layers__label,
.layers__item.is-hidden .layers__icon {
  opacity: 0.45;
}

.layers__grip {
  cursor: grab;
  color: var(--color-text-subtle);
}

.layers__icon {
  width: 1.5rem;
  height: 1.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  background: var(--color-bg-muted);
  box-shadow: inset 0 0 0 1px var(--color-border-strong);
  font-size: 0.8rem;
}

.layers__label {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.85rem;
}

.layers__rename {
  flex: 1;
  min-width: 0;
  font-size: 0.85rem;
  padding: 0.15rem 0.35rem;
  border-radius: 6px;
  border: 1px solid var(--color-accent);
  background: var(--color-bg-elevated);
  color: var(--color-text);
  outline: none;
}

.layers__toggle {
  width: 1.75rem;
  height: 1.75rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  color: var(--color-text-subtle);
  opacity: 0;
  transition: opacity 0.1s ease;
}

.layers__item:hover .layers__toggle,
.layers__toggle.is-on {
  opacity: 1;
}

.layers__toggle:hover {
  background: var(--color-bg-muted);
  color: var(--color-text);
}
</style>
