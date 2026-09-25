<script setup lang="ts">
const props = withDefaults(defineProps<{
  modelValue: number
  prefix?: string
  suffix?: string
  min?: number
  max?: number
  step?: number
  disabled?: boolean
  title?: string
}>(), {
  step: 1,
  disabled: false,
});

const emit = defineEmits<{ 'update:modelValue': [value: number] }>();

function commit(event: Event) {
  const input = event.target as HTMLInputElement;
  let value = Number(input.value);
  if (!Number.isFinite(value)) {
    input.value = String(props.modelValue);
    return;
  }
  if (props.min !== undefined) value = Math.max(props.min, value);
  if (props.max !== undefined) value = Math.min(props.max, value);
  input.value = String(value);
  emit('update:modelValue', value);
}
</script>

<template>
  <label class="number-field" :class="{ 'is-disabled': disabled }" :title="title">
    <span v-if="prefix" class="number-field__affix">{{ prefix }}</span>
    <input type="number" :value="modelValue" :min="min" :max="max" :step="step" :disabled="disabled"
           @change="commit" @keydown.enter="($event.target as HTMLInputElement).blur()"/>
    <span v-if="suffix" class="number-field__affix">{{ suffix }}</span>
  </label>
</template>

<style scoped>
.number-field {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  min-width: 0;
  height: 2rem;
  padding: 0 0.5rem;
  border-radius: 8px;
  border: 1px solid var(--color-border-strong);
  background: var(--color-bg-elevated);
  color: var(--color-text);
}

.number-field:focus-within {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px var(--color-accent-soft);
}

.number-field.is-disabled {
  opacity: 0.5;
}

.number-field input {
  width: 100%;
  min-width: 0;
  border: 0;
  background: transparent;
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  outline: none;
  color: inherit;
}

.number-field__affix {
  font-size: 0.75rem;
  color: var(--color-text-subtle);
  flex-shrink: 0;
}
</style>
