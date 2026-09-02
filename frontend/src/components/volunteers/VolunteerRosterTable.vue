<script setup lang="ts">
import axios from 'axios'
import {ref} from 'vue'
import {RouterLink} from 'vue-router'
import {useEventStore} from '@/stores/event'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {programNameForId} from '@/utils/eventPrograms'
import {volunteerDisplayName} from '@/utils/volunteerPerson'
import {T_SHIRT_CUTS} from '@/volunteers/rosterConstants'
import {defaultRosterDetail, type RosterAssignment, type RosterEntry} from '@/volunteers/rosterTypes'
import type {VolunteerMealOption} from '@/composables/useVolunteerMealOptions'
import type {RosterColumnMeta} from '@/volunteers/columns/rosterColumns'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import {
  photoConsentStatusClass,
  photoConsentStatusForVolunteer,
} from '@/utils/photoConsentStatus'

const props = defineProps<{
  eventId?: number | null
  entries: RosterEntry[]
  columns: RosterColumnMeta[]
  mealOptions: VolunteerMealOption[]
  sortKey: 'name' | 'role'
  sortDir: 'asc' | 'desc'
  togglingId: number | null
}>()

const emit = defineEmits<{
  'toggle-sort': [key: 'name' | 'role']
  'request-remove': [entry: RosterEntry]
  'open-shirt': [entry: RosterEntry, anchor: HTMLElement]
}>()

const eventStore = useEventStore()
const savingEntryId = ref<number | null>(null)

function columnColClass(column: RosterColumnMeta) {
  if (column.kind === 'custom') {
    return column.type === 'text' ? 'vol-col--custom-text' : 'vol-col--custom'
  }

  const classes: Record<string, string> = {
    name: 'vol-col--name',
    role: 'vol-col--role',
    t_shirt: 'vol-col--tshirt',
    meal: 'vol-col--meal',
    photo_consent: 'vol-col--photo',
  }
  return classes[column.key] ?? 'vol-col--custom'
}

function personListLink(person: RosterEntry['person']) {
  return {
    name: 'volunteers-people',
    query: {q: volunteerDisplayName(person)},
  } as const
}

function isSortableRosterColumn(key: string): key is 'name' | 'role' {
  return key === 'name' || key === 'role'
}

function sortIcon(key: 'name' | 'role') {
  if (props.sortKey !== key) return 'bi-arrow-down-up'
  return props.sortDir === 'asc' ? 'bi-sort-up' : 'bi-sort-down'
}

function rosterIconTooltip(entry: RosterEntry) {
  if (entry.has_assignment) {
    return 'Von Helfer:innenliste entfernen — Zuordnungen werden ebenfalls entfernt'
  }
  return 'Von Helfer:innenliste entfernen'
}

function assignmentProgramRef(assignment: RosterAssignment) {
  if (!assignment.first_program) return null
  return {
    first_program: assignment.first_program,
    name: programNameForId(eventStore.selectedEvent, assignment.first_program),
  }
}

function entryCustom(entry: RosterEntry) {
  if (!entry.custom) entry.custom = {}
  return entry.custom
}

function customValue(entry: RosterEntry, fieldKey: string) {
  return entryCustom(entry)[fieldKey] ?? null
}

function entryDetail(entry: RosterEntry) {
  if (!entry.detail) entry.detail = defaultRosterDetail()
  return entry.detail
}

function tShirtLabel(entry: RosterEntry) {
  const detail = entryDetail(entry)
  if (!detail.t_shirt_cut || !detail.t_shirt_size) return '?'
  const cutLabel = T_SHIRT_CUTS.find((c) => c.value === detail.t_shirt_cut)?.label ?? detail.t_shirt_cut
  return `${cutLabel} ${detail.t_shirt_size}`
}

function isSaving(entry: RosterEntry) {
  return savingEntryId.value === entry.id
}

async function saveDetail(entry: RosterEntry) {
  if (!props.eventId) return
  const detail = entryDetail(entry)
  savingEntryId.value = entry.id
  try {
    const {data} = await axios.patch(
      `/events/${props.eventId}/volunteer-roster/${entry.person.id}/detail`,
      {
        t_shirt_cut: detail.t_shirt_cut,
        t_shirt_size: detail.t_shirt_size,
        meal: detail.meal,
        photo_consent: detail.photo_consent,
      },
    )
    entry.detail = data.detail ?? detail
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    if (savingEntryId.value === entry.id) savingEntryId.value = null
  }
}

