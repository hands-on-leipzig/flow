<script setup lang="ts">
defineProps<{
  label: string
  disabled?: boolean
}>()
</script>

<template>
  <button
      type="button"
      class="glass-btn-icon glass-btn-icon--danger"
      :aria-label="label"
      :title="label"
      :disabled="disabled"
  >
    <i class="bi bi-trash3" aria-hidden="true"/>
  </button>
</template>
