<script setup lang="ts">
import {computed} from 'vue'
import StaffingScopeLeading from '@/components/volunteers/StaffingScopeLeading.vue'
import {useEventStore} from '@/stores/event'
import {eventPrograms, programDisplayName, programId, programNameForId} from '@/utils/eventPrograms'
import {type StaffingFilterKey} from '@/utils/volunteerStaffingFilters'
import {
  computeOpenPositions,
  openPositionsCriticalCount,
  openPositionsNiceCount,
  type OpenPositionEntry,
  type OpenPositionScopeGroup,
} from '@/utils/volunteerOpenPositions'
import type {StaffingTile} from '@/volunteers/staffingTypes'

const props = defineProps<{
  tiles: ReadonlyArray<StaffingTile>
}>()

const eventStore = useEventStore()

const programs = computed(() => eventPrograms(eventStore.selectedEvent))

const scopes = computed(() => computeOpenPositions(props.tiles, programs.value))

const hasCritical = computed(() => openPositionsCriticalCount(scopes.value) > 0)
const hasNice = computed(() => openPositionsNiceCount(scopes.value) > 0)
const isEmpty = computed(() => !hasCritical.value && !hasNice.value)

function scopeLabel(key: StaffingFilterKey) {
  if (key === 'cross') return 'Übergreifend'
  if (key === 'local') return 'Zusätzlich'
  const id = Number(key.slice('program:'.length))
  if (!Number.isInteger(id) || id <= 0) return 'Programm'
  const program = programs.value.find((row) => programId(row) === id)
  return programDisplayName(program ?? {first_program: id, name: programNameForId(eventStore.selectedEvent, id)})
}

function scopesWithEntries(
  section: 'critical' | 'nice',
): Array<OpenPositionScopeGroup & {entries: OpenPositionEntry[]}> {
  return scopes.value
    .map((scope) => ({
      ...scope,
      entries: section === 'critical' ? scope.critical : scope.nice,
    }))
    .filter((scope) => scope.entries.length > 0)
}
</script>

<template>
  <div class="glass-card liquid-surface-inner staffing-sidebar-tile staffing-open-positions">
    <h2 class="glass-card__heading !mb-3 !text-sm md:!text-base">Offene Positionen</h2>

    <p v-if="isEmpty" class="staffing-sidebar-muted">
      Alle Rollen sind ideal besetzt.
    </p>

    <template v-else>
      <section class="staffing-open-positions__section">
        <h3 class="staffing-open-positions__heading staffing-open-positions__heading--critical">
          Kritisch
        </h3>
        <p v-if="!hasCritical" class="staffing-sidebar-muted staffing-open-positions__empty">
          Keine kritischen Lücken.
        </p>
        <template v-else>
          <div
              v-for="scope in scopesWithEntries('critical')"
              :key="`critical-${scope.key}`"
              class="staffing-open-positions__scope"
          >
            <div class="staffing-open-positions__scope-label">
              <StaffingScopeLeading :filter-key="scope.key" size="chip" :boxed="false"/>
              <span>{{ scopeLabel(scope.key) }}</span>
            </div>
            <ul class="staffing-open-positions__list">
              <li
                  v-for="entry in scope.entries"
                  :key="`critical-${scope.key}-${entry.sortable.role_id}-${entry.sortable.group_index}`"
                  class="staffing-open-positions__item"
              >
                <span class="staffing-open-positions__name">{{ entry.name }}</span>
                <span class="staffing-open-positions__count staffing-open-positions__count--critical">
                  {{ entry.wanted }}
                </span>
              </li>
            </ul>
          </div>
        </template>
      </section>

      <section class="staffing-open-positions__section">
        <h3 class="staffing-open-positions__heading staffing-open-positions__heading--recommended">
          Zusätzlich empfohlen
        </h3>
        <p v-if="!hasNice" class="staffing-sidebar-muted staffing-open-positions__empty">
          Idealbesetzung erreicht.
        </p>
        <template v-else>
          <div
              v-for="scope in scopesWithEntries('nice')"
              :key="`nice-${scope.key}`"
              class="staffing-open-positions__scope"
          >
            <div class="staffing-open-positions__scope-label">
              <StaffingScopeLeading :filter-key="scope.key" size="chip" :boxed="false"/>
              <span>{{ scopeLabel(scope.key) }}</span>
            </div>
            <ul class="staffing-open-positions__list">
              <li
                  v-for="entry in scope.entries"
                  :key="`nice-${scope.key}-${entry.sortable.role_id}-${entry.sortable.group_index}`"
                  class="staffing-open-positions__item"
              >
                <span class="staffing-open-positions__name">{{ entry.name }}</span>
                <span class="staffing-open-positions__count staffing-open-positions__count--recommended">
                  {{ entry.wanted }}
                </span>
              </li>
            </ul>
          </div>
        </template>
      </section>
    </template>
  </div>
</template>

<style scoped>
.staffing-open-positions__section + .staffing-open-positions__section {
  margin-top: 0.85rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--liquid-border-soft);
}

.staffing-open-positions__heading {
  margin: 0 0 0.45rem;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.staffing-open-positions__heading--critical {
  color: #dc2626;
}

.staffing-open-positions__heading--recommended {
  color: #d97706;
}

.staffing-open-positions__empty {
  margin: 0;
}

.staffing-open-positions__scope + .staffing-open-positions__scope {
  margin-top: 0.55rem;
}

.staffing-open-positions__scope-label {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-bottom: 0.25rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.staffing-open-positions__list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.staffing-open-positions__item {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.2rem 0;
  font-size: 0.8125rem;
  line-height: 1.35;
}

.staffing-open-positions__name {
  min-width: 0;
  color: var(--color-text);
}

.staffing-open-positions__count {
  flex-shrink: 0;
  min-width: 1.25rem;
  font-variant-numeric: tabular-nums;
  font-weight: 700;
  text-align: right;
}

.staffing-open-positions__count--critical {
  color: #dc2626;
}

.staffing-open-positions__count--recommended {
  color: #d97706;
}
</style>
