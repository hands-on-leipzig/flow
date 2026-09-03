<script setup lang="ts">
/**
 * Ausgabe → Namensschilder: Namensaufkleber für Teams und Volunteers.
 */
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'

defineOptions({name: 'PublishNameTags'})
</script>

<template>
  <div class="vol-page namensschilder-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Namensschilder</h1>
        <p class="vol-page__sub">Namensaufkleber zum Drucken auf A4-Papier</p>
      </div>
    </header>

    <PdfPlansBox hide-heading section="labels"/>
  </div>
</template>
