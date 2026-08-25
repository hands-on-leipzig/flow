<script setup lang="ts">
/**
 * Ausgabe → Drucksachen: PDF-Pläne und Aushänge zum Drucken.
 */
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'

defineOptions({name: 'PublishAnalog'})
</script>

<template>
  <div class="druck">
    <header class="druck__intro">
      <h1 class="druck__title">Drucksachen</h1>
    </header>
    <PdfPlansBox hide-heading/>
  </div>
</template>

<style scoped>
.druck {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.druck__title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}
</style>
