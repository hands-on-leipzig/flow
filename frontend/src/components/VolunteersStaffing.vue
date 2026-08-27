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
import {programLogoAlt, programLogoSrc} from '@/utils/images'
import {programNameForId} from '@/utils/eventPrograms'

defineOptions({name: 'VolunteersStaffing'})

type Person = {
  id: number
  first_name: string
  last_name: string
  nickname: string | null
  email: string
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
const planId = ref<number | null>(null)
const staffingOk = ref(true)
const loading = ref(false)
const syncing = ref(false)
const isSaving = ref(false)

const isDragging = ref(false)
const dragOverGroupId = ref<number | null>(null)
const dragSourceGroupId = ref<number | null>(null)
const draggedPerson = ref<Person | null>(null)

const roleToDelete = ref<Role | null>(null)
const pickPerson = ref<Person | null>(null)
const composerRef = ref<{focusTitle?: () => void} | null>(null)

const newRoleName = ref('')
const newRoleMin = ref<number | ''>('')
const newRoleBest = ref<number | ''>('')
const newRoleMax = ref<number | ''>('')

const tiles = computed<Tile[]>(() =>
  roles.value.flatMap((role) =>
    role.groups
      .filter((group) => !(group.surplus && group.people.length === 0))
      .map((group) => ({
        key: `${role.id}-${group.id}`,
        role,
        group,
        name: groupTitle(role, group),
      })),
  ),
)

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

watch(unassignedPeople, (people) => {
  rosterPool.value = [...people]
}, {immediate: true})

const assignedPeople = computed(() =>
  roster.value
    .map((entry) => entry.person)
    .filter((person) => assignedIds.value.has(person.id))
    .sort(sortPeople),
)

const gapSummary = computed(() => {
  let underMin = 0
  let surplusPeople = 0
  for (const tile of tiles.value) {
    if (tile.group.surplus && tile.group.filled > 0) surplusPeople += tile.group.filled
    if (tile.group.under_min) underMin++
  }
  return {underMin, surplusPeople}
})

const deleteRoleMessage = computed(() => {
  if (!roleToDelete.value) return ''
  const name = (roleToDelete.value.label || '').trim() || 'Unbenannt'
  return `„${name}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

const peopleGroup = {name: 'staffing-people', pull: true, put: false}

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

function groupTitle(role: Role, group: Group) {
  if (role.groups.length <= 1 && !group.surplus) return role.label
  return `${role.label} ${group.group_index}`
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

function assignmentLabel(person: Person) {
  for (const tile of tiles.value) {
    if (tile.group.people.some((p) => p.id === person.id)) return tile.name
  }
  return ''
}

function isUnderMin(tile: Tile) {
  return !tile.group.surplus && tile.group.filled < Number(tile.role.min)
}

function slotPositions(role: Role) {
  const max = Number(role.max)
  if (!Number.isInteger(max) || max < 1) return []
  return Array.from({length: max}, (_, i) => i + 1)
}

function slotBarClass(pos: number, role: Role) {
  if (pos < role.min) return 'staffing-slot__bar--low'
  if (pos === role.best) return 'staffing-slot__bar--best'
  return 'staffing-slot__bar--mid'
}

function apiError(e: any, fallback: string) {
  return e?.response?.data?.error || fallback
}

async function load() {
  if (!eventId.value) return
  loading.value = true
  try {
    const [staffingRes, rosterRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/staffing`),
      axios.get(`/events/${eventId.value}/volunteer-roster`),
    ])
    roles.value = staffingRes.data.roles ?? []
    planId.value = staffingRes.data.plan_id ?? null
    staffingOk.value = staffingRes.data.staffing_ok !== false
    roster.value = rosterRes.data.roster ?? []
    await eventStore.refreshReadiness(eventId.value)
  } catch (e: any) {
    showGlassToast(apiError(e, 'Laden fehlgeschlagen'), 'error')
  } finally {
    loading.value = false
  }
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
  if (!Number.isInteger(min) || !Number.isInteger(best) || !Number.isInteger(max)) {
    showGlassToast('Bitte min, best und max eintragen.', 'info')
    return
  }
  if (min > best || best > max) {
    showGlassToast('Es muss min ≤ best ≤ max gelten.', 'info')
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
    showGlassToast('Es muss min ≤ best ≤ max gelten.', 'info')
    await load()
    return
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
</script>

<template>
  <div class="vol-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Besetzung</h1>
        <p class="vol-page__sub">
          <span v-if="staffingOk" class="vol-ok">Besetzung ok</span>
          <span v-else class="vol-warn">
            Handlungsbedarf:
            <template v-if="gapSummary.underMin">{{ gapSummary.underMin }} unter Min</template>
            <template v-if="gapSummary.underMin && gapSummary.surplusPeople"> · </template>
            <template v-if="gapSummary.surplusPeople">{{ gapSummary.surplusPeople }} umsetzen</template>
          </span>
        </p>
      </div>
      <button
          type="button"
          class="glass-btn-accent"
          :disabled="syncing || !planId"
          @click="syncFromPlan"
      >
        {{ syncing ? 'Abgleichen…' : 'Mit Plan abgleichen' }}
      </button>
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
        <h2 class="glass-card__heading !text-lg md:!text-xl !mb-3 md:!mb-4">Rollen</h2>

        <p v-if="!tiles.length" class="text-sm text-[var(--color-text-subtle)] mb-3">
          Noch keine Rollen. Mit Plan abgleichen — oder links eine eigene Rolle anlegen.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 md:gap-4">
          <ItemCard
              v-for="tile in tiles"
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

            <div class="staffing-meta">
              <div
                  class="staffing-slots"
                  :title="`min ${tile.role.min} · best ${tile.role.best} · max ${tile.role.max}`"
              >
                <div
                    v-for="pos in slotPositions(tile.role)"
                    :key="`${tile.key}-slot-${pos}`"
                    class="staffing-slot"
                >
                  <i
                      class="staffing-slot__icon"
                      :class="pos <= tile.group.filled ? 'bi bi-person-fill staffing-slot__icon--filled' : 'bi bi-person'"
                  />
                  <span class="staffing-slot__bar" :class="slotBarClass(pos, tile.role)"/>
                </div>
              </div>

              <div v-if="tile.role.is_local" class="staffing-bounds">
                <label class="staffing-bounds__field">
                  <span>min</span>
                  <input
                      v-model.number="tile.role.min"
                      class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                      type="number"
                      min="1"
                      @blur="persistLocalRole(tile.role)"
                  />
                </label>
                <label class="staffing-bounds__field">
                  <span>best</span>
                  <input
                      v-model.number="tile.role.best"
                      class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                      type="number"
                      min="1"
                      @blur="persistLocalRole(tile.role)"
                  />
                </label>
                <label class="staffing-bounds__field">
                  <span>max</span>
                  <input
                      v-model.number="tile.role.max"
                      class="glass-input glass-input--sm liquid-surface-control staffing-bounds__input"
                      type="number"
                      min="1"
                      @blur="persistLocalRole(tile.role)"
                  />
                </label>
              </div>
              <InfoPopover v-else :text="tile.role.ui_description"/>
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
                    <span>best</span>
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
                <p class="item-card__hint">min ≤ best ≤ max — wie viele Personen diese Rolle braucht.</p>
              </div>
            </transition>
          </ItemComposer>
        </div>
      </div>

      <div class="lg:col-span-1 order-1 lg:order-2">
        <div class="glass-card liquid-surface-inner !p-3 md:!p-4 lg:sticky lg:top-4">
          <h2 class="glass-card__heading !mb-1 !text-sm md:!text-base">Personen</h2>
          <p class="text-xs text-[var(--color-text-subtle)] mb-3">
            {{ unassignedPeople.length }} frei
            · {{ assignedPeople.length }} zugewiesen
            · {{ roster.length }} auf der Anmeldung
          </p>

          <p v-if="!roster.length" class="text-sm text-[var(--color-text-subtle)]">
            Noch niemand auf der Anmeldung — unter Anmeldung Personen hinzufügen.
          </p>

          <div v-else class="space-y-3">
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

            <div v-if="assignedPeople.length" class="staffing-assigned">
              <h3 class="staffing-assigned__label">Zugewiesen</h3>
              <div class="flex flex-wrap gap-1.5">
                <span
                    v-for="person in assignedPeople"
                    :key="`assigned-${person.id}`"
                    class="glass-row-item text-[11px] md:text-xs staffing-assigned__chip"
                    :title="assignmentLabel(person)"
                >
                  <i class="bi bi-person-check text-[var(--color-text-subtle)]"/>
                  <span class="px-1.5 py-1 truncate max-w-[11rem]">
                    {{ displayName(person) }}
                    <span v-if="assignmentLabel(person)" class="staffing-assigned__role">
                      · {{ assignmentLabel(person) }}
                    </span>
                  </span>
                </span>
              </div>
            </div>
          </div>
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

.vol-page__title {
  font-size: 1.5rem;
  font-weight: 650;
  margin: 0;
}

.vol-page__sub {
  margin: 0.25rem 0 0;
  font-size: 0.9rem;
}

.vol-ok {
  color: #15803d;
  font-weight: 600;
}

.vol-warn {
  color: #b91c1c;
  font-weight: 600;
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
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.staffing-slots {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.3rem 0.4rem;
  flex: 1 1 7rem;
  min-width: 0;
}

.staffing-slot {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.15rem;
  width: 1.05rem;
}

.staffing-slot__icon {
  font-size: 1rem;
  line-height: 1;
  color: var(--color-text-subtle);
}

.staffing-slot__icon--filled {
  color: var(--color-text);
}

.staffing-slot__bar {
  display: block;
  width: 100%;
  height: 3px;
  border-radius: 999px;
}

.staffing-slot__bar--low {
  background: #dc2626;
}

.staffing-slot__bar--mid {
  background: #eab308;
}

.staffing-slot__bar--best {
  background: #16a34a;
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

.staffing-assigned {
  padding-top: 0.75rem;
  border-top: 1px solid color-mix(in srgb, var(--color-border) 70%, transparent);
}

.staffing-assigned__label {
  margin: 0 0 0.45rem;
  font-size: 0.7rem;
  font-weight: 650;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-subtle);
}

.staffing-assigned__chip {
  opacity: 0.72;
}

.staffing-assigned__role {
  color: var(--color-text-subtle);
  font-weight: 500;
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
