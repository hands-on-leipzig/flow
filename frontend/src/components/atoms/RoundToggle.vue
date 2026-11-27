<script setup lang="ts">
defineProps<{
  on: boolean
  busy?: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{ toggle: [] }>()
</script>

<template>
  <button
      type="button"
      :disabled="disabled"
      :aria-pressed="on"
      :class="['round-toggle', on ? 'round-toggle--on' : 'glass-btn-secondary', busy ? 'opacity-70' : '']"
      @click="emit('toggle')"
  >
    <slot/>
  </button>
</template>

<style scoped>
.round-toggle {
  min-height: 3rem;
  border-radius: var(--radius);
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  font-weight: 600;
}

.round-toggle--on {
  border: 1px solid color-mix(in srgb, #000 12%, #16a34a);
  color: #fff;
  background: linear-gradient(
    180deg,
    color-mix(in srgb, #fff 14%, #16a34a) 0%,
    #16a34a 55%,
    #15803d 100%
  );
  box-shadow:
    0 0 0 transparent,
    0 1px 1px rgba(15, 23, 42, 0.12),
    inset 0 3px 7px rgba(0, 0, 0, 0.28),
    inset 0 1px 0 rgba(0, 0, 0, 0.14);
  transform: translateY(1px) scale(0.99);
}

.round-toggle--on:active:not(:disabled) {
  transform: translateY(2px) scale(0.985);
}
</style>
