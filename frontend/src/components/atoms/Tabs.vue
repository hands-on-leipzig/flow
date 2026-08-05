<script setup>
import PageTabs from '@hands-on/glass/page-tabs'
import { computed } from 'vue'

const props = defineProps({
  tabs: Array,
  modelValue: String,
})

const emit = defineEmits(['update:modelValue'])

const pageTabs = computed(() =>
  (props.tabs || []).map((tab) => ({
    id: tab,
    label: tab,
  }))
)
</script>

<template>
  <PageTabs
      :tabs="pageTabs"
      :model-value="modelValue"
      @update:model-value="emit('update:modelValue', $event)"
  />
</template>
