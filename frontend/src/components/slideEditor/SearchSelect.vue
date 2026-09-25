<script setup lang="ts">
import {computed, nextTick, ref} from 'vue';
import {Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions} from '@headlessui/vue';

interface SearchSelectOption {
  value: string | number
  label: string
  description?: string
  icon?: string
  keywords?: string
  badges?: { icon: string, title: string }[]
}

const props = withDefaults(defineProps<{
  modelValue: string | number | null | undefined
  options: SearchSelectOption[]
  placeholder?: string
  emptyText?: string
}>(), {
  placeholder: 'Suchen…',
  emptyText: 'Keine Treffer',
});

const emit = defineEmits<{ 'update:modelValue': [value: string | number] }>();

const query = ref('');

const selected = computed(() => props.options.find(o => o.value === props.modelValue) ?? null);

// Case- and accent-insensitive, so "halle" finds "Halle", "turnhalle" and "Hallé".
function normalize(value: string) {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

const filtered = computed(() => {
  const terms = normalize(query.value).split(/\s+/).filter(Boolean);
  if (!terms.length) return props.options;
  return props.options.filter(o => {
    const haystack = normalize([o.label, o.description, o.keywords].filter(Boolean).join(' '));
    return terms.every(t => haystack.includes(t));
  });
});

function pick(option: SearchSelectOption | null) {
  if (option) emit('update:modelValue', option.value);
}

const inputRef = ref<{ $el: HTMLInputElement } | null>(null);
const buttonRef = ref<{ $el: HTMLButtonElement } | null>(null);

// Every opening starts with the full list and the shown selection highlighted, so typing replaces it.
function resetSearch() {
  query.value = '';
  nextTick(() => inputRef.value?.$el?.select());
}

function openFromInput(open: boolean) {
  if (open) return;
  resetSearch();
  buttonRef.value?.$el?.click();
}
</script>

<template>
  <Combobox :model-value="selected" by="value" nullable @update:model-value="pick" v-slot="{ open }">
    <div class="search-select" :class="{ 'is-open': open }">
      <div class="search-select__control">
        <i class="bi search-select__lead" :class="open ? 'bi-search' : (selected?.icon ?? 'bi-search')"></i>
        <ComboboxInput ref="inputRef"
                       class="search-select__input"
                       :display-value="(option: any) => option?.label ?? ''"
                       :placeholder="placeholder"
                       autocomplete="off"
                       @click="openFromInput(open)"
                       @keydown.down="!open && resetSearch()"
                       @change="query = ($event.target as HTMLInputElement).value"/>
        <ComboboxButton ref="buttonRef" class="search-select__chevron" title="Liste öffnen"
                        @click="!open && resetSearch()">
          <i class="bi bi-chevron-expand"></i>
        </ComboboxButton>
      </div>

      <ComboboxOptions class="search-select__options">
        <li v-if="!filtered.length" class="search-select__empty">
          <i class="bi bi-search"></i> {{ emptyText }}
        </li>
        <ComboboxOption v-for="option in filtered" :key="option.value" :value="option" as="template"
                        v-slot="{ active, selected: isSelected }">
          <li class="search-select__option" :class="{ 'is-active': active, 'is-selected': isSelected }">
            <span class="search-select__icon"><i class="bi" :class="option.icon ?? 'bi-dot'"></i></span>
            <span class="search-select__text">
              <span class="search-select__label">{{ option.label }}</span>
              <span v-if="option.description" class="search-select__description">{{ option.description }}</span>
            </span>
            <i v-for="badge in option.badges ?? []" :key="badge.icon" class="bi search-select__badge"
               :class="badge.icon" :title="badge.title"></i>
            <i v-if="isSelected" class="bi bi-check-lg search-select__check"></i>
          </li>
        </ComboboxOption>
      </ComboboxOptions>
    </div>
  </Combobox>
</template>

<style>
.search-select__control {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  height: 2.25rem;
  padding: 0 0.6rem;
  border-radius: 8px;
  border: 1px solid var(--color-border-strong);
  background: var(--color-bg-elevated);
  cursor: text;
  transition: border-color 0.1s ease, box-shadow 0.1s ease;
}

.search-select__control:hover {
  border-color: var(--color-accent-muted);
}

.search-select.is-open .search-select__control,
.search-select__control:focus-within {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px var(--color-accent-soft);
}

.search-select__lead {
  color: var(--color-accent);
  font-size: 0.9rem;
}

.search-select__input {
  flex: 1;
  min-width: 0;
  border: 0;
  background: transparent;
  font-size: 0.85rem;
  color: var(--color-text);
  outline: none;
}

.search-select__input::placeholder {
  color: var(--color-text-subtle);
}

.search-select__chevron {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  margin-right: -0.25rem;
  border-radius: 6px;
  font-size: 0.8rem;
  color: var(--color-text-subtle);
}

.search-select__chevron:hover {
  background: var(--color-bg-hover);
  color: var(--color-text);
}

.search-select__options {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 15rem;
  margin-top: 0.35rem;
  padding: 0.3rem;
  overflow-y: auto;
  scrollbar-width: thin;
  border-radius: 10px;
  border: 1px solid var(--color-border-strong);
  background: var(--color-bg-elevated);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}

.search-select__option {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.4rem 0.5rem;
  border-radius: 7px;
  cursor: pointer;
  color: var(--color-text);
}

.search-select__option.is-active {
  background: var(--color-bg-hover);
}

.search-select__option.is-selected {
  background: var(--color-accent-soft);
}

.search-select__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 7px;
  background: var(--color-bg-muted);
  box-shadow: inset 0 0 0 1px var(--color-border-strong);
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.search-select__option.is-selected .search-select__icon {
  color: var(--color-accent);
}

.search-select__text {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.search-select__label {
  font-size: 0.85rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-select__description {
  font-size: 0.72rem;
  color: var(--color-text-subtle);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-select__badge {
  font-size: 0.85rem;
  color: var(--color-text-subtle);
}

.search-select__check {
  color: var(--color-accent);
}

.search-select__empty {
  padding: 0.6rem 0.5rem;
  font-size: 0.8rem;
  color: var(--color-text-subtle);
}
</style>
