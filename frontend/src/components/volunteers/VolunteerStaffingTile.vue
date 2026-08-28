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
  tileNeedsAttention,
  type StaffingGroup,
  type StaffingRole,
  type StaffingTile,
} from '@/volunteers/staffingTypes'

const props = defineProps<{
  tile: StaffingTile
  isDragging: boolean
  dragOverGroupId: number | null
}>()

const emit = defineEmits<{
  'persist-role': [role: StaffingRole]
  'delete-role': [role: StaffingRole]
  'open-bounds': [role: StaffingRole, anchor: HTMLElement]
  drop: [event: unknown, group: StaffingGroup]
  'drag-start': [event: unknown, groupId: number]
  'drag-end': []
  'dropzone-leave': [event: DragEvent, groupId: number]
  'hover-group': [groupId: number]
  unassign: [group: StaffingGroup, person: VolunteerPersonRef]
}>()

function dropGroup(group: StaffingGroup) {
  return {
    name: 'staffing-people',
    pull: true,
    put: !group.surplus && group.filled < group.max,
  }
}

function gapStatusClass(tile: StaffingTile) {
  return `staffing-status__gap--${staffingGap(tile).tone}`
}
</script>

<template>
  <ItemCard
      :inactive="tile.group.surplus"
      :class="{'staffing-tile--surplus': tile.group.surplus}"
  >
    <template #leading>
      <StaffingScopeLeading :role="tile.role" size="base"/>
    </template>
    <template #title>
      <div class="staffing-title">
        <input
            v-if="tile.role.is_local"
            v-model="tile.role.label"
            class="item-card__title glass-input glass-input--sm liquid-surface-control"
            @blur="emit('persist-role', tile.role)"
        >
        <span v-else class="item-card__title font-semibold truncate flex items-center min-h-[var(--field-min-height-sm)]">
          {{ tile.name }}
        </span>
        <span
            v-if="tileNeedsAttention(tile)"
            class="staffing-need-dot"
            :title="tile.group.surplus ? 'Überzählig mit Personen' : 'Unter Min'"
        />
      </div>
    </template>
    <template v-if="tile.role.is_local || tile.group.surplus" #trailing>
      <IconDangerButton
          v-if="tile.role.is_local"
          label="Rolle löschen"
          @click.stop="emit('delete-role', tile.role)"
      />
      <span v-else class="staffing-stale-badge">Überzählig</span>
    </template>

    <div v-if="!tile.group.surplus" class="staffing-meta">
      <div class="staffing-status__primary">
        <span class="staffing-status__assigned">{{ tile.group.filled }} zugewiesen</span>
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
              :class="pos <= tile.group.filled ? 'bi-person-fill staffing-slot__icon--filled' : 'bi-person'"
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

    <p v-if="tile.group.surplus" class="staffing-surplus">
      Nicht mehr benötigt — Personen in andere Rollen ziehen.
    </p>

    <div
        class="glass-dropzone"
        :class="{
          'glass-dropzone--dragging': isDragging && !tile.group.surplus,
          'glass-dropzone--active': dragOverGroupId === tile.group.id,
          'glass-dropzone--blocked': tile.group.surplus,
        }"
        @dragenter.prevent="emit('hover-group', tile.group.id)"
        @dragover.prevent="emit('hover-group', tile.group.id)"
        @dragleave="emit('dropzone-leave', $event, tile.group.id)"
    >
      <div
          v-if="tile.group.people.length === 0"
          class="glass-dropzone__empty"
      >
        <i class="bi bi-box-arrow-in-down glass-dropzone__empty-icon"/>
        <span class="glass-dropzone__empty-text">
          {{ isDragging ? 'Hier ablegen' : 'Personen hierher ziehen' }}
        </span>
      </div>
      <draggable
          :list="tile.group.people"
          class="glass-dropzone__list"
          :group="dropGroup(tile.group)"
          item-key="id"
          @add="emit('drop', $event, tile.group)"
          @start="emit('drag-start', $event, tile.group.id)"
          @end="emit('drag-end')"
      >
        <template #item="{element: person}">
          <span class="glass-row-item glass-row-item--interactive text-[11px] md:text-xs cursor-move">
            <i class="bi bi-person-fill text-[var(--color-text-subtle)]"/>
            <span class="px-1.5 py-1 truncate max-w-[10rem]">{{ volunteerDisplayName(person) }}</span>
            <button
                type="button"
                class="ml-0.5 text-sm text-[var(--color-text-subtle)] hover:text-[var(--color-text)] pr-1"
                @click.stop="emit('unassign', tile.group, person)"
            >
              ✖
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

.staffing-title .item-card__title {
  flex: 0 1 auto;
  min-width: 0;
  width: auto;
  max-width: 100%;
}

.staffing-title .item-card__title.glass-input {
  flex: 1 1 auto;
}

.staffing-need-dot {
  flex-shrink: 0;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 999px;
  background: #ef4444;
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
  color: #dc2626;
}

.staffing-status__gap--caution {
  color: #d97706;
}

.staffing-status__gap--ok {
  color: #15803d;
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

.staffing-stale-badge {
  font-size: 0.7rem;
  font-weight: 600;
  color: var(--color-text-subtle);
  white-space: nowrap;
}

.staffing-surplus {
  margin: 0 0 0.5rem;
  font-size: 0.8125rem;
  color: var(--color-text-subtle);
}
</style>
