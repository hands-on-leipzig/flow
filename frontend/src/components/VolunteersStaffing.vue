<script setup lang="ts">
import {computed, nextTick, ref, watch} from 'vue'
import axios from 'axios'
import draggable from 'vuedraggable'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import InfoPopover from '@/components/atoms/InfoPopover.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import ItemCard from '@/components/molecules/ItemCard.vue'
import ItemComposer from '@/components/molecules/ItemComposer.vue'
import VolunteerEmailOutreach from '@/components/molecules/VolunteerEmailOutreach.vue'
import {programLogoAlt, programLogoSrc} from '@/utils/images'
import {eventPrograms, programDisplayName, programId, programNameForId} from '@/utils/eventPrograms'

defineOptions({name: 'VolunteersStaffing'})

type Person = {
  id: number
  first_name: string
  last_name: string
  nickname: string | null
  email: string
  mobile?: string | null
}

type Group = {
  id: number
  group_index: number
  surplus: boolean
  filled: number
  min: number
  best: number
  max: number
  under_min: boolean
  people: Person[]
}

type Role = {
  id: number
  m_role: number | null
  is_local: boolean
  label: string
  first_program: number | null
  min: number
  best: number
  max: number
  ui_description: string | null
  sequence: number
  groups: Group[]
}

type RosterEntry = {
  person: Person
  has_assignment: boolean
}

type Tile = {
  key: string
  role: Role
  group: Group
  name: string
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)

const roles = ref<Role[]>([])
const roster = ref<RosterEntry[]>([])
const pool = ref<Person[]>([])
const personSearch = ref('')
const planId = ref<number | null>(null)
const loading = ref(false)
const syncing = ref(false)
const isSaving = ref(false)

const isDragging = ref(false)
const dragOverGroupId = ref<number | null>(null)
const dragSourceGroupId = ref<number | null>(null)
const draggedPerson = ref<Person | null>(null)

const roleToDelete = ref<Role | null>(null)
const boundsEditRole = ref<Role | null>(null)
const boundsDraft = ref({min: 1, best: 1, max: 1})
const pickPerson = ref<Person | null>(null)
const composerRef = ref<{focusTitle?: () => void} | null>(null)

const newRoleName = ref('')
const newRoleMin = ref<number | ''>('')
const newRoleBest = ref<number | ''>('')
const newRoleMax = ref<number | ''>('')

type TileFilterKey = 'cross' | 'local' | `program:${number}`

const activeTileFilters = ref<Set<TileFilterKey>>(new Set())

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
  return [...list].sort(compareTiles)
})