async function saveCustom(entry: RosterEntry, fieldKey: string, value: string | number | boolean | null) {
  if (!props.eventId) return
  savingEntryId.value = entry.id
  try {
    const {data} = await axios.patch(
      `/events/${props.eventId}/volunteer-roster/${entry.person.id}/custom`,
      {fields: {[fieldKey]: value}},
    )
    if (data.custom) {
      entry.custom = {...data.custom}
    }
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    if (savingEntryId.value === entry.id) savingEntryId.value = null
  }
}

function setPhotoConsent(entry: RosterEntry, value: boolean | null) {
  const detail = entryDetail(entry)
  if (detail.photo_consent === value) return
  detail.photo_consent = value
  void saveDetail(entry)
}

function setCustomBoolean(entry: RosterEntry, fieldKey: string, value: boolean | null) {
  if (customValue(entry, fieldKey) === value) return
  entryCustom(entry)[fieldKey] = value
  void saveCustom(entry, fieldKey, value)
}
</script>

<template>
  <div class="vol-table-frame vol-table-frame--scroll vol-table-frame--roster">
    <table class="vol-table vol-table--roster">
      <colgroup>
        <col class="vol-col--roster">
        <col
            v-for="column in columns"
            :key="`roster-col-${column.key}`"
            :class="columnColClass(column)"
        >
      </colgroup>
      <thead>
        <tr>
          <th class="vol-table__roster vol-table__sticky vol-table__sticky--roster" scope="col">
            <span class="sr-only">Helfer:innenliste</span>
          </th>
          <th
              v-for="column in columns"
              :key="column.key"
              scope="col"
              :class="{
                'vol-table__name': column.key === 'name',
                'vol-table__sticky': column.key === 'name',
                'vol-table__sticky--name': column.key === 'name',
              }"
          >
            <button
                v-if="column.sortable && isSortableRosterColumn(column.key)"
                type="button"
                class="vol-sort"
                :class="{'vol-sort--active': sortKey === column.key}"
                @click="emit('toggle-sort', column.key)"
            >
              {{ column.label }}
              <i class="bi" :class="sortIcon(column.key)" aria-hidden="true"/>
            </button>
            <span v-else>{{ column.label }}</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="entry in entries" :key="entry.id" class="glass-table-row--hover">
          <td class="vol-table__roster vol-table__sticky vol-table__sticky--roster">
            <button
                type="button"
                class="vol-roster-icon vol-roster-icon--on"
                :disabled="togglingId === entry.person.id"
                :aria-label="rosterIconTooltip(entry)"
                @click="emit('request-remove', entry)"
            >
              <i class="bi bi-clipboard-check-fill vol-roster-icon__glyph" aria-hidden="true"/>
              <span class="vol-roster-icon__tip glass-dropdown" role="tooltip">
                {{ rosterIconTooltip(entry) }}
              </span>
            </button>
          </td>
          <template v-for="column in columns" :key="`${entry.id}-${column.key}`">
            <td
                v-if="column.key === 'name'"
                class="vol-table__name vol-table__sticky vol-table__sticky--name"
            >
              <RouterLink :to="personListLink(entry.person)" class="vol-table__name-link">
                {{ volunteerDisplayName(entry.person) }}
              </RouterLink>
            </td>
            <td v-else-if="column.key === 'role'" class="vol-table__role">
              <div v-if="entry.assignments?.length" class="vol-table__assignments">
                <div
                    v-for="(assignment, idx) in entry.assignments"
                    :key="`${entry.id}-assignment-${idx}`"
                    class="vol-table__assignment"
                >
                  <ProgramLogo
                      v-if="assignmentProgramRef(assignment)"
                      :program="assignmentProgramRef(assignment)!"
                      size="chip"
                      decorative
                      class="vol-table__assignment-icon"
                  />
                  <span>{{ assignment.tile_name }}</span>
                </div>
              </div>
              <span v-else>—</span>
            </td>
            <td v-else-if="column.editor === 't_shirt'" class="vol-table__field">
              <button
                  type="button"
                  class="vol-detail-trigger glass-input glass-input--sm"
                  :class="{'vol-detail-trigger--unset': !entryDetail(entry).t_shirt_cut || !entryDetail(entry).t_shirt_size}"
                  :disabled="isSaving(entry)"
                  @click="emit('open-shirt', entry, $event.currentTarget as HTMLElement)"
              >
                {{ tShirtLabel(entry) }}
              </button>
            </td>
            <td v-else-if="column.editor === 'meal'" class="vol-table__field">
              <select
                  class="select-input vol-detail-select vol-detail-select--full"
                  :value="entryDetail(entry).meal ?? ''"
                  :disabled="isSaving(entry)"
                  @change="entryDetail(entry).meal = ($event.target as HTMLSelectElement).value || null; saveDetail(entry)"
              >
                <option value="">?</option>
                <option v-for="meal in mealOptions" :key="meal.value" :value="meal.value">{{ meal.label }}</option>
              </select>
            </td>
            <td
                v-else-if="column.editor === 'photo_consent'"
                class="vol-table__field vol-table__field--photo"
            >
              <div
                  class="glass-segment vol-tristate"
                  :class="photoConsentStatusClass(photoConsentStatusForVolunteer(entryDetail(entry).photo_consent).status)"
                  role="group"
                  :aria-label="column.label"
              >
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': entryDetail(entry).photo_consent === null}"
                    :aria-pressed="entryDetail(entry).photo_consent === null"
                    :disabled="isSaving(entry)"
                    @click="setPhotoConsent(entry, null)"
                >
                  ?
                </button>
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': entryDetail(entry).photo_consent === true}"
                    :aria-pressed="entryDetail(entry).photo_consent === true"
                    :disabled="isSaving(entry)"
                    @click="setPhotoConsent(entry, true)"
                >
                  Ja
                </button>
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': entryDetail(entry).photo_consent === false}"
                    :aria-pressed="entryDetail(entry).photo_consent === false"
                    :disabled="isSaving(entry)"
                    @click="setPhotoConsent(entry, false)"
                >
                  Nein
                </button>
              </div>
            </td>
            <td v-else-if="column.kind === 'custom' && column.field_key" class="vol-table__field">
              <input
                  v-if="column.type === 'text'"
                  type="text"
                  class="glass-input glass-input--sm vol-detail-input"
                  :value="(customValue(entry, column.field_key) as string | null) ?? ''"
                  :disabled="isSaving(entry)"
                  @change="entryCustom(entry)[column.field_key] = ($event.target as HTMLInputElement).value.trim() || null; saveCustom(entry, column.field_key, entryCustom(entry)[column.field_key] ?? null)"
              >
              <input
                  v-else-if="column.type === 'number'"
                  type="number"
                  class="glass-input glass-input--sm vol-detail-input vol-detail-input--number"
                  :value="customValue(entry, column.field_key) ?? ''"
                  :disabled="isSaving(entry)"
                  @change="saveCustom(entry, column.field_key, ($event.target as HTMLInputElement).value.trim() || null)"
              >
              <select
                  v-else-if="column.type === 'select'"
                  class="select-input vol-detail-select vol-detail-select--full"
                  :value="(customValue(entry, column.field_key) as string | null) ?? ''"
                  :disabled="isSaving(entry)"
                  @change="saveCustom(entry, column.field_key, ($event.target as HTMLSelectElement).value || null)"
              >
                <option value="">?</option>
                <option v-for="option in column.options ?? []" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <div
                  v-else-if="column.type === 'boolean'"
                  class="glass-segment vol-tristate"
                  role="group"
                  :aria-label="column.label"
              >
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': customValue(entry, column.field_key) === null}"
                    :aria-pressed="customValue(entry, column.field_key) === null"
                    :disabled="isSaving(entry)"
                    @click="setCustomBoolean(entry, column.field_key, null)"
                >
                  ?
                </button>
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': customValue(entry, column.field_key) === true}"
                    :aria-pressed="customValue(entry, column.field_key) === true"
                    :disabled="isSaving(entry)"
                    @click="setCustomBoolean(entry, column.field_key, true)"
                >
                  Ja
                </button>
                <button
                    type="button"
                    class="glass-segment__btn"
                    :class="{'glass-segment__btn--active': customValue(entry, column.field_key) === false}"
                    :aria-pressed="customValue(entry, column.field_key) === false"
                    :disabled="isSaving(entry)"
                    @click="setCustomBoolean(entry, column.field_key, false)"
                >
                  Nein
                </button>
              </div>
            </td>
          </template>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.vol-table-frame--roster {
  --vol-roster-sticky-roster-width: 2.75rem;
}

