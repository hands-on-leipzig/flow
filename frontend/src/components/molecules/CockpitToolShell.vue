<script setup lang="ts">
defineOptions({name: 'CockpitToolShell'})

defineProps<{
  title: string
  explanation?: string
}>()

const emit = defineEmits<{
  back: []
}>()
</script>

<template>
  <div class="cp-tool-shell">
    <header class="cp-tool-shell__bar">
      <button type="button" class="cp-tool-shell__back" @click="emit('back')">
        ← Zurück
      </button>
      <h1 class="cp-tool-shell__title">{{ title }}</h1>
    </header>

    <p v-if="explanation" class="cp-tool-shell__explanation">{{ explanation }}</p>

    <div class="cp-tool-shell__body">
      <slot/>
    </div>
  </div>
</template>

<style scoped>
.cp-tool-shell {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  max-width: 48rem;
  margin: 0 auto;
  min-height: 100%;
}

.cp-tool-shell__bar {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
  position: sticky;
  top: 0;
  z-index: 1;
  margin: -1rem -1rem 0;
  padding: 0.85rem 1rem 0.75rem;
  padding-top: max(0.85rem, env(safe-area-inset-top));
  background: color-mix(in srgb, var(--color-bg, #f4f4f5) 92%, transparent);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid var(--liquid-border-soft);
}

.cp-tool-shell__back {
  appearance: none;
  border: 0;
  background: transparent;
  padding: 0.15rem 0;
  margin: 0;
  font: inherit;
  font-size: 0.95rem;
  font-weight: 600;
  color: var(--color-accent, var(--color-text));
  cursor: pointer;
  min-height: 2.5rem;
  display: inline-flex;
  align-items: center;
}

.cp-tool-shell__back:active {
  opacity: 0.75;
}

.cp-tool-shell__title {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 750;
  line-height: 1.25;
  color: var(--color-text);
}

.cp-tool-shell__explanation {
  margin: 0;
  font-size: 0.95rem;
  line-height: 1.45;
  color: var(--color-text-muted);
}

.cp-tool-shell__body {
  flex: 1;
  min-width: 0;
}
</style>
