<script setup lang="ts">
/**
 * Ausgabe → Analog: PDF-Pläne und Aushänge zum Drucken.
 */
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'

defineOptions({name: 'PublishAnalog'})
</script>

<template>
  <div class="analog">
    <header class="analog__intro">
      <h1 class="analog__title">Analog</h1>
      <p class="analog__sub">
        Druckbare Pläne und Aushänge für vor Ort.
      </p>
    </header>

    <PdfPlansBox/>
  </div>
</template>

<style scoped>
.analog {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.analog__intro {
  margin-bottom: 0.1rem;
}

.analog__title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}

.analog__sub {
  margin: 0.3rem 0 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
  max-width: 40rem;
}
</style>
