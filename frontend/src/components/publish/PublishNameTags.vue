<script setup lang="ts">
/**
 * Ausgabe → Namensschilder: Namensaufkleber für Teams und Volunteers.
 */
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'

defineOptions({name: 'PublishNameTags'})
</script>

<template>
  <div class="namensschilder">
    <header class="namensschilder__intro">
      <h1 class="namensschilder__title">Namensschilder</h1>
    </header>
    <PdfPlansBox hide-heading section="labels"/>
  </div>
</template>

<style scoped>
.namensschilder {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.namensschilder__title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}
</style>
