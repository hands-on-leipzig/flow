<script setup lang="ts">
import StaffingScopeLeading from '@/components/volunteers/StaffingScopeLeading.vue'
import {programDisplayName, programId} from '@/utils/eventPrograms'
import {
  isStaffingFilterActive,
  type StaffingFilterKey,
} from '@/utils/volunteerStaffingFilters'

type ProgramRef = {first_program?: number; id?: number; name?: string | null}

const props = defineProps<{
  activeFilters: Set<StaffingFilterKey>
  programs: ReadonlyArray<ProgramRef>
  card?: boolean
  hasAttention?: (key: StaffingFilterKey) => boolean
}>()

const emit = defineEmits<{
  toggle: [key: StaffingFilterKey]
}>()

function isActive(key: StaffingFilterKey) {
  return isStaffingFilterActive(props.activeFilters, key)
}

function onToggle(key: StaffingFilterKey) {
  emit('toggle', key)
}
</script>

<template>
  <div
      class="vol-staffing-filters"
      :class="{'vol-staffing-filters--card glass-card liquid-surface-inner': card}"
  >
    <button
        type="button"
        class="vol-staffing-filter"
        :class="{'vol-staffing-filter--active': isActive('cross')}"
        @click="onToggle('cross')"
    >
      <StaffingScopeLeading filter-key="cross" size="chip" :boxed="false"/>
      <span class="vol-staffing-filter__label">Übergreifend</span>
      <span
          v-if="hasAttention?.('cross')"
          class="vol-staffing-filter__dot"
          title="Unter Min"
      />
    </button>
    <button
        v-for="program in programs"
        :key="`filter-program-${programId(program)}`"
        type="button"
        class="vol-staffing-filter"
        :class="{'vol-staffing-filter--active': isActive(`program:${programId(program)}`)}"
        @click="onToggle(`program:${programId(program)}`)"
    >
      <StaffingScopeLeading
          :filter-key="`program:${programId(program)}`"
          size="chip"
          :boxed="false"
      />
      <span class="vol-staffing-filter__label">{{ programDisplayName(program) }}</span>
      <span
          v-if="hasAttention?.(`program:${programId(program)}`)"
          class="vol-staffing-filter__dot"
          title="Unter Min"
      />
    </button>
    <button
        type="button"
        class="vol-staffing-filter"
        :class="{'vol-staffing-filter--active': isActive('local')}"
        @click="onToggle('local')"
    >
      <StaffingScopeLeading filter-key="local" size="chip" :boxed="false"/>
      <span class="vol-staffing-filter__label">Zusätzlich</span>
      <span
          v-if="hasAttention?.('local')"
          class="vol-staffing-filter__dot"
          title="Unter Min"
      />
    </button>
    <slot name="trailing"/>
  </div>
</template>
