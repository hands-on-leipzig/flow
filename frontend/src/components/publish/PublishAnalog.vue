<script setup lang="ts">
/**
 * Ausgabe → Drucksachen: PDF-Pläne und Aushänge zum Drucken.
 */
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'

defineOptions({name: 'PublishAnalog'})
</script>

<template>
  <div class="analog">
    <header class="analog__intro">
      <h1 class="analog__title">Drucksachen</h1>
    </header>

    <PdfPlansBox hide-heading/>
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
</style>
