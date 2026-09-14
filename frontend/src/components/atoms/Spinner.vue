<script setup lang="ts">
withDefaults(defineProps<{
  size?: 'sm' | 'md' | 'lg'
  /** When set, the spinner is announced; otherwise it is decorative next to a caption. */
  label?: string
  /** Use the surrounding text color (e.g. white on a filled button). */
  inherit?: boolean
}>(), {
  size: 'md',
})
</script>

<template>
  <span
      class="flow-spinner"
      :class="[`flow-spinner--${size}`, inherit ? 'flow-spinner--inherit' : '']"
      :role="label ? 'status' : undefined"
      :aria-label="label"
      :aria-hidden="label ? undefined : true"
  />
</template>

<style scoped>
.flow-spinner {
  display: inline-block;
  flex-shrink: 0;
  box-sizing: border-box;
  border-radius: 999px;
  border-style: solid;
  border-color: color-mix(in srgb, var(--color-accent) 25%, transparent);
  border-top-color: var(--color-accent);
  animation: flow-spinner-rotate 0.8s linear infinite;
}

.flow-spinner--sm {
  width: 1rem;
  height: 1rem;
  border-width: 2px;
}

.flow-spinner--md {
  width: 2rem;
  height: 2rem;
  border-width: 2.5px;
}

.flow-spinner--lg {
  width: 2.75rem;
  height: 2.75rem;
  border-width: 3px;
}

.flow-spinner--inherit {
  border-color: color-mix(in srgb, currentColor 25%, transparent);
  border-top-color: currentColor;
}

@keyframes flow-spinner-rotate {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .flow-spinner {
    animation-duration: 1.6s;
  }
}
</style>
