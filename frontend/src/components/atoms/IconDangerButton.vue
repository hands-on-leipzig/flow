<script setup lang="ts">
defineProps<{
  label: string
  disabled?: boolean
}>()
</script>

<template>
  <button
      type="button"
      class="icon-danger-btn"
      :aria-label="label"
      :title="label"
      :disabled="disabled"
  >
    <i class="bi bi-trash-fill" aria-hidden="true"/>
  </button>
</template>
