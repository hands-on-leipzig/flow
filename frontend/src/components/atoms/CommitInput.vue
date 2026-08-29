<script setup lang="ts">
import {toRef} from 'vue'
import {useCommitField} from '@/composables/useCommitField'

defineOptions({inheritAttrs: false})

const props = withDefaults(defineProps<{
  modelValue: string
  disabled?: boolean
  type?: 'text' | 'url' | 'time' | 'date' | 'number'
  placeholder?: string
  min?: string
  max?: string
  step?: string | number
  inputClass?: string
  ariaLabel?: string
}>(), {
  disabled: false,
  type: 'text',
  placeholder: '',
  inputClass: '',
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  commit: [value: string]
}>()

const source = toRef(props, 'modelValue')

const {local, onInput, onBlur, onEnter} = useCommitField(
  () => source.value,
  (value) => {
    emit('update:modelValue', value)
    emit('commit', value)
  },
)
</script>

<template>
  <input
      v-bind="$attrs"
      :value="local"
      :disabled="disabled"
      :type="type"
      :placeholder="placeholder"
      :min="min"
      :max="max"
      :step="step"
      :class="inputClass"
      :aria-label="ariaLabel"
      @input="onInput(($event.target as HTMLInputElement).value)"
      @blur="onBlur"
      @keydown.enter="onEnter"
  />
</template>
