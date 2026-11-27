<script setup lang="ts">
import {useSlots} from 'vue'
import StaffingScopeLeading from '@/components/volunteers/StaffingScopeLeading.vue'
import {programDisplayName, programId} from '@/utils/eventPrograms'
import {
  isStaffingFilterActive,
  type StaffingFilterKey,
} from '@/utils/volunteerStaffingFilters'
import type {StaffingScopeSummary} from '@/utils/volunteerStaffingSummary'

type ProgramRef = {first_program?: number; id?: number; name?: string | null}

const props = defineProps<{
  activeFilters?: Set<StaffingFilterKey>
  programs?: ReadonlyArray<ProgramRef>
  scopes?: ReadonlyArray<StaffingScopeSummary>
  card?: boolean
  hasAttention?: (key: StaffingFilterKey) => boolean
}>()

const emit = defineEmits<{
  toggle: [key: StaffingFilterKey]
}>()

const slots = useSlots()
const hasMiddleSlot = () => !!slots.middle

function isActive(key: StaffingFilterKey) {
  return isStaffingFilterActive(props.activeFilters ?? new Set(), key)
}

function onToggle(key: StaffingFilterKey) {
  emit('toggle', key)
}

function assignedCount(key: StaffingFilterKey) {
  return props.scopes?.find((scope) => scope.key === key)?.assigned
}
</script>

<template>
  <div
      class="vol-staffing-filters"
      :class="{'vol-staffing-filters--card glass-card liquid-surface-inner': card}"
  >
    <slot name="leading"/>
    <slot v-if="hasMiddleSlot()" name="middle"/>
    <template v-else>
      <button
          type="button"
          class="glass-filter-chip"
          :class="{'glass-filter-chip--active': isActive('cross')}"
          :aria-pressed="isActive('cross')"
          @click="onToggle('cross')"
      >
        <StaffingScopeLeading filter-key="cross" size="chip" :boxed="false"/>
        <span class="glass-filter-chip__label">
          Übergreifend<span v-if="scopes" class="glass-filter-chip__count"> ({{ assignedCount('cross') }})</span>
        </span>
        <span
            v-if="hasAttention?.('cross')"
            class="glass-filter-chip__dot"
            title="Unter Min"
        />
      </button>
      <button
          v-for="program in (programs ?? [])"
          :key="`filter-program-${programId(program)}`"
          type="button"
          class="glass-filter-chip"
          :class="{'glass-filter-chip--active': isActive(`program:${programId(program)}`)}"
          :aria-pressed="isActive(`program:${programId(program)}`)"
          @click="onToggle(`program:${programId(program)}`)"
      >
        <StaffingScopeLeading
            :filter-key="`program:${programId(program)}`"
            size="chip"
            :boxed="false"
        />
        <span class="glass-filter-chip__label">
          {{ programDisplayName(program) }}<span v-if="scopes" class="glass-filter-chip__count"> ({{ assignedCount(`program:${programId(program)}`) }})</span>
        </span>
        <span
            v-if="hasAttention?.(`program:${programId(program)}`)"
            class="glass-filter-chip__dot"
            title="Unter Min"
        />
      </button>
      <button
          type="button"
          class="glass-filter-chip"
          :class="{'glass-filter-chip--active': isActive('local')}"
          :aria-pressed="isActive('local')"
          @click="onToggle('local')"
      >
        <StaffingScopeLeading filter-key="local" size="chip" :boxed="false"/>
        <span class="glass-filter-chip__label">
          Zusätzlich<span v-if="scopes" class="glass-filter-chip__count"> ({{ assignedCount('local') }})</span>
        </span>
        <span
            v-if="hasAttention?.('local')"
            class="glass-filter-chip__dot"
            title="Unter Min"
        />
      </button>
    </template>
    <slot name="trailing"/>
  </div>
</template>
