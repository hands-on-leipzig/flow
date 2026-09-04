<script setup lang="ts">
import draggable from 'vuedraggable'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import InfoPopover from '@/components/atoms/InfoPopover.vue'
import ItemCard from '@/components/molecules/ItemCard.vue'
import StaffingScopeLeading from '@/components/volunteers/StaffingScopeLeading.vue'
import {volunteerDisplayName, type VolunteerPersonRef} from '@/utils/volunteerPerson'
import {
  boundsLabel,
  slotPositions,
  staffingGap,
  tileFilled,
  tileNeedsAttention,
  tilePeople,
  tileSurplus,
  type StaffingRole,
  type StaffingTile,
} from '@/volunteers/staffingTypes'

defineProps<{
  tile: StaffingTile
  isDragging: boolean
  dragOverKey: string | null
}>()

const emit = defineEmits<{
  'persist-role': [role: StaffingRole]
  'delete-role': [role: StaffingRole]
  'open-bounds': [role: StaffingRole, anchor: HTMLElement]
  drop: [event: unknown, tile: StaffingTile]
  'drag-start': [event: unknown, tileKey: string]
  'drag-end': []
  'dropzone-leave': [event: DragEvent, tileKey: string]
  hover: [tileKey: string]
  unassign: [tile: StaffingTile, person: VolunteerPersonRef]
}>()

function dropGroup(tile: StaffingTile) {
  const surplus = tileSurplus(tile)
  const filled = tileFilled(tile)
  return {
    name: 'staffing-people',
    pull: true,
    put: !surplus && filled < Number(tile.role.max),
  }
}

function gapStatusClass(tile: StaffingTile) {
  return `staffing-status__gap--${staffingGap(tile).tone}`
}
</script>

<template>
  <ItemCard
      :inactive="tileSurplus(tile)"
      :class="{'staffing-tile--surplus': tileSurplus(tile)}"
  >
    <template #leading>
      <StaffingScopeLeading :role="tile.role" size="base"/>
    </template>
    <template #title>
      <div class="staffing-title">
        <div class="staffing-title__text">
          <input
              v-if="tile.role.is_local"
              v-model="tile.role.label"
              class="item-card__title glass-input glass-input--sm liquid-surface-control"
              @blur="emit('persist-role', tile.role)"
          >
          <span v-else class="item-card__title font-semibold truncate flex items-center min-h-[var(--field-min-height-sm)]">
            {{ tile.name }}
          </span>
          <span v-if="tile.group" class="staffing-title__subtitle">{{ tile.role.label }}</span>
        </div>
        <span
            v-if="tileNeedsAttention(tile)"
            class="staffing-need-dot"
            :title="tileSurplus(tile) ? 'Überzählig mit Personen' : 'Unter Min'"
        />
      </div>
    </template>
    <template v-if="tile.role.is_local || tileSurplus(tile)" #trailing>
      <IconDangerButton
          v-if="tile.role.is_local"
          label="Rolle löschen"
          @click.stop="emit('delete-role', tile.role)"
      />
      <span v-else class="staffing-stale-badge">Überzählig</span>
    </template>

    <div v-if="!tileSurplus(tile)" class="staffing-meta">
      <div class="staffing-status__primary">
        <span class="staffing-status__assigned">{{ tileFilled(tile) }} zugewiesen</span>
        <span class="staffing-status__sep" aria-hidden="true">·</span>
        <span class="staffing-status__gap" :class="gapStatusClass(tile)">
          {{ staffingGap(tile).label }}
        </span>
      </div>

      <div class="staffing-status__secondary">
        <div class="staffing-slots" aria-hidden="true">
          <i
              v-for="pos in slotPositions(tile.role)"
              :key="`${tile.key}-slot-${pos}`"
              class="staffing-slot__icon bi"
              :class="pos <= tileFilled(tile) ? 'bi-person-fill staffing-slot__icon--filled' : 'bi-person'"
          />
        </div>

        <div class="staffing-status__bounds">
          <span class="staffing-bounds-text">{{ boundsLabel(tile.role) }}</span>
          <button
              v-if="tile.role.is_local"
              type="button"
              class="staffing-bounds-gear"
              title="Besetzung bearbeiten"
              aria-label="Besetzung bearbeiten"
              @click.stop="emit('open-bounds', tile.role, $event.currentTarget as HTMLElement)"
          >
            <i class="bi bi-gear" aria-hidden="true"/>
          </button>
          <InfoPopover v-else-if="tile.role.ui_description" :text="tile.role.ui_description"/>
        </div>
      </div>
    </div>

    <p v-if="tileSurplus(tile)" class="staffing-surplus">
      Nicht mehr benötigt — Personen in andere Rollen ziehen.
    </p>

    <div
        class="glass-dropzone"
        :class="{
          'glass-dropzone--dragging': isDragging && !tileSurplus(tile),
          'glass-dropzone--active': dragOverKey === tile.key,
          'glass-dropzone--blocked': tileSurplus(tile),
        }"
        @dragenter.prevent="emit('hover', tile.key)"
        @dragover.prevent="emit('hover', tile.key)"
        @dragleave="emit('dropzone-leave', $event, tile.key)"
    >
      <div
          v-if="tilePeople(tile).length === 0"
          class="glass-dropzone__empty"
      >
        <i class="bi bi-box-arrow-in-down glass-dropzone__empty-icon"/>
        <span class="glass-dropzone__empty-text">
          {{ isDragging ? 'Hier ablegen' : 'Personen hierher ziehen' }}
        </span>
      </div>
      <draggable
          :list="tilePeople(tile)"
          class="glass-dropzone__list"
          :group="dropGroup(tile)"
          item-key="id"
          @add="emit('drop', $event, tile)"
          @start="emit('drag-start', $event, tile.key)"
          @end="emit('drag-end')"
      >
        <template #item="{element: person}">
          <span class="glass-row-item glass-row-item--interactive vol-person-chip cursor-move">
            <i class="bi bi-person-fill vol-person-chip__icon" aria-hidden="true"/>
            <span class="vol-person-chip__label truncate max-w-[10rem]">{{ volunteerDisplayName(person) }}</span>
            <button
                type="button"
                class="vol-person-chip__dismiss"
                aria-label="Zuordnung entfernen"
                @click.stop="emit('unassign', tile, person)"
            >
              <i class="bi bi-x" aria-hidden="true"/>
            </button>
          </span>
        </template>
      </draggable>
    </div>
  </ItemCard>
