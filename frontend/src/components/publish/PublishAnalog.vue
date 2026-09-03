<script setup lang="ts">
/**
 * Ausgabe → Drucksachen: PDF-Pläne und Aushänge zum Drucken.
 */
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'

defineOptions({name: 'PublishAnalog'})
</script>

<template>
  <div class="vol-page vol-page--fill druck-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Drucksachen</h1>
        <p class="vol-page__sub">Gerade noch im Umbau. Sorry.</p>
      </div>
    </header>

    <PdfPlansBox hide-heading section="plans" split-panes/>
  </div>
</template>

<style scoped>
.druck-page > :deep(.pdf-plans) {
  flex: 1 1 0%;
  min-height: 0;
  min-width: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.druck-page > :deep(.pdf-plans__plans--split) {
  flex: 1 1 0%;
}
</style>
