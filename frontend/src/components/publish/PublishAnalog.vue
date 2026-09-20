<script setup lang="ts">
/**
 * Ausgabe → Drucksachen: PDF-Pläne und Aushänge zum Drucken.
 * Tiles only on Local/Dev; Test/Production see an in-review notice.
 */
import {computed} from 'vue'
import PdfPlansBox from '@/components/molecules/PdfPlansBox.vue'
import RoleSheetsPrint from '@/components/molecules/RoleSheetsPrint.vue'
import RoomSheetsPrint from '@/components/molecules/RoomSheetsPrint.vue'
import OverviewSheetsPrint from '@/components/molecules/OverviewSheetsPrint.vue'
import NoticePane from '@/components/molecules/NoticePane.vue'
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

    <NoticePane/>

    <div v-if="showTiles" class="druck-page__body">
      <div class="druck-page__panes">
        <section class="glass-card liquid-surface-inner druck-page__panel">
          <p class="druck-page__group-label">
            <i class="bi bi-people" aria-hidden="true"/>
            <span>Zum Aushang bzw. zum Verteilen an Teams und Volunteers</span>
          </p>
          <div class="druck-page__grid">
            <div class="druck-page__col">
              <OverviewSheetsPrint/>
              <RoomSheetsPrint/>
            </div>
            <RoleSheetsPrint/>
          </div>
        </section>
        <section class="glass-card liquid-surface-inner druck-page__panel">
          <p class="druck-page__group-label">
            <i class="bi bi-shield-lock" aria-hidden="true"/>
            <span>Nur für Veranstalter – nicht für Teams oder Besucher.</span>
          </p>
        </section>
      </div>
      <PdfPlansBox hide-heading section="plans" split-panes/>
    </div>
    <section v-else class="glass-card liquid-surface-inner druck-page__review" role="status">
      <p class="druck-page__review-text">
        Diese Funktionen werden gerade für die neue Saison angepasst und kommen bald zurück.
      </p>
    </section>
  </div>
</template>

<style scoped>
.druck-page__body {
  flex: 1 1 0%;
  min-height: 0;
  min-width: 0;
  overflow-x: hidden;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.druck-page__panes {
  display: flex;
  flex-direction: row;
  gap: 1rem 1.25rem;
  min-width: 0;
  align-items: start;
}

.druck-page__panel {
  flex: 1 1 0%;
  min-width: 0;
}

.druck-page__group-label {
  margin: 0 0 0.75rem;
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  font-size: 0.82rem;
  line-height: 1.4;
  color: var(--color-text-muted);
}

.druck-page__group-label .bi {
  flex-shrink: 0;
  margin-top: 0.12rem;
  font-size: 1rem;
  color: var(--color-accent);
}

.druck-page__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.85rem;
  align-items: start;
}

.druck-page__col {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  min-width: 0;
}

.druck-page__body :deep(.pdf-plans),
.druck-page__body :deep(.pdf-plans__plans--split) {
  flex: none;
  min-height: 0;
  overflow: visible;
}

.druck-page__body :deep(.pdf-plans__plans--split) {
  align-items: start;
}

.druck-page__body :deep(.pdf-plans__plans--split > .pdf-plans__panel) {
  flex: 1 1 0%;
  min-width: 0;
  min-height: auto;
  overflow: visible;
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