.vol-table--roster {
  width: max-content;
  min-width: 100%;
  table-layout: auto;
}

.vol-col--roster {
  width: var(--vol-roster-sticky-roster-width);
}

.vol-col--name { min-width: 11rem; }
.vol-col--role { min-width: 10rem; }
.vol-col--tshirt { min-width: 6.5rem; }
.vol-col--meal { min-width: 7rem; }
.vol-col--photo { min-width: 8rem; }
.vol-col--custom { min-width: 7.5rem; }
.vol-col--custom-text { min-width: 14rem; }

.vol-table__sticky {
  position: sticky;
}

.vol-table-frame--roster thead .vol-table__sticky {
  background: color-mix(in srgb, var(--color-bg-muted) 88%, #fff);
  backdrop-filter: blur(8px);
}

.vol-table-frame--roster tbody .vol-table__sticky {
  background: color-mix(in srgb, #ffffff 92%, var(--color-bg-muted));
}

.vol-table__sticky--roster {
  left: 0;
  z-index: 2;
}

.vol-table__sticky--name {
  left: var(--vol-roster-sticky-roster-width);
  z-index: 2;
  box-shadow: 4px 0 8px -4px rgba(15, 23, 42, 0.14);
}

.vol-table-frame--roster thead th.vol-table__sticky {
  z-index: 4;
}

.vol-table-frame--roster tbody tr.glass-table-row--hover:hover .vol-table__sticky {
  background: var(--color-bg-hover);
}

.vol-table__name-link {
  font-weight: 600;
  color: var(--color-accent);
  text-decoration: none;
}

.vol-table__name-link:hover {
  text-decoration: underline;
}

.vol-table__name {
  font-weight: 600;
}

.vol-table__role {
  color: var(--color-text-muted);
}

.vol-table__assignments {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.vol-table__assignment {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
}

.vol-table__assignment-icon {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
  object-fit: contain;
}

.vol-table__field {
  vertical-align: middle;
}

.vol-detail-trigger {
  width: 100%;
  min-width: 5.5rem;
  text-align: left;
  cursor: pointer;
}

.vol-detail-trigger--unset {
  color: var(--color-text-subtle);
  text-align: center;
}

.vol-detail-trigger:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.vol-detail-select,
.vol-detail-input {
  width: 100%;
  min-width: 0;
  font-size: 0.8125rem;
}

.vol-table__field select.select-input {
  box-sizing: border-box;
  min-height: var(--field-min-height-sm);
  height: var(--field-min-height-sm);
  padding: var(--field-padding-y-sm) 2rem var(--field-padding-y-sm) var(--field-padding-x-sm);
  font-size: var(--field-font-size-sm);
  border-radius: var(--field-radius-sm);
  line-height: 1.4;
}

.vol-detail-input--number {
  -moz-appearance: textfield;
  appearance: textfield;
}

.vol-detail-input--number::-webkit-outer-spin-button,
.vol-detail-input--number::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.vol-detail-select--full {
  min-width: 6.5rem;
}

.vol-tristate {
  display: inline-flex;
  width: 100%;
  min-width: 7.5rem;
}

.vol-tristate .glass-segment__btn {
  flex: 1;
  padding: 0.2rem 0.35rem;
  font-size: 0.75rem;
  line-height: 1.3;
}

.vol-tristate .glass-segment__btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.vol-table__field--photo {
  border-radius: var(--field-radius-sm, 0.375rem);
}

.vol-table__field--photo .vol-tristate.photo-consent--pending,
.vol-table__field--photo .vol-tristate.photo-consent--granted,
.vol-table__field--photo .vol-tristate.photo-consent--denied {
  border-color: color-mix(in srgb, currentColor 12%, var(--color-border));
}
</style>
