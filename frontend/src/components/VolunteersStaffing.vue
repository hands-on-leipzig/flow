<script setup lang="ts">
import {computed, nextTick, ref, watch} from 'vue'
import {RouterLink} from 'vue-router'
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
import VolunteerOpenPositions from '@/components/volunteers/VolunteerOpenPositions.vue'
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
import {staffingContainerTitle, staffingTileKey} from '@/volunteers/staffingLabel'
import {
  boundsValidationError,
  tileFilled,
  tileNeedsAttention,
  tilePeople,
  tileSurplus,
  type StaffingRole,
  type StaffingTile,
} from '@/volunteers/staffingTypes'
import {computeStaffingSummary} from '@/utils/volunteerStaffingSummary'
import {type OpenPositionApiScope} from '@/utils/volunteerOpenPositions'

defineOptions({name: 'VolunteersStaffing'})

type Person = VolunteerPersonRef
type Role = StaffingRole
type Tile = StaffingTile

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roles = ref<Role[]>([])
const openPositions = ref<OpenPositionApiScope[]>([])
const roster = ref<{person: Person; has_assignment: boolean}[]>([])
const pool = ref<Person[]>([])
const personSearch = ref('')
const planId = ref<number | null>(null)
const loading = ref(false)
const isSaving = ref(false)

const isDragging = ref(false)
const dragOverKey = ref<string | null>(null)
const dragSourceKey = ref<string | null>(null)
const draggedPerson = ref<Person | null>(null)

const roleToDelete = ref<Role | null>(null)
const boundsEditRole = ref<Role | null>(null)
const boundsAnchorEl = ref<HTMLElement | null>(null)
const composerRef = ref<{focusTitle?: () => void} | null>(null)

const newRoleName = ref('')
const newRoleMin = ref<number | ''>('')
const newRoleBest = ref<number | ''>('')
const newRoleMax = ref<number | ''>('')

const activeTileFilters = ref<Set<StaffingFilterKey>>(new Set())

const programFilters = computed(() => eventPrograms(eventStore.selectedEvent))

const staffingSummary = computed(() => computeStaffingSummary(roles.value, programFilters.value))

const tiles = computed<Tile[]>(() => {
  const list = roles.value.flatMap((role) => {
    if (role.grouped) {
      return (role.groups ?? [])
        .filter((group) => !(group.surplus && group.people.length === 0))
        .map((group) => ({
          key: staffingTileKey(role.id, group.id),
          role,
          group,
          name: staffingContainerTitle(role, group),
        }))
    }
    if (role.surplus && (role.people ?? []).length === 0) return []
    return [{
      key: staffingTileKey(role.id, null),
      role,
      group: null,
      name: staffingContainerTitle(role, null),
    }]
  })
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
    for (const person of tilePeople(tile)) {
      if (!byId.has(person.id)) byId.set(person.id, person)
    }
  }
  return [...byId.values()].sort(sortPeople)
})

const assignedIds = computed(() => {
  const ids = new Set<number>()
  for (const role of roles.value) {
    for (const person of role.people ?? []) ids.add(person.id)
    for (const group of role.groups ?? []) {
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

const hasPersonPool = computed(() => pool.value.length > 0)

const volunteersPeopleRoute = {name: 'volunteers-people'} as const

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
    return 'bi-clipboard-check-fill vol-person-chip__icon--roster'
  }
  return 'bi-person-fill'
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
    roles.value = (staffingRes.data.roles ?? []).map((role: Role) => ({
      ...role,
      people: role.people ?? [],
      groups: role.groups ?? [],
    }))
    openPositions.value = staffingRes.data.open_positions ?? []
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

function assignmentCollectionUrl(tile: Tile) {
  if (tile.group) {
    return `/events/${eventId.value}/staffing/groups/${tile.group.id}/assignments`
  }
  return `/events/${eventId.value}/staffing/roles/${tile.role.id}/assignments`
}

function assignmentItemUrl(tileKey: string, personId: number) {
  if (tileKey.startsWith('g-')) {
    return `/events/${eventId.value}/staffing/groups/${tileKey.slice(2)}/assignments/${personId}`
  }
  return `/events/${eventId.value}/staffing/roles/${tileKey.slice(2)}/assignments/${personId}`
}

function onDragStart(event: any, tileKey: string | null) {
  isDragging.value = true
  dragSourceKey.value = tileKey
  draggedPerson.value = event.item?.__draggable_context?.element ?? null
}

function onDragEnd() {
  isDragging.value = false
  dragOverKey.value = null
  dragSourceKey.value = null
  draggedPerson.value = null
}

function onDropzoneLeave(event: DragEvent, tileKey: string) {
  const next = event.relatedTarget as Node | null
  if (next && (event.currentTarget as Node)?.contains(next)) return
  if (dragOverKey.value === tileKey) dragOverKey.value = null
}

async function handleDrop(event: any, tile: Tile) {
  const person = draggedPerson.value || event.item?.__draggable_context?.element
  dragOverKey.value = null
  isDragging.value = false
  if (!person?.id || !eventId.value) return
  if (dragSourceKey.value === tile.key) return
  const surplus = tileSurplus(tile)
  const filled = tileFilled(tile)
  const people = tilePeople(tile)
  if (surplus) {
    showGlassToast('Diese Rolle wird nicht mehr benötigt — Personen nur umsetzen.', 'info')
    await load()
    return
  }
  if (filled >= Number(tile.role.max) && !people.some((p) => p.id === person.id)) {
    showGlassToast('Maximum für diese Rolle erreicht.', 'info')
    await load()
    return
  }

  try {
    if (dragSourceKey.value) {
      await axios.delete(assignmentItemUrl(dragSourceKey.value, person.id))
    }
    await ensureOnRoster(person)
    await axios.post(assignmentCollectionUrl(tile), {
      volunteer_person: person.id,
    })
  } catch (e: any) {
    showGlassToast(apiError(e, 'Zuweisen fehlgeschlagen'), 'error')
  } finally {
    dragSourceKey.value = null
    draggedPerson.value = null
    await load()
  }
}

async function unassign(tile: Tile, person: Person) {
  if (!eventId.value) return
  try {
    await axios.delete(assignmentItemUrl(tile.key, person.id))
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

watch(eventId, () => load(), {immediate: true})
watch(() => eventStore.selectedEvent?.id, () => syncTileFilters(), {immediate: true})
</script>

<template>
  <div class="vol-page vol-page--fill">
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
        class="vol-staffing-body vol-staffing-body--loading"
    >
      <div class="vol-staffing-loading">
        <LoaderFlow/>
        <LoaderText/>
      </div>
    </div>

    <div v-else class="vol-staffing-body">
      <div class="vol-staffing-pane vol-staffing-pane--main">
        <p v-if="!tiles.length" class="vol-sidebar-muted vol-staffing-empty">
          Noch keine Rollen verfügbar. Rollen werden nach jedem Generieren des Veranstaltungsplans aktualisiert.
        </p>

        <VolunteerStaffingFilterBar
            v-if="tiles.length"
            card
            :active-filters="activeTileFilters"
            :programs="programFilters"
            :scopes="staffingSummary"
            :has-attention="filterHasAttention"
            @toggle="onToggleTileFilter"
        />

        <p v-if="tiles.length && !filteredTiles.length" class="vol-sidebar-muted vol-staffing-empty">
          Keine Rollen für die gewählten Filter.
        </p>

        <div v-if="filteredTiles.length" class="vol-staffing-tiles">
          <VolunteerStaffingTile
              v-for="tile in filteredTiles"
              :key="tile.key"
              :tile="tile"
              :is-dragging="isDragging"
              :drag-over-key="dragOverKey"
              @persist-role="persistLocalRole"
              @delete-role="askDeleteRole"
              @open-bounds="openBoundsModal"
              @drop="handleDrop"
              @drag-start="onDragStart"
              @drag-end="onDragEnd"
              @dropzone-leave="onDropzoneLeave"
              @hover="dragOverKey = $event"
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

      <div class="vol-staffing-pane vol-staffing-pane--side">
        <div class="glass-card liquid-surface-inner vol-sidebar-tile">
          <div class="vol-person-search-field">
            <input
                v-model="personSearch"
                type="search"
                class="glass-input glass-input--sm vol-search-tile__input"
                :placeholder="hasPersonPool ? 'Personen suchen…' : ''"
                :disabled="!hasPersonPool"
                autocomplete="off"
            />
            <RouterLink
                v-if="!hasPersonPool"
                :to="volunteersPeopleRoute"
                class="vol-person-search-empty-link"
            >
              Bitte Personen anlegen.
            </RouterLink>
          </div>
          <div v-if="personSearch.trim()" class="vol-search-results">
            <p v-if="!personSearchMatches.length" class="vol-sidebar-muted">
              Keine Treffer in der Personenliste.
            </p>
            <draggable
                v-else
                :list="searchDisplayPool"
                class="vol-search-chips"
                :group="searchDragGroup"
                :sort="false"
                filter=".vol-person-chip--static"
                item-key="id"
                @start="onDragStart($event, null)"
                @end="onDragEnd"
            >
              <template #item="{element: person}">
                <span
                    class="glass-row-item vol-person-chip"
                    :class="canDragFromSearch(person)
                      ? 'glass-row-item--interactive cursor-move'
                      : 'vol-person-chip--static'"
                >
                  <i
                      class="bi vol-person-chip__icon"
                      :class="searchChipIconClass(person)"
                      aria-hidden="true"
                  />
                  <span class="vol-person-chip__label">{{ volunteerDisplayName(person) }}</span>
                </span>
              </template>
            </draggable>
          </div>
        </div>

        <div class="glass-card liquid-surface-inner vol-sidebar-tile">
          <h2 class="vol-sidebar-heading">Helfer:innen ohne Zuordnung</h2>

          <p v-if="!roster.length" class="vol-sidebar-muted">
            Noch niemand auf der Helfer:innenliste — unter Helfer:innenliste Personen hinzufügen.
          </p>
          <p v-else-if="!unassignedPeople.length" class="vol-sidebar-muted">
            Alle auf der Helfer:innenliste sind zugeordnet.
          </p>

          <draggable
              v-else
              :list="rosterPool"
              class="vol-search-chips"
              :group="peopleGroup"
              item-key="id"
              @start="onDragStart($event, null)"
              @end="onDragEnd"
          >
            <template #item="{element: person}">
              <span class="glass-row-item glass-row-item--interactive vol-person-chip cursor-move">
                <i class="bi bi-person-fill vol-person-chip__icon" aria-hidden="true"/>
                <span class="vol-person-chip__label">{{ volunteerDisplayName(person) }}</span>
              </span>
            </template>
          </draggable>
        </div>

        <VolunteerOpenPositions :open-positions="openPositions"/>
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

  </div>
</template>

<style scoped>
.vol-staffing-body--loading {
  display: flex;
  align-items: center;
  justify-content: center;
}

.vol-staffing-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  color: var(--color-text-muted);
}

.vol-staffing-empty {
  margin-bottom: 0.75rem;
}

.vol-staffing-tiles {
  display: grid;
  grid-template-columns: repeat(1, minmax(0, 1fr));
  gap: 0.75rem 1rem;
}

@media (min-width: 640px) {
  .vol-staffing-tiles {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (min-width: 1280px) {
  .vol-staffing-tiles {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

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

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
