<script setup lang="ts">
/**
 * Ausgabe → Digital: shared page header + flexible dual-scroll workspace.
 */
import PresentationSettings from '@/components/molecules/PresentationSettings.vue'

defineOptions({name: 'PublishDigital'})
</script>

<template>
  <div class="vol-page vol-page--fill digital-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Digitale Ausgabe</h1>
        <p class="vol-page__sub">
          Slideshows, die während der Veranstaltung statt gedruckten Material gezeigt werden können.
        </p>
      </div>
    </header>

    <PresentationSettings/>
  </div>
</template>

<style scoped>
.digital-page > :deep(.digital-workspace) {
  flex: 1 1 0%;
  min-height: 0;
  min-width: 0;
}
</style>
