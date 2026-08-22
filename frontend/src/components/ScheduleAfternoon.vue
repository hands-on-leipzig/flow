<script setup lang="ts">
import {ref} from 'vue'
import draggable from 'vuedraggable'
import IconDraggable from '@/components/icons/IconDraggable.vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'

defineOptions({ name: 'ScheduleAfternoon' })

type AfternoonProgram = 'CHALLENGE' | 'FUTURE_8'

type AfternoonBlock = {
  id: string
  label: string
  program: AfternoonProgram
}

const blocks = ref<AfternoonBlock[]>([
  {id: 'c_research', label: 'Challenge Forschung', program: 'CHALLENGE'},
  {id: 'c_quarter', label: 'Challenge Viertelfinale', program: 'CHALLENGE'},
  {id: 'c_semi', label: 'Challenge Halbfinale', program: 'CHALLENGE'},
  {id: 'c_final', label: 'Challenge Finale', program: 'CHALLENGE'},
  {id: 'f8_round4', label: 'Future Runde 4', program: 'FUTURE_8'},
  {id: 'f8_round5', label: 'Future Runde 5', program: 'FUTURE_8'},
  {id: 'f8_research', label: 'Future Forschung', program: 'FUTURE_8'},
])
</script>

<template>
  <div class="schedule-afternoon flex flex-col pb-2">
    <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
      <h2 class="afternoon-anchor__title">Vorrunden</h2>
    </section>

    <draggable
        v-model="blocks"
        animation="150"
        chosen-class="drag-chosen"
        class="afternoon-stack"
        drag-class="drag-dragging"
        ghost-class="drag-ghost"
        handle=".drag-handle"
        item-key="id"
    >
      <template #item="{ element }">
        <div class="afternoon-block glass-card liquid-surface-inner">
          <span class="drag-handle" aria-label="Reihenfolge ändern">
            <IconDraggable/>
          </span>
          <img
              :alt="programLogoAlt(element.program)"
              :src="programLogoSrc(element.program)"
              class="afternoon-block__logo"
          >
          <span class="afternoon-block__label">{{ element.label }}</span>
        </div>
      </template>
    </draggable>

    <section class="afternoon-anchor glass-stack-card glass-stack-card--dashed">
      <h2 class="afternoon-anchor__title">Preisverleihung</h2>
    </section>
  </div>
</template>

<style scoped>
.schedule-afternoon {
  gap: 1.15rem;
}

.afternoon-stack {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.afternoon-anchor {
  padding: 0.7rem 0.9rem;
}

.afternoon-anchor__title {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text-muted);
  line-height: 1.3;
}

.afternoon-block {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.7rem 0.9rem;
}

.drag-handle {
  display: inline-flex;
  color: var(--color-text-muted);
  cursor: grab;
  flex-shrink: 0;
}

.drag-handle :deep(svg) {
  width: 1.15rem;
  height: 1.15rem;
}

.afternoon-block__logo {
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  object-fit: contain;
}

.afternoon-block__label {
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
  line-height: 1.3;
}

.drag-ghost {
  opacity: 0.4;
}

.drag-chosen {
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-accent) 45%, transparent);
}

.drag-dragging {
  cursor: grabbing;
}
</style>
