<script setup lang="ts">
import {ref, watch} from 'vue'

const props = defineProps<{
  modelValue: boolean
  disabled?: boolean
}>()
const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
}>()

const checked = ref(props.modelValue)

watch(() => props.modelValue, (v) => (checked.value = v))

function toggle() {
  if (props.disabled) return
  checked.value = !checked.value
  emit('update:modelValue', checked.value)
}
</script>

<template>
  <button
      type="button"
      role="switch"
      :aria-checked="checked"
      :disabled="disabled"
      @click="toggle"
      class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out"
      :class="checked ? 'bg-blue-600' : 'bg-gray-300'"
  >
    <span
        class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
        :class="checked ? 'translate-x-5' : 'translate-x-0'"
    />
  </button>
</template>
