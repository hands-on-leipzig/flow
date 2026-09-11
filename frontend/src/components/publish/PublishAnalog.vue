<script setup lang="ts">
/**
 * Ausgabe → Drucksachen: PDF-Pläne und Aushänge zum Drucken.
 * Tiles only on Local/Dev; Test/Production see an in-review notice.
 */
import {computed} from 'vue'
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'
import {useAdminEnvironment} from '@/composables/useAdminEnvironment'
import {isEntwicklungEnvironment} from '@/constants/adminNav'

defineOptions({name: 'PublishAnalog'})

const {isLocal} = useAdminEnvironment()
const showTiles = computed(() => isEntwicklungEnvironment(isLocal))
</script>

<template>
  <div class="vol-page" :class="{'vol-page--fill druck-page': showTiles}">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Drucksachen</h1>
        <p class="vol-page__sub">Gerade noch im Umbau. Sorry.</p>
      </div>
    </header>

    <PdfPlansBox v-if="showTiles" hide-heading section="plans" split-panes/>
    <section v-else class="glass-card liquid-surface-inner druck-page__review" role="status">
      <p class="druck-page__review-text">
        Diese Funktionen werden gerade für die neue Saison angepasst und kommen bald zurück.
      </p>
    </section>
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

.druck-page__review {
  max-width: 36rem;
}

.druck-page__review-text {
  margin: 0;
  font-size: 0.9375rem;
  color: var(--color-text-subtle);
}
</style>