const filteredTiles = computed(() => {
  if (activeTileFilters.value.size === 0) return []
  return tiles.value.filter((tile) => activeTileFilters.value.has(tileFilterKey(tile)))
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
    .filter((p) => searchHaystack(p).includes(q))
    .sort((a, b) => {
      const av = displayName(a).toLocaleLowerCase('de')
      const bv = displayName(b).toLocaleLowerCase('de')
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

function dropGroup(group: Group) {
  return {
    name: 'staffing-people',
    pull: true,
    put: !group.surplus && group.filled < group.max,
  }
}

function sortPeople(a: Person, b: Person) {
  const last = a.last_name.localeCompare(b.last_name, 'de')
  if (last !== 0) return last
  return a.first_name.localeCompare(b.first_name, 'de')
}

function displayName(person: Person) {
  if (person.nickname?.trim()) return `${person.first_name} „${person.nickname}“ ${person.last_name}`
  return `${person.first_name} ${person.last_name}`
}

function searchHaystack(person: Person) {
  return [
    person.first_name,
    person.last_name,
    person.nickname,
    person.email,
    person.mobile,
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
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

function firstProgramSequence(program: number | null) {
  if (program == null) return -1
  const row = eventStore.selectedEvent?.programs?.find((p) => programId(p) === program)
  return row?.sequence ?? Number.MAX_SAFE_INTEGER
}

function compareTiles(a: Tile, b: Tile) {
  if (a.role.is_local !== b.role.is_local) {
    return a.role.is_local ? 1 : -1
  }

  if (a.role.is_local) {
    const byName = a.name.localeCompare(b.name, 'de')
    if (byName !== 0) return byName
    return a.group.group_index - b.group.group_index
  }

  const aNoProgram = a.role.first_program == null
  const bNoProgram = b.role.first_program == null
  if (aNoProgram !== bNoProgram) return aNoProgram ? -1 : 1

  const byProgram = firstProgramSequence(a.role.first_program) - firstProgramSequence(b.role.first_program)
  if (byProgram !== 0) return byProgram

  if (a.role.sequence !== b.role.sequence) return a.role.sequence - b.role.sequence

  const byLabel = a.role.label.localeCompare(b.role.label, 'de')
  if (byLabel !== 0) return byLabel

  if (a.role.id !== b.role.id) return a.role.id - b.role.id

  return a.group.group_index - b.group.group_index
}

function buildTileFilterKeys(): TileFilterKey[] {
  const keys: TileFilterKey[] = ['cross', 'local']
  for (const program of programFilters.value) {
    const id = programId(program)
    if (id > 0) keys.push(`program:${id}`)
  }
  return keys
}

function syncTileFilters() {
  const keys = buildTileFilterKeys()
  const kept = keys.filter((key) => activeTileFilters.value.has(key))
  activeTileFilters.value = kept.length > 0 ? new Set(kept) : new Set(keys)
}

function tileFilterKey(tile: Tile): TileFilterKey {
  if (tile.role.is_local) return 'local'
  if (tile.role.first_program == null) return 'cross'
  return `program:${tile.role.first_program}`
}

function isTileFilterActive(key: TileFilterKey) {
  return activeTileFilters.value.has(key)
}

function toggleTileFilter(key: TileFilterKey) {
  const next = new Set(activeTileFilters.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  activeTileFilters.value = next
}

function programFilterLogo(program: {first_program?: number; id?: number; name?: string | null}) {
  return programLogoSrc({
    first_program: programId(program),
    name: program.name ?? programNameForId(eventStore.selectedEvent, programId(program)),
  })
}

function roleLogoSrc(role: Role) {
  if (!role.first_program) return ''
  return programLogoSrc({
    first_program: role.first_program,
    name: programNameForId(eventStore.selectedEvent, role.first_program),
  })
}

function roleLogoAlt(role: Role) {
  if (!role.first_program) return ''
  return programLogoAlt({
    first_program: role.first_program,
    name: programNameForId(eventStore.selectedEvent, role.first_program),
  })
}

function isUnderMin(tile: Tile) {
  return !tile.group.surplus && tile.group.filled < Number(tile.role.min)
}

type StaffingGapTone = 'warn' | 'caution' | 'ok' | 'muted'

function staffingGap(tile: Tile): {label: string; tone: StaffingGapTone} {
  const filled = tile.group.filled
  const min = Number(tile.role.min)
  const best = Number(tile.role.best)

  if (filled < min) {
    const missing = min - filled
    return {
      label: missing === 1 ? '1 fehlt' : `${missing} fehlen`,
      tone: 'warn',
    }
  }
  if (filled < best) {
    return {label: `${best - filled} bis ideal`, tone: 'caution'}
  }
  if (filled === best) {
    return {label: 'Ideal', tone: 'ok'}
  }
  return {label: `${filled - best} mehr als ideal`, tone: 'muted'}
}

function gapStatusClass(tile: Tile) {
  return `staffing-status__gap--${staffingGap(tile).tone}`
}

function boundsLabel(role: Role) {
  return `min ${role.min} · ideal ${role.best} · max ${role.max}`
}

function boundsValidationError(min: number, best: number, max: number) {
  if (!Number.isInteger(min) || !Number.isInteger(best) || !Number.isInteger(max)) {
    return 'Bitte min, ideal und max eintragen.'
  }
  if (min > best || best > max) {
    return 'Es muss min ≤ ideal ≤ max gelten.'
  }
  return null
}

function openBoundsModal(role: Role) {
  boundsEditRole.value = role
  boundsDraft.value = {
    min: Number(role.min),
    best: Number(role.best),
    max: Number(role.max),
  }
}

function closeBoundsModal() {
  boundsEditRole.value = null
}

async function saveBoundsModal() {
  const role = boundsEditRole.value
  if (!role || isSaving.value) return
  const min = Number(boundsDraft.value.min)
  const best = Number(boundsDraft.value.best)
  const max = Number(boundsDraft.value.max)
  const validationError = boundsValidationError(min, best, max)
  if (validationError) {
    showGlassToast(validationError, 'info')
    return
  }
  isSaving.value = true
  try {
    role.min = min
    role.best = best
    role.max = max
    await persistLocalRole(role)
    closeBoundsModal()
  } finally {
    isSaving.value = false
  }
}

function slotPositions(role: Role) {
  const max = Number(role.max)
  if (!Number.isInteger(max) || max < 1) return []
  return Array.from({length: max}, (_, i) => i + 1)
}

function apiError(e: any, fallback: string) {
  return e?.response?.data?.error || fallback
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

async function syncFromPlan() {
  if (!eventId.value) return
  syncing.value = true
  try {
    const {data} = await axios.post(`/events/${eventId.value}/staffing/sync`)
    const s = data.stats || {}
    showGlassToast(
      `Abgleich: ${s.roles ?? 0} Rollen, +${s.groups_created ?? 0} Gruppen`
        + (s.skipped?.length ? ` (${s.skipped.length} übersprungen)` : ''),
      'success',
    )
    await load()
  } catch (e: any) {
    showGlassToast(apiError(e, 'Abgleich fehlgeschlagen'), 'error')
  } finally {
    syncing.value = false
  }
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
  const min = Number(newRoleMin.value)
  const best = Number(newRoleBest.value)
  const max = Number(newRoleMax.value)
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
        <VolunteerEmailOutreach scope="roster"/>
        <button
            type="button"
            class="glass-btn-accent"
            :disabled="syncing || !planId"
            @click="syncFromPlan"
        >
          {{ syncing ? 'Abgleichen…' : 'Mit Plan abgleichen' }}
        </button>
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
          Noch keine Rollen. Mit Plan abgleichen — oder links eine eigene Rolle anlegen.
        </p>

        <div v-if="tiles.length" class="staffing-filters glass-card liquid-surface-inner">
          <button
              type="button"
              class="staffing-filter"
              :class="{'staffing-filter--active': isTileFilterActive('cross')}"
              @click="toggleTileFilter('cross')"
          >
            Übergreifend
          </button>
          <button
              v-for="program in programFilters"
              :key="`filter-program-${programId(program)}`"
              type="button"
              class="staffing-filter"
              :class="{'staffing-filter--active': isTileFilterActive(`program:${programId(program)}`)}"
              @click="toggleTileFilter(`program:${programId(program)}`)"
          >
            <img
                v-if="programFilterLogo(program)"
                :src="programFilterLogo(program)"
                :alt="programDisplayName(program)"
                class="staffing-filter__logo"
            />
            {{ programDisplayName(program) }}
          </button>
          <button
              type="button"
              class="staffing-filter"
              :class="{'staffing-filter--active': isTileFilterActive('local')}"
              @click="toggleTileFilter('local')"
          >
            Zusätzlich
          </button>
        </div>

        <p v-if="tiles.length && !filteredTiles.length" class="text-sm text-[var(--color-text-subtle)] mb-3">
          Keine Rollen für die gewählten Filter.
        </p>

        <div v-if="filteredTiles.length" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 md:gap-4">
          <ItemCard
              v-for="tile in filteredTiles"
              :key="tile.key"
              :inactive="tile.group.surplus"
              :class="{'staffing-tile--surplus': tile.group.surplus}"
          >
            <template v-if="roleLogoSrc(tile.role)" #leading>
              <img
                  :src="roleLogoSrc(tile.role)"
                  :alt="roleLogoAlt(tile.role)"
                  class="w-6 h-6 flex-shrink-0"
              />
            </template>
            <template #title>
              <div class="staffing-title">
                <input
                    v-if="tile.role.is_local"
                    v-model="tile.role.label"
                    class="item-card__title glass-input glass-input--sm liquid-surface-control"
                    @blur="persistLocalRole(tile.role)"
                />
                <span v-else class="item-card__title font-semibold truncate flex items-center min-h-[var(--field-min-height-sm)]">
                  {{ tile.name }}
                </span>
                <span
                    v-if="isUnderMin(tile)"
                    class="staffing-need-dot"
                    title="Unter Min"
                />
              </div>
            </template>
            <template v-if="tile.role.is_local || tile.group.surplus" #trailing>
              <IconDangerButton
                  v-if="tile.role.is_local"
                  label="Rolle löschen"
                  @click.stop="askDeleteRole(tile.role)"
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
                      @click.stop="openBoundsModal(tile.role)"
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
                @dragenter.prevent="dragOverGroupId = tile.group.id"
                @dragover.prevent="dragOverGroupId = tile.group.id"
                @dragleave="onDropzoneLeave($event, tile.group.id)"
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
                  @add="handleDrop($event, tile.group)"
                  @start="onDragStart($event, tile.group.id)"
                  @end="onDragEnd"
              >
                <template #item="{element: person}">
                  <span class="glass-row-item glass-row-item--interactive text-[11px] md:text-xs cursor-move">
                    <i class="bi bi-person-fill text-[var(--color-text-subtle)]"/>
                    <span class="px-1.5 py-1 truncate max-w-[10rem]">{{ displayName(person) }}</span>
                    <button
                        type="button"
                        class="ml-0.5 text-sm text-[var(--color-text-subtle)] hover:text-[var(--color-text)] pr-1"
                        @click.stop="unassign(tile.group, person)"
                    >
                      ✖
                    </button>
                  </span>
                </template>
              </draggable>
            </div>
          </ItemCard>

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
                      <span class="staffing-search-chip__label">{{ displayName(person) }}</span>
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
                  <span class="staffing-search-chip__label">{{ displayName(person) }}</span>
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
                  <span class="px-1.5 py-1">{{ displayName(person) }}</span>
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
                <span class="px-1.5 py-1">{{ displayName(person) }}</span>
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

    <div
        v-if="boundsEditRole"
        class="glass-scrim fixed inset-0 z-50 flex items-center justify-center p-4"
        @click="closeBoundsModal"
    >
      <div class="glass-modal staffing-bounds-modal" @click.stop>
        <h3 class="staffing-bounds-modal__title">Besetzung bearbeiten</h3>
        <p class="staffing-bounds-modal__role">{{ boundsEditRole.label }}</p>
        <div class="staffing-bounds staffing-bounds--modal">
          <label class="staffing-bounds__field">
            <span>min</span>
            <input
                v-model.number="boundsDraft.min"
                class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                type="number"
                min="1"
            />
          </label>
          <label class="staffing-bounds__field">
            <span>ideal</span>
            <input
                v-model.number="boundsDraft.best"
                class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                type="number"
                min="1"
            />
          </label>
          <label class="staffing-bounds__field">
            <span>max</span>
            <input
                v-model.number="boundsDraft.max"
                class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                type="number"
                min="1"
            />
          </label>
        </div>
        <p class="item-card__hint">min ≤ ideal ≤ max — wie viele Personen diese Rolle braucht.</p>
        <div class="staffing-bounds-modal__actions">
          <button type="button" class="glass-btn-secondary" :disabled="isSaving" @click="closeBoundsModal">
            Abbrechen
          </button>
          <button type="button" class="glass-btn-accent" :disabled="isSaving" @click="saveBoundsModal">
            Speichern
          </button>
        </div>
      </div>
    </div>

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
          {{ displayName(pickPerson) }}
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
.vol-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 0.5rem 0 2rem;
}

.vol-page__header {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  flex-wrap: wrap;
}

.vol-page__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
}

.vol-page__title {
  font-size: 1.5rem;
  font-weight: 650;
  margin: 0;
}

.vol-page__sub {
  margin: 0.25rem 0 0;
  opacity: 0.75;
}

.staffing-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.65rem;
  margin-bottom: 0.75rem;
}

.staffing-filter {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.65rem;
  border: 1px solid var(--liquid-border-soft);
  border-radius: var(--radius);
  background: var(--liquid-tile-bg-inner);
  box-shadow: var(--liquid-shadow-inset);
  color: var(--color-text-muted);
  font-size: 0.8125rem;
  font-weight: 500;
  line-height: 1.2;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.staffing-filter:hover {
  background: var(--color-bg-hover);
  color: var(--color-text);
}

.staffing-filter--active {
  border-color: color-mix(in srgb, var(--color-accent) 45%, var(--color-border));
  background: color-mix(in srgb, var(--color-accent-muted) 55%, var(--liquid-tile-bg-inner));
  color: var(--color-text);
}

.staffing-filter__logo {
  width: 1rem;
  height: 1rem;
  flex-shrink: 0;
}

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
  color: #b91c1c;
}

.staffing-status__gap--caution {
  color: #a16207;
}

.staffing-status__gap--ok {
  color: #15803d;
  font-weight: 500;
}

.staffing-status__gap--muted {
  color: var(--color-text-subtle);
  font-weight: 500;
}

.staffing-status__secondary {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.staffing-status__bounds {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-left: auto;
}

.staffing-bounds-text {
  font-size: 0.7rem;
  color: var(--color-text-subtle);
  letter-spacing: 0.02em;
  white-space: nowrap;
}

.staffing-bounds-gear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  padding: 0;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-subtle);
  cursor: pointer;
  font-size: 0.85rem;
  line-height: 1;
}

.staffing-bounds-gear:hover {
  color: var(--color-accent);
  background: var(--color-bg-hover);
}

.staffing-bounds-modal {
  width: min(100%, 20rem);
  padding: 1rem 1.1rem;
}

.staffing-bounds-modal__title {
  margin: 0 0 0.25rem;
  font-size: 1rem;
  font-weight: 650;
  color: var(--color-text);
}

.staffing-bounds-modal__role {
  margin: 0 0 0.85rem;
  font-size: 0.8125rem;
  color: var(--color-text-subtle);
}

.staffing-bounds--modal {
  margin-bottom: 0.35rem;
}

.staffing-bounds-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.85rem;
}

.staffing-slots {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem;
  flex: 1 1 auto;
  min-width: 0;
}

.staffing-slot__icon {
  font-size: 0.85rem;
  line-height: 1;
  color: color-mix(in srgb, var(--color-text-subtle) 30%, transparent);
}

.staffing-slot__icon--filled {
  color: var(--color-text);
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
