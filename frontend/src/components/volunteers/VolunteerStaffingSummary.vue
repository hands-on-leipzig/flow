<script setup lang="ts">
import {computed} from 'vue'
import {RouterLink} from 'vue-router'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {useEventStore} from '@/stores/event'
import {programDisplayName, programId, programNameForId, type EventProgramRef} from '@/utils/eventPrograms'
import {type StaffingFilterKey} from '@/utils/volunteerStaffingFilters'
import {programIdFromSummaryKey, type StaffingScopeSummary} from '@/utils/volunteerStaffingSummary'

const props = defineProps<{
  scopes: ReadonlyArray<StaffingScopeSummary>
  programs: ReadonlyArray<EventProgramRef>
  layout?: 'list' | 'bar' | 'teams'
  loading?: boolean
  linkTo?: string
}>()

const emit = defineEmits<{
  select: [key: StaffingFilterKey]
}>()

const eventStore = useEventStore()

const layout = computed(() => props.layout ?? 'list')

const visibleScopes = computed(() =>
  props.scopes.filter((scope) => scope.key !== 'local' || (scope.roles ?? 0) > 0),
)

function scopeProgram(key: StaffingFilterKey) {
  const id = programIdFromSummaryKey(key)
  if (!id) return null
  const program = props.programs.find((row) => programId(row) === id)
  return program ?? {first_program: id, name: programNameForId(eventStore.selectedEvent, id)}
}

function scopeLabel(key: StaffingFilterKey) {
  if (key === 'cross') return 'Übergreifend'
  if (key === 'local') return 'Zusätzlich'
  const id = programIdFromSummaryKey(key)
  if (!id) return 'Programm'
  const program = props.programs.find((row) => programId(row) === id)
  return programDisplayName(program ?? {first_program: id, name: programNameForId(eventStore.selectedEvent, id)})
}

function missingLabel(count: number) {
  if (count === 1) return '1 fehlt'
  return `${count} fehlen`
}

function scopeStatus(scope: StaffingScopeSummary) {
  if (scope.missing_min > 0) return missingLabel(scope.missing_min)
  return 'komplett'
}

function onSelect(key: StaffingFilterKey) {
  emit('select', key)
}

function scopeIconClass(key: StaffingFilterKey) {
  if (key === 'cross') return 'bi-intersect'
  if (key === 'local') return 'bi-plus-lg'
  return ''
}
</script>

<template>
  <div
      v-if="layout === 'teams'"
      class="vol-staffing-summary vol-staffing-summary--teams space-y-2"
  >
    <p v-if="loading" class="vol-staffing-summary__muted">Lade Zuordnung…</p>
    <template v-else>
      <component
          :is="linkTo ? RouterLink : 'div'"
          v-for="scope in visibleScopes"
          :key="scope.key"
          :to="linkTo"
          class="flex items-center gap-2 rounded-lg px-3 py-1.5 liquid-surface-inner"
          :class="linkTo ? 'hover:bg-[var(--color-bg-hover)] transition-colors no-underline text-inherit' : ''"
      >
        <ProgramLogo
            v-if="scopeProgram(scope.key)"
            :program="scopeProgram(scope.key)!"
            size="lg"
        />
        <div
            v-else
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-[var(--color-bg-muted)] text-[var(--color-text-muted)]"
            aria-hidden="true"
        >
          <i class="bi text-lg" :class="scopeIconClass(scope.key)"/>
        </div>
        <div class="min-w-0 flex-1">
          <div class="font-medium flex items-center justify-between gap-2">
            <span class="inline-flex items-center gap-1.5 min-w-0">
              <span>{{ scope.assigned }} zugeordnet, {{ scopeStatus(scope) }}</span>
              <span
                  v-if="scope.missing_min > 0"
                  class="inline-block h-2 w-2 rounded-full bg-red-500 shrink-0"
                  title="Unter Mindestempfehlung"
                  aria-label="Unter Mindestempfehlung"
              />
            </span>
            <i
                v-if="linkTo"
                class="bi bi-chevron-right text-[var(--color-text-subtle)] shrink-0"
                aria-hidden="true"
            />
          </div>
        </div>
      </component>
      <p v-if="visibleScopes.length === 0" class="text-sm text-[var(--color-text-subtle)]">
        Keine Rollen-Daten verfügbar
      </p>
    </template>
  </div>
  <div
      v-else
      class="vol-staffing-summary"
      :class="{
        'vol-staffing-summary--list': layout === 'list',
        'vol-staffing-summary--bar': layout === 'bar',
      }"
  >
    <p v-if="loading" class="vol-staffing-summary__muted">Lade Zuordnung…</p>
    <template v-else>
      <component
          :is="layout === 'list' ? 'button' : 'div'"
          v-for="scope in visibleScopes"
          :key="scope.key"
          type="button"
          class="vol-staffing-summary__row"
          :class="{
            'vol-staffing-summary__row--warn': scope.missing_min > 0,
            'vol-staffing-summary__row--interactive': layout === 'list',
          }"
          @click="layout === 'list' && onSelect(scope.key)"
      >
        <div class="vol-staffing-summary__label">
          <ProgramLogo
              v-if="scopeProgram(scope.key)"
              :program="scopeProgram(scope.key)!"
              size="chip"
              decorative
          />
          <span>{{ scopeLabel(scope.key) }}</span>
        </div>
        <div class="vol-staffing-summary__nums tabular-nums">
          <span>{{ scope.assigned }} zugeordnet</span>
          <span
              class="vol-staffing-summary__gap"
              :class="{'vol-staffing-summary__gap--warn': scope.missing_min > 0}"
          >
            {{ missingLabel(scope.missing_min) }}
          </span>
        </div>
      </component>
    </template>
  </div>
</template>

<style scoped>
.vol-staffing-summary {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.vol-staffing-summary--bar {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0.5rem;
}

.vol-staffing-summary__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  width: 100%;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--liquid-border-soft);
  border-radius: var(--radius);
  background: var(--liquid-tile-bg-inner);
  box-shadow: var(--liquid-shadow-inset);
  text-align: left;
  color: inherit;
}

.vol-staffing-summary__row--interactive {
  cursor: pointer;
  transition: background 0.15s ease;
}

.vol-staffing-summary__row--interactive:hover {
  background: var(--color-bg-hover);
}

.vol-staffing-summary__row--warn {
  border-color: color-mix(in srgb, var(--color-warning, #f59e0b) 35%, var(--liquid-border-soft));
}

.vol-staffing-summary__label {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-width: 0;
  font-weight: 500;
}

.vol-staffing-summary__nums {
  display: inline-flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.35rem 0.55rem;
  font-size: 0.8125rem;
  color: var(--color-text-muted);
  white-space: nowrap;
}

.vol-staffing-summary__gap--warn {
  color: var(--color-warning, #d97706);
  font-weight: 600;
}

.vol-staffing-summary__muted {
  margin: 0;
  font-size: 0.875rem;
  color: var(--color-text-subtle);
}
</style>
