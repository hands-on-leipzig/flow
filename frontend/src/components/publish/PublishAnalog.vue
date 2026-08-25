<script setup lang="ts">
/**
 * Ausgabe → Drucksachen: PDF-Pläne und Aushänge zum Drucken.
 */
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'

defineOptions({name: 'PublishAnalog'})
</script>

<template>
  <div class="druck">
    <div class="glass-card liquid-surface-inner">
      <h1 class="glass-card__heading">Drucksachen</h1>
      <PdfPlansBox hide-heading/>
    </div>
  </div>
</template>

<style scoped>
.druck {
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}
</style>
