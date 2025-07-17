<script setup>
defineProps({
  tabs: Array,
  modelValue: String
})

defineEmits(['update:modelValue'])
</script>

<template>
  <div class="border-b border-gray-200 flex space-x-4 mb-2">
    <button
        v-for="tab in tabs"
        :key="tab"
        @click="$emit('update:modelValue', tab)"
        :class="[
        'py-2 px-4 text-sm font-medium',
        modelValue === tab
          ? 'border-b-2 border-blue-600 text-blue-600'
          : 'text-gray-500 hover:text-blue-600'
      ]"
    >
      {{ tab }}
    </button>
  </div>
</template>

<style scoped>

</style>