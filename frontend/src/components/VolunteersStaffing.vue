<script setup lang="ts">
import {computed, nextTick, ref, watch} from 'vue'
import axios from 'axios'
import draggable from 'vuedraggable'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import ItemComposer from '@/components/molecules/ItemComposer.vue'
import VolunteerEmailOutreach from '@/components/molecules/VolunteerEmailOutreach.vue'
import VolunteerStaffingFilterBar from '@/components/molecules/VolunteerStaffingFilterBar.vue'
import VolunteerStaffingBoundsPopover from '@/components/volunteers/VolunteerStaffingBoundsPopover.vue'
import VolunteerStaffingTile from '@/components/volunteers/VolunteerStaffingTile.vue'
import {eventPrograms} from '@/utils/eventPrograms'
import {compareStaffingTiles, staffingSortableFromTile} from '@/utils/volunteerStaffingSort'
import {
  buildStaffingFilterKeys,
  staffingFilterKeyFromScope,
  syncStaffingFilters,
  toggleStaffingFilter,
  type StaffingFilterKey,
} from '@/utils/volunteerStaffingFilters'
import {type VolunteerPersonRef, volunteerDisplayName, volunteerSearchHaystack} from '@/utils/volunteerPerson'
import {
  boundsValidationError,
  tileNeedsAttention,
  type StaffingGroup,
  type StaffingRole,
  type StaffingTile,
} from '@/volunteers/staffingTypes'

defineOptions({name: 'VolunteersStaffing'})

type Person = VolunteerPersonRef
type Group = StaffingGroup
type Role = StaffingRole
type Tile = StaffingTile

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roles = ref<Role[]>([])
const roster = ref<{person: Person; has_assignment: boolean}[]>([])
const pool = ref<Person[]>([])
const personSearch = ref('')
const planId = ref<number | null>(null)
const loading = ref(false)
const isSaving = ref(false)

const isDragging = ref(false)
const dragOverGroupId = ref<number | null>(null)
const dragSourceGroupId = ref<number | null>(null)
const draggedPerson = ref<Person | null>(null)

const roleToDelete = ref<Role | null>(null)
const boundsEditRole = ref<Role | null>(null)
const boundsAnchorEl = ref<HTMLElement | null>(null)
const pickPerson = ref<Person | null>(null)
const composerRef = ref<{focusTitle?: () => void} | null>(null)

const newRoleName = ref('')
const newRoleMin = ref<number | ''>('')
const newRoleBest = ref<number | ''>('')
const newRoleMax = ref<number | ''>('')

const activeTileFilters = ref<Set<StaffingFilterKey>>(new Set())

const programFilters = computed(() => eventPrograms(eventStore.selectedEvent))

const tiles = computed<Tile[]>(() => {
  const list = roles.value.flatMap((role) =>
    role.groups
      .filter((group) => !(group.surplus && group.people.length === 0))
      .map((group) => ({
        key: `${role.id}-${group.id}`,
        role,
        group,
        name: groupTitle(role, group),
      })),
  )
  return [...list].sort((a, b) =>
    compareStaffingTiles(
      staffingSortableFromTile(a),
      staffingSortableFromTile(b),
      eventStore.selectedEvent?.programs,
    ),
  )
})

const filteredTiles = computed(() => {
  if (activeTileFilters.value.size === 0) return []
  return tiles.value.filter((tile) => activeTileFilters.value.has(tileFilterKey(tile)))
})

const visibleTilePeople = computed(() => {
  const byId = new Map<number, Person>()
  for (const tile of filteredTiles.value) {
    for (const person of tile.group.people) {
      if (!byId.has(person.id)) byId.set(person.id, person)
    }
  }
  return [...byId.values()].sort(sortPeople)
})

const assignedIds = computed(() => {
  const ids = new Set<number>()
  for (const role of roles.value) {
    for (const group of role.groups) {
      for (const person of group.people) ids.add(person.id)
    }
  }
  return ids
})

const catalogAssignedIds = computed(() => {
  const ids = new Set<number>()
  for (const role of roles.value) {
    if (role.is_local) continue
    for (const group of role.groups) {
      for (const person of group.people) ids.add(person.id)
    }
  }
  return ids
})

const unassignedPeople = computed(() =>
  roster.value
    .map((entry) => entry.person)
    .filter((person) => !assignedIds.value.has(person.id))
    .sort(sortPeople),
)

const rosterPool = ref<Person[]>([])
const searchDisplayPool = ref<Person[]>([])

const rosterPersonIds = computed(() => new Set(roster.value.map((entry) => entry.person.id)))

const personSearchMatches = computed(() => {
  const q = personSearch.value.trim().toLowerCase()
  if (!q) return []

  return pool.value
    .filter((p) => volunteerSearchHaystack(p).includes(q))
    .sort((a, b) => {
      const av = volunteerDisplayName(a).toLocaleLowerCase('de')
      const bv = volunteerDisplayName(b).toLocaleLowerCase('de')
      if (av < bv) return -1
      if (av > bv) return 1
      return a.id - b.id
    })
})

watch(unassignedPeople, (people) => {
  rosterPool.value = [...people]
}, {immediate: true})

watch(personSearchMatches, (matches) => {
  searchDisplayPool.value = [...matches]
}, {immediate: true})

const deleteRoleMessage = computed(() => {
  if (!roleToDelete.value) return ''
  const name = (roleToDelete.value.label || '').trim() || 'Unbenannt'
  return `„${name}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

const peopleGroup = {name: 'staffing-people', pull: true, put: false}
const searchDragGroup = {name: 'staffing-people', pull: 'clone', put: false}

function sortPeople(a: Person, b: Person) {
  const last = a.last_name.localeCompare(b.last_name, 'de')
  if (last !== 0) return last
  return a.first_name.localeCompare(b.first_name, 'de')
}

function isOnRoster(person: Person) {
  return rosterPersonIds.value.has(person.id)
}

function isAssigned(person: Person) {
  return assignedIds.value.has(person.id)
}

function canDragFromSearch(person: Person) {
  return !isAssigned(person)
}

function searchChipIconClass(person: Person) {
  if (isOnRoster(person)) {
    return 'bi-clipboard-check-fill staffing-search-chip__icon--roster'
  }
  return 'bi-person-fill'
}

function groupTitle(role: Role, group: Group) {
  if (role.groups.length <= 1 && !group.surplus) return role.label
  return `${role.label} ${group.group_index}`
}

function syncTileFilters() {
  activeTileFilters.value = syncStaffingFilters(
    activeTileFilters.value,
    buildStaffingFilterKeys(programFilters.value),
  )
}

function tileFilterKey(tile: Tile): StaffingFilterKey {
  return staffingFilterKeyFromScope(tile.role)
}

function onToggleTileFilter(key: StaffingFilterKey) {
  activeTileFilters.value = toggleStaffingFilter(activeTileFilters.value, key)
}

function filterHasAttention(key: StaffingFilterKey) {
  return tiles.value.some((tile) => tileFilterKey(tile) === key && tileNeedsAttention(tile))
}

function resolveRoleBounds(minRaw: number | '', bestRaw: number | '', maxRaw: number | '') {
  const isEmpty = (value: number | '') =>
    value === '' || value === null || value === undefined || Number.isNaN(Number(value))

  if (isEmpty(minRaw) && isEmpty(bestRaw) && isEmpty(maxRaw)) {
    return {min: 1, best: 1, max: 2}
  }

  return {
    min: Number(minRaw),
    best: Number(bestRaw),
    max: Number(maxRaw),
  }
}

function openBoundsModal(role: Role, anchor: HTMLElement) {
  boundsAnchorEl.value = anchor
  boundsEditRole.value = role
}

function closeBoundsModal() {
  boundsEditRole.value = null
  boundsAnchorEl.value = null
}

async function saveBoundsModal(bounds: {min: number; best: number; max: number}) {
  const role = boundsEditRole.value
  if (!role || isSaving.value) return
  isSaving.value = true
  try {
    role.min = bounds.min
    role.best = bounds.best
    role.max = bounds.max
    await persistLocalRole(role)
    closeBoundsModal()
  } finally {
    isSaving.value = false
  }
}

async function load() {
  if (!eventId.value) return
  loading.value = true
  try {
    const [staffingRes, rosterRes, poolRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/staffing`),
      axios.get(`/events/${eventId.value}/volunteer-roster`),
      axios.get(`/events/${eventId.value}/volunteers`),
    ])
    roles.value = staffingRes.data.roles ?? []
    planId.value = staffingRes.data.plan_id ?? null
    roster.value = rosterRes.data.roster ?? []
    pool.value = poolRes.data.people ?? []
    await eventStore.refreshReadiness(eventId.value)
  } catch (e: any) {
    showGlassToast(apiError(e, 'Laden fehlgeschlagen'), 'error')
  } finally {
    loading.value = false
  }
}

async function ensureOnRoster(person: Person) {
  if (!eventId.value || isOnRoster(person)) return
  await axios.post(`/events/${eventId.value}/volunteer-roster`, {
    volunteer_person: person.id,
  })
}

function onDragStart(event: any, groupId: number | null) {
  isDragging.value = true
  dragSourceGroupId.value = groupId
  draggedPerson.value = event.item?.__draggable_context?.element ?? null
}

function onDragEnd() {
  isDragging.value = false
  dragOverGroupId.value = null
  dragSourceGroupId.value = null
  draggedPerson.value = null
}

function onDropzoneLeave(event: DragEvent, groupId: number) {
  const next = event.relatedTarget as Node | null
  if (next && (event.currentTarget as Node)?.contains(next)) return
  if (dragOverGroupId.value === groupId) dragOverGroupId.value = null
}

async function handleDrop(event: any, group: Group) {
  const person = draggedPerson.value || event.item?.__draggable_context?.element
  dragOverGroupId.value = null
  isDragging.value = false
  if (!person?.id || !eventId.value) return
  if (dragSourceGroupId.value === group.id) return
  if (group.surplus) {
    showGlassToast('Diese Rolle wird nicht mehr benötigt — Personen nur umsetzen.', 'info')
    await load()
    return
  }
  if (group.filled >= group.max && !group.people.some((p) => p.id === person.id)) {
    showGlassToast('Maximum für diese Rolle erreicht.', 'info')
    await load()
    return
  }

  try {
    if (dragSourceGroupId.value) {
      await axios.delete(
        `/events/${eventId.value}/staffing/groups/${dragSourceGroupId.value}/assignments/${person.id}`,
      )
    }
    await ensureOnRoster(person)
    await axios.post(`/events/${eventId.value}/staffing/groups/${group.id}/assignments`, {
      volunteer_person: person.id,
    })
  } catch (e: any) {
    showGlassToast(apiError(e, 'Zuweisen fehlgeschlagen'), 'error')
  } finally {
    dragSourceGroupId.value = null
    draggedPerson.value = null
    await load()
  }
}

async function unassign(group: Group, person: Person) {
  if (!eventId.value) return
  try {
    await axios.delete(
      `/events/${eventId.value}/staffing/groups/${group.id}/assignments/${person.id}`,
    )
    await load()
  } catch (e: any) {
    showGlassToast(apiError(e, 'Entfernen fehlgeschlagen'), 'error')
  }
}

async function createLocalRole() {
  if (!eventId.value || isSaving.value) return
  const label = newRoleName.value.trim()
  const {min, best, max} = resolveRoleBounds(newRoleMin.value, newRoleBest.value, newRoleMax.value)
  if (!label) return
  const validationError = boundsValidationError(min, best, max)
  if (validationError) {
    showGlassToast(validationError, 'info')
    return
  }
  isSaving.value = true
  try {
    await axios.post(`/events/${eventId.value}/staffing/local-roles`, {
      label,
      min,
      best,
      max,
    })
    newRoleName.value = ''
    newRoleMin.value = ''
    newRoleBest.value = ''
    newRoleMax.value = ''
    await load()
    await nextTick()
    composerRef.value?.focusTitle?.()
  } catch (e: any) {
    showGlassToast(apiError(e, 'Anlegen fehlgeschlagen'), 'error')
  } finally {
    isSaving.value = false
  }
}

async function persistLocalRole(role: Role) {
  if (!eventId.value || !role.is_local) return
  const label = role.label.trim()
  if (!label) {
    showGlassToast('Name darf nicht leer sein.', 'info')
    await load()
    return
  }
  if (role.min > role.best || role.best > role.max) {
    const validationError = boundsValidationError(Number(role.min), Number(role.best), Number(role.max))
    if (validationError) {
      showGlassToast(validationError, 'info')
      await load()
      return
    }
  }
  try {
    await axios.put(`/events/${eventId.value}/staffing/local-roles/${role.id}`, {
      label,
      min: Number(role.min),
      best: Number(role.best),
      max: Number(role.max),
    })
    await load()
  } catch (e: any) {
    showGlassToast(apiError(e, 'Speichern fehlgeschlagen'), 'error')
    await load()
  }
}

function askDeleteRole(role: Role) {
  if (!role.is_local) return
  roleToDelete.value = role
}

function cancelDeleteRole() {
  roleToDelete.value = null
}

async function confirmDeleteRole() {
  const role = roleToDelete.value
  if (!eventId.value || !role?.is_local) return
  try {
    await axios.delete(`/events/${eventId.value}/staffing/local-roles/${role.id}`)
    roleToDelete.value = null
    await load()
  } catch (e: any) {
    showGlassToast(apiError(e, 'Löschen fehlgeschlagen'), 'error')
  }
}

function openAssignModal(person: Person) {
  pickPerson.value = person
}

function closeAssignModal() {
  pickPerson.value = null
}

async function assignPickedTo(group: Group) {
  const person = pickPerson.value
  if (!person) return
  draggedPerson.value = person
  dragSourceGroupId.value = null
  closeAssignModal()
  await handleDrop({item: {__draggable_context: {element: person}}}, group)
}

function assignableTilesFor(person: Person) {
  const onCatalog = catalogAssignedIds.value.has(person.id)
  return tiles.value.filter((tile) => {
    if (tile.group.surplus) return false
    if (tile.group.filled >= tile.group.max) return false
    if (tile.group.people.some((p) => p.id === person.id)) return false
    if (onCatalog) return false
    if (!tile.role.is_local && assignedIds.value.has(person.id)) return false
    return true
  })
}

watch(eventId, () => load(), {immediate: true})
watch(() => eventStore.selectedEvent?.id, () => syncTileFilters(), {immediate: true})
</script>

<template>
  <div class="vol-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Zuordnung</h1>
        <p class="vol-page__sub">Zuordnung der Helfer:innen auf die Rollen im Veranstaltungsplan</p>
      </div>
      <div class="vol-page__actions">
        <VolunteerEmailOutreach scope="roster" :people="visibleTilePeople"/>
      </div>
    </header>

    <div v-if="!planId && !loading" class="glass-alert-warning">
      Kein Plan vorhanden — zuerst Ablauf erzeugen.
    </div>

    <div
        v-else-if="loading && !roles.length"
        class="flex items-center justify-center min-h-[400px] flex-col text-[var(--color-text-muted)]"
    >
      <LoaderFlow/>
      <LoaderText/>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-4 gap-4 lg:gap-5">
      <div class="lg:col-span-3 order-2 lg:order-1">
        <p v-if="!tiles.length" class="text-sm text-[var(--color-text-subtle)] mb-3">
          Noch keine Rollen. Rollen werden beim Erzeugen des Ablaufs angelegt — oder links eine eigene Rolle anlegen.
        </p>

        <VolunteerStaffingFilterBar
            v-if="tiles.length"
            card
            :active-filters="activeTileFilters"
            :programs="programFilters"
            :has-attention="filterHasAttention"
            @toggle="onToggleTileFilter"
        />

        <p v-if="tiles.length && !filteredTiles.length" class="text-sm text-[var(--color-text-subtle)] mb-3">
          Keine Rollen für die gewählten Filter.
        </p>

        <div v-if="filteredTiles.length" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 md:gap-4">
          <VolunteerStaffingTile
              v-for="tile in filteredTiles"
              :key="tile.key"
              :tile="tile"
              :is-dragging="isDragging"
              :drag-over-group-id="dragOverGroupId"
              @persist-role="persistLocalRole"
              @delete-role="askDeleteRole"
              @open-bounds="openBoundsModal"
              @drop="handleDrop"
              @drag-start="onDragStart"
              @drag-end="onDragEnd"
              @dropzone-leave="onDropzoneLeave"
              @hover-group="dragOverGroupId = $event"
              @unassign="unassign"
          />

          <ItemComposer
              ref="composerRef"
              v-model:title="newRoleName"
              :disabled="isSaving || !planId"
              title-placeholder="Neue Rolle z. B. Check-in"
              empty-hint="Eigene Rolle für diese Veranstaltung, unabhängig vom Plan."
              @commit="createLocalRole"
          >
            <transition name="fade">
              <div v-if="newRoleName.trim().length > 0" class="staffing-composer-extra">
                <div class="staffing-bounds staffing-bounds--composer">
                  <label class="staffing-bounds__field">
                    <span>min</span>
                    <input
                        v-model.number="newRoleMin"
                        :disabled="isSaving"
                        class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                        type="number"
                        min="1"
                        placeholder="1"
                    />
                  </label>
                  <label class="staffing-bounds__field">
                    <span>ideal</span>
                    <input
                        v-model.number="newRoleBest"
                        :disabled="isSaving"
                        class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                        type="number"
                        min="1"
                        placeholder="1"
                    />
                  </label>
                  <label class="staffing-bounds__field">
                    <span>max</span>
                    <input
                        v-model.number="newRoleMax"
                        :disabled="isSaving"
                        class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                        type="number"
                        min="1"
                        placeholder="2"
                    />
                  </label>
                </div>
                <p class="item-card__hint">min ≤ ideal ≤ max — wie viele Personen diese Rolle braucht.</p>
              </div>
            </transition>
          </ItemComposer>
        </div>
      </div>

      <div class="lg:col-span-1 order-1 lg:order-2 space-y-3 md:space-y-4 lg:sticky lg:top-4 self-start">
        <div class="glass-card liquid-surface-inner staffing-sidebar-tile">
          <input
              v-model="personSearch"
              type="search"
              class="glass-input glass-input--sm staffing-search__input"
              placeholder="Personen suchen…"
              autocomplete="off"
          />
          <div v-if="personSearch.trim()" class="staffing-search-results">
            <p v-if="!personSearchMatches.length" class="staffing-sidebar-muted">
              Keine Treffer in der Personenliste.
            </p>
            <div v-else class="staffing-search-chips">
              <div class="staffing-search-chips__desktop hidden md:block">
                <draggable
                    :list="searchDisplayPool"
                    class="flex flex-wrap gap-1.5 md:gap-2"
                    :group="searchDragGroup"
                    :sort="false"
                    filter=".staffing-search-chip--static"
                    item-key="id"
                    @start="onDragStart($event, null)"
                    @end="onDragEnd"
                >
                  <template #item="{element: person}">
                    <span
                        class="glass-row-item staffing-search-chip"
                        :class="canDragFromSearch(person)
                          ? 'glass-row-item--interactive staffing-search-chip--draggable cursor-move'
                          : 'staffing-search-chip--static'"
                    >
                      <i
                          class="bi staffing-search-chip__icon"
                          :class="searchChipIconClass(person)"
                          aria-hidden="true"
                      />
                      <span class="staffing-search-chip__label">{{ volunteerDisplayName(person) }}</span>
                    </span>
                  </template>
                </draggable>
              </div>

              <div class="staffing-search-chips__mobile flex flex-wrap gap-1.5 md:hidden">
                <button
                    v-for="person in personSearchMatches"
                    :key="`search-mobile-${person.id}`"
                    type="button"
                    class="glass-row-item staffing-search-chip"
                    :class="canDragFromSearch(person)
                      ? 'glass-row-item--interactive'
                      : 'staffing-search-chip--static'"
                    :disabled="!canDragFromSearch(person)"
                    @click="canDragFromSearch(person) && openAssignModal(person)"
                >
                  <i
                      class="bi staffing-search-chip__icon"
                      :class="searchChipIconClass(person)"
                      aria-hidden="true"
                  />
                  <span class="staffing-search-chip__label">{{ volunteerDisplayName(person) }}</span>
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="glass-card liquid-surface-inner staffing-sidebar-tile">
          <h2 class="glass-card__heading !mb-3 !text-sm md:!text-base">Helfer:innen ohne Zuordnung</h2>

          <p v-if="!roster.length" class="staffing-sidebar-muted">
            Noch niemand auf der Helferliste — unter Helferliste Personen hinzufügen.
          </p>
          <p v-else-if="!unassignedPeople.length" class="staffing-sidebar-muted">
            Alle auf der Helferliste sind zugewiesen.
          </p>

          <template v-else>
            <draggable
                :list="rosterPool"
                class="hidden md:flex flex-wrap gap-1.5 md:gap-2"
                :group="peopleGroup"
                item-key="id"
                @start="onDragStart($event, null)"
                @end="onDragEnd"
            >
              <template #item="{element: person}">
                <span class="glass-row-item glass-row-item--interactive text-[11px] md:text-xs cursor-move">
                  <i class="bi bi-person-fill text-[var(--color-text-subtle)]"/>
                  <span class="px-1.5 py-1">{{ volunteerDisplayName(person) }}</span>
                </span>
              </template>
            </draggable>

            <div class="md:hidden flex flex-wrap gap-1.5">
              <button
                  v-for="person in unassignedPeople"
                  :key="`mobile-${person.id}`"
                  type="button"
                  class="glass-row-item text-[11px]"
                  @click="openAssignModal(person)"
              >
                <i class="bi bi-person-fill text-[var(--color-text-subtle)]"/>
                <span class="px-1.5 py-1">{{ volunteerDisplayName(person) }}</span>
              </button>
            </div>
          </template>
        </div>
      </div>
    </div>

    <ConfirmationModal
        :show="!!roleToDelete"
        title="Rolle löschen"
        :message="deleteRoleMessage"
        type="danger"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        @confirm="confirmDeleteRole"
        @cancel="cancelDeleteRole"
    />

    <VolunteerStaffingBoundsPopover
        :role="boundsEditRole"
        :anchor="boundsAnchorEl"
        :saving="isSaving"
        @close="closeBoundsModal"
        @save="saveBoundsModal"
    />

    <div
        v-if="pickPerson"
        class="glass-scrim fixed inset-0 z-50 flex items-end md:hidden"
        @click="closeAssignModal"
    >
      <div
          class="w-full max-h-[70vh] overflow-y-auto rounded-t-[var(--radius-xl)] border border-[var(--liquid-border)] bg-[var(--liquid-popover-fill)] backdrop-blur-[var(--liquid-popover-blur)] p-4 shadow-[var(--shadow-lg)]"
          @click.stop
      >
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-[var(--color-text)]">Zu Rolle zuweisen</h3>
          <button
              type="button"
              class="text-[var(--color-text-subtle)] hover:text-[var(--color-text)]"
              @click="closeAssignModal"
          >
            ✕
          </button>
        </div>
        <div class="text-xs text-[var(--color-text-muted)] mb-3 truncate">
          {{ volunteerDisplayName(pickPerson) }}
        </div>
        <div class="space-y-2">
          <button
              v-for="tile in assignableTilesFor(pickPerson)"
              :key="`assign-${tile.key}`"
              type="button"
              class="w-full text-left px-3 py-2.5 liquid-surface-inner rounded-[var(--radius)] hover:bg-[var(--color-bg-hover)] transition-colors"
              @click="assignPickedTo(tile.group)"
          >
            <div class="font-medium text-sm text-[var(--color-text)]">{{ tile.name }}</div>
            <div class="text-xs text-[var(--color-text-subtle)]">
              {{ tile.group.filled }} / max {{ tile.group.max }}
            </div>
          </button>
          <p v-if="!assignableTilesFor(pickPerson).length" class="text-sm text-[var(--color-text-subtle)]">
            Keine freie Rolle für diese Person.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.staffing-bounds {
  display: flex;
  align-items: flex-end;
  gap: 0.3rem;
  flex-shrink: 0;
}