</template>

<style scoped>
.staffing-title {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  min-width: 0;
  width: 100%;
}

.staffing-title__text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1 1 auto;
}

.staffing-title .item-card__title {
  flex: 0 1 auto;
  min-width: 0;
  width: auto;
  max-width: 100%;
}

.staffing-title .item-card__title.glass-input {
  flex: 1 1 auto;
}

.staffing-title__subtitle {
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-text-subtle);
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.staffing-need-dot {
  flex-shrink: 0;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  background: var(--color-danger, #ef4444);
}

.staffing-meta {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.staffing-status__primary {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.35rem;
  font-size: 0.9rem;
  font-weight: 600;
  line-height: 1.3;
}

.staffing-status__assigned {
  color: var(--color-text);
}

.staffing-status__sep {
  color: var(--color-text-subtle);
  font-weight: 400;
}

.staffing-status__gap {
  font-weight: 600;
}

.staffing-status__gap--warn {
  color: var(--color-danger, #dc2626);
}

.staffing-status__gap--caution {
  color: var(--color-warning, #d97706);
}

.staffing-status__gap--ok {
  color: var(--color-success, #15803d);
}

.staffing-status__gap--muted {
  color: var(--color-text-subtle);
}

.staffing-status__secondary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.staffing-slots {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.15rem;
}

.staffing-slot__icon {
  font-size: 0.85rem;
  color: var(--color-text-subtle);
}

.staffing-slot__icon--filled {
  color: var(--color-accent);
}

.staffing-status__bounds {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.75rem;
  color: var(--color-text-subtle);
}

.staffing-bounds-text {
  white-space: nowrap;
}

.staffing-bounds-gear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.15rem;
  border: none;
  background: transparent;
  color: var(--color-text-subtle);
  cursor: pointer;
}

.staffing-bounds-gear:hover {
  color: var(--color-text);
}

:deep(.staffing-tile--surplus) {
  border-color: color-mix(in srgb, var(--color-danger, #dc2626) 42%, var(--color-border));
  background: color-mix(in srgb, var(--color-danger, #dc2626) 12%, var(--color-bg-muted));
}

.staffing-stale-badge {
  flex-shrink: 0;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-danger, #dc2626) 14%, transparent);
  color: var(--color-danger, #b91c1c);
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  white-space: nowrap;
}

.staffing-surplus {
  margin: 0 0 0.5rem;
  font-size: 0.75rem;
  line-height: 1.35;
  color: var(--color-danger, #b91c1c);
  font-weight: 600;
}

:deep(.glass-dropzone--blocked) {
  border-style: dashed;
  border-color: color-mix(in srgb, var(--color-danger, #dc2626) 35%, var(--color-border));
  background: color-mix(in srgb, var(--color-danger, #dc2626) 10%, var(--color-bg-muted));
}
</style>
