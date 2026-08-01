<script setup>
defineProps({
  tabs: Array,
  modelValue: String
})

defineEmits(['update:modelValue'])
</script>

<template>
  <div class="glass-tabs">
    <button
        v-for="tab in tabs"
        :key="tab"
        @click="$emit('update:modelValue', tab)"
        :class="[
        'glass-tab',
        modelValue === tab ? 'glass-tab--active' : ''
      ]"
    >
      {{ tab }}
    </button>
  </div>
</template>