.staffing-bounds--composer {
  width: 100%;
}

.staffing-bounds__field {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  font-size: 0.65rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-subtle);
}

.staffing-bounds__input {
  width: 3.1rem;
  padding-left: 0.35rem !important;
  padding-right: 0.35rem !important;
  text-align: center;
}

.staffing-composer-extra {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.staffing-tile--surplus {
  border-color: color-mix(in srgb, #dc2626 42%, var(--color-border));
  background: color-mix(in srgb, #fecaca 26%, var(--color-bg-muted));
}

.staffing-stale-badge {
  flex-shrink: 0;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, #dc2626 14%, transparent);
  color: #b91c1c;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.staffing-surplus {
  margin: 0;
  font-size: 0.75rem;
  line-height: 1.35;
  color: #b91c1c;
  font-weight: 600;
}

.glass-dropzone--blocked {
  border-style: dashed;
  border-color: color-mix(in srgb, #dc2626 35%, var(--color-border));
  background: color-mix(in srgb, #fecaca 28%, var(--color-bg-muted));
}

.staffing-sidebar-tile {
  padding: 0.75rem;
}

@media (min-width: 768px) {
  .staffing-sidebar-tile {
    padding: 1rem;
  }
}

.staffing-sidebar-muted {
  margin: 0;
  font-size: 0.875rem;
  color: var(--color-text-subtle);
}

.staffing-search__input {
  width: 100%;
}

.staffing-search-results {
  margin-top: 0.75rem;
}

.staffing-search-chips {
  display: block;
}

.staffing-search-chip {
  font-size: 0.75rem;
  padding: 0.35rem 0.5rem;
  gap: 0.4rem;
}

.staffing-search-chip--static {
  opacity: 0.72;
  cursor: default;
}

.staffing-search-chip:disabled {
  opacity: 0.72;
  cursor: default;
}

.staffing-search-chip__icon {
  color: var(--color-text-subtle);
}

.staffing-search-chip__icon--roster {
  color: var(--color-accent);
}

.staffing-search-chip__label {
  padding: 0;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
