<script setup lang="ts">
import {computed} from 'vue'
import {RouterLink} from 'vue-router'
import StaffingScopeLeading from '@/components/volunteers/StaffingScopeLeading.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import {useEventStore} from '@/stores/event'
import {usePublicHelperSearch} from '@/composables/usePublicHelperSearch'
import {eventPrograms, programDisplayName, programId, programNameForId} from '@/utils/eventPrograms'
import {type StaffingFilterKey} from '@/utils/volunteerStaffingFilters'
import {
  openPositionsCriticalCount,
  openPositionsFromApi,
  openPositionsRecommendedCount,
  type OpenPositionApiScope,
  type OpenPositionEntry,
  type OpenPositionScopeGroup,
} from '@/utils/volunteerOpenPositions'

const props = defineProps<{
  openPositions: ReadonlyArray<OpenPositionApiScope> | null | undefined
}>()

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id ?? null)

const {
  enabled: helperSearchEnabled,
  loading: helperSearchLoading,
  setEnabled: setHelperSearchEnabled,
} = usePublicHelperSearch(eventId)

const programs = computed(() => eventPrograms(eventStore.selectedEvent))

const scopes = computed(() => openPositionsFromApi(props.openPositions, programs.value))

const hasCritical = computed(() => openPositionsCriticalCount(scopes.value) > 0)
const hasRecommended = computed(() => openPositionsRecommendedCount(scopes.value) > 0)
const isEmpty = computed(() => !hasCritical.value && !hasRecommended.value)

function scopeLabel(key: StaffingFilterKey) {
  if (key === 'cross') return 'Übergreifend'
  if (key === 'local') return 'Zusätzlich'
  const id = Number(key.slice('program:'.length))
  if (!Number.isInteger(id) || id <= 0) return 'Programm'
  const program = programs.value.find((row) => programId(row) === id)
  return programDisplayName(program ?? {first_program: id, name: programNameForId(eventStore.selectedEvent, id)})
}

function scopesWithEntries(
  section: 'critical' | 'recommended',
): Array<OpenPositionScopeGroup & {entries: OpenPositionEntry[]}> {
  return scopes.value
    .map((scope) => ({
      ...scope,
      entries: section === 'critical' ? scope.critical : scope.recommended,
    }))
    .filter((scope) => scope.entries.length > 0)
}

async function onHelperSearchToggle(next: boolean) {
  try {
    await setHelperSearchEnabled(next)
  } catch {
    // toast from composable
  }
}
</script>

<template>
  <div class="glass-card liquid-surface-inner vol-sidebar-tile staffing-open-positions">
    <h2 class="vol-sidebar-heading">Offene Positionen</h2>

    <section class="vol-roster-publish staffing-open-positions__publish">
      <div class="vol-roster-publish__row">
        <span class="vol-roster-publish__label">Suche nach Helfer:innen</span>
        <ToggleSwitch
            :model-value="helperSearchEnabled"
            :disabled="helperSearchLoading || !eventId"
            @update:modelValue="onHelperSearchToggle"
        />
      </div>
      <p class="glass-settings-hint !mb-0 vol-roster-publish__hint">
        Offene Positionen können auf dem öffentlichen Plan erscheinen. Einstellungen unter
        <RouterLink to="/plan/publish" class="vol-roster-publish__link">
          Ausgabe → Veröffentlichung
        </RouterLink>.
      </p>
    </section>

    <p v-if="isEmpty" class="vol-sidebar-muted">
      Alle Rollen sind ideal besetzt.
    </p>

    <template v-else>
      <section class="staffing-open-positions__section">
        <h3 class="staffing-open-positions__heading staffing-open-positions__heading--critical">
          Kritisch
        </h3>
        <p v-if="!hasCritical" class="vol-sidebar-muted staffing-open-positions__empty">
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
                  :key="`critical-${scope.key}-${entry.roleId}`"
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
        <p v-if="!hasRecommended" class="vol-sidebar-muted staffing-open-positions__empty">
          Idealbesetzung erreicht.
        </p>
        <template v-else>
          <div
              v-for="scope in scopesWithEntries('recommended')"
              :key="`recommended-${scope.key}`"
              class="staffing-open-positions__scope"
          >
            <div class="staffing-open-positions__scope-label">
              <StaffingScopeLeading :filter-key="scope.key" size="chip" :boxed="false"/>
              <span>{{ scopeLabel(scope.key) }}</span>
            </div>
            <ul class="staffing-open-positions__list">
              <li
                  v-for="entry in scope.entries"
                  :key="`recommended-${scope.key}-${entry.roleId}`"
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
.staffing-open-positions__publish {
  margin-bottom: 0.85rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid var(--liquid-border-soft);
}

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
  color: var(--color-danger, #dc2626);
}

.staffing-open-positions__heading--recommended {
  color: var(--color-warning, #d97706);
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
  color: var(--color-danger, #dc2626);
}

.staffing-open-positions__count--recommended {
  color: var(--color-warning, #d97706);
}
</style>
