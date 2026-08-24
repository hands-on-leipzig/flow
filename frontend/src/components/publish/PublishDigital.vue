<script setup lang="ts">
/**
 * Ausgabe → Digital: Karussell / Slideshow-Startseite (kein Zwischen-Screen).
 */
import PresentationSettings from '@/components/molecules/PresentationSettings.vue'

defineOptions({name: 'PublishDigital'})
</script>

<template>
  <div class="digital-page">
    <PresentationSettings/>
  </div>
</template>

<style scoped>
.digital-page {
  height: 100%;
  min-height: min(72vh, 48rem);
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.digital-page > :deep(.digital-workspace) {
  flex: 1 1 auto;
}
</style>
