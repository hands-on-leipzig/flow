<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import draggable from 'vuedraggable'
import axios from 'axios'
import {programLogoAlt, programLogoSrc} from '@/utils/images'
import {showGlassToast} from '@/composables/useGlassToast'
import {useProgramsStore} from '@/stores/programs'

defineOptions({name: 'Visibility'})

type FilterProgram = {
  id: number | null
  name: string | null
  display_name: string | null
  letter: string | null
  logo_stem: string | null
  sequence: number | null
}

type CatalogRole = {
  id: number
  name: string
  name_short: string | null
  sequence: number
  first_program: number | null
  program: string | null
  logo_stem: string | null
}

type CatalogActivity = {
  id: number
  name: string
  activity_type: number | null
  sequence: number
  first_program: number | null
  program: string | null
  logo_stem: string | null
}

type CatalogActivityType = {
  id: number
  name: string
  first_program: number | null
  sequence: number
}

const OVERALL = 'overall'

const programsStore = useProgramsStore()

const view = ref<'matrix' | 'sort'>('matrix')
const loading = ref(false)
const error = ref<string | null>(null)
const toggling = ref(false)
const savingSort = ref(false)

const roles = ref<CatalogRole[]>([])
const activities = ref<CatalogActivity[]>([])
const visibleKeys = ref<Set<string>>(new Set())
const rolePrograms = ref<FilterProgram[]>([])
const activityTypePrograms = ref<FilterProgram[]>([])
const activityTypes = ref<CatalogActivityType[]>([])

const selectedRoleProgramKeys = ref<string[]>([])
const activityProgramKey = ref('')
const selectedActivityTypeIds = ref<string[]>([])

const sortProgramKey = ref('')
const sortRoles = ref<CatalogRole[]>([])

function programKey(id: number | null | undefined): string {
  return id == null ? OVERALL : String(id)
}

function parseProgramKey(key: string): number | null {
  return key === OVERALL || key === '' ? null : Number(key)
}

function programLabel(program: FilterProgram): string {
  if (program.id == null) return 'Overall'
  return program.display_name || program.name || `Programm ${program.id}`
}

function logoFor(
  firstProgram: number | null | undefined,
  name?: string | null,
  stem?: string | null,
) {
  if (firstProgram == null) return null
  return {
    id: firstProgram,
    name: name ?? undefined,
    logo_stem: stem ?? undefined,
  }
}

function cellKey(roleId: number, activityId: number): string {
  return `${roleId}_${activityId}`
}

function isVisible(roleId: number, activityId: number): boolean {
  return visibleKeys.value.has(cellKey(roleId, activityId))
}

function matchesProgram(firstProgram: number | null | undefined, keys: string[]): boolean {
  if (!keys.length) return false
  return keys.includes(programKey(firstProgram))
}

const filteredRoles = computed(() =>
  roles.value.filter((role) => matchesProgram(role.first_program, selectedRoleProgramKeys.value)),
)

const activityTypesForProgram = computed(() => {
  const id = parseProgramKey(activityProgramKey.value)
  return activityTypes.value.filter((type) => {
    if (id == null) return type.first_program == null
    return Number(type.first_program) === id
  })
})

const filteredActivities = computed(() => {
  const typeIds = selectedActivityTypeIds.value.map(Number).filter((n) => n > 0)
  const allowedTypeIds = new Set(
    typeIds.length
      ? typeIds
      : activityTypesForProgram.value.map((type) => type.id),
  )
  return activities.value.filter((activity) => allowedTypeIds.has(Number(activity.activity_type)))
})

function roleTooltip(role: CatalogRole): string {
  const parts = [role.name]
  if (role.name_short) parts.push(`(${role.name_short})`)
  const program = rolePrograms.value.find((p) => programKey(p.id) === programKey(role.first_program))
  if (program) parts.push(programLabel(program))
  return parts.join(' ')
}

function activityTooltip(activity: CatalogActivity): string {
  const program = activityTypePrograms.value.find(
    (p) => programKey(p.id) === programKey(activity.first_program),
  )
  return program ? `${activity.name} — ${programLabel(program)}` : activity.name
}

function toggleRoleChip(key: string) {
  const current = selectedRoleProgramKeys.value
  selectedRoleProgramKeys.value = current.includes(key)
    ? current.filter((item) => item !== key)
    : [...current, key]
}

function withOverallFirst(programs: FilterProgram[]): FilterProgram[] {
  const overall = programs.filter((program) => program.id == null)
  const rest = programs.filter((program) => program.id != null)
  return [...overall, ...rest]
}

const sortPrograms = computed(() => withOverallFirst(rolePrograms.value))
const activityPrograms = computed(() => withOverallFirst(activityTypePrograms.value))

function rolesForSortProgram(key: string): CatalogRole[] {
  const id = parseProgramKey(key)
  return roles.value
    .filter((role) => (id == null ? role.first_program == null : Number(role.first_program) === id))
    .slice()
    .sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0) || a.id - b.id)
}

function syncSortList() {
  if (!sortProgramKey.value && sortPrograms.value.length) {
    sortProgramKey.value = programKey(sortPrograms.value[0].id)
  }
  sortRoles.value = rolesForSortProgram(sortProgramKey.value).map((role) => ({...role}))
}

watch(sortProgramKey, () => {
  sortRoles.value = rolesForSortProgram(sortProgramKey.value).map((role) => ({...role}))
})

watch(activityProgramKey, () => {
  selectedActivityTypeIds.value = []
})

async function loadMatrix() {
  loading.value = true
  error.value = null
  try {
    await programsStore.ensureLoaded()
    const {data} = await axios.get('/visibility/matrix')
    roles.value = data.roles || []
    activities.value = data.activities || []
    rolePrograms.value = data.role_programs || []
    activityTypePrograms.value = data.activity_type_programs || []
    activityTypes.value = data.activity_types || []
    if (!selectedRoleProgramKeys.value.length) {
      selectedRoleProgramKeys.value = rolePrograms.value.map((program) => programKey(program.id))
    }

    const next = new Set<string>()
    for (const row of data.matrix || []) {
      const roleId = row.role?.id
      for (const cell of row.activities || []) {
        if (cell.visible && roleId != null && cell.activity?.id != null) {
          next.add(cellKey(roleId, cell.activity.id))
        }
      }
    }
    visibleKeys.value = next

    if (!activityProgramKey.value && activityPrograms.value.length) {
      activityProgramKey.value = programKey(activityPrograms.value[0].id)
    }
    syncSortList()
  } catch (err) {
    error.value = 'Sichtbarkeitsmatrix konnte nicht geladen werden.'
    console.error(err)
  } finally {
    loading.value = false
  }
}

async function toggleCell(roleId: number, activityId: number) {
  if (toggling.value) return
  const key = cellKey(roleId, activityId)
  const next = !visibleKeys.value.has(key)
  const updated = new Set(visibleKeys.value)
  if (next) updated.add(key)
  else updated.delete(key)
  visibleKeys.value = updated
  toggling.value = true
  try {
    await axios.post('/visibility/toggle', {
      role_id: roleId,
      activity_type_detail_id: activityId,
      visible: next,
    })
  } catch (err) {
    const reverted = new Set(visibleKeys.value)
    if (next) reverted.delete(key)
    else reverted.add(key)
    visibleKeys.value = reverted
    showGlassToast('Sichtbarkeit konnte nicht gespeichert werden.', 'error')
    console.error(err)
  } finally {
    toggling.value = false
  }
}

async function saveSort() {
  if (savingSort.value || !sortRoles.value.length) return
  savingSort.value = true
  try {
    const order = sortRoles.value.map((role, index) => ({
      id: role.id,
      sequence: index + 1,
    }))
    await axios.post('/visibility/roles/reorder', {
      first_program: parseProgramKey(sortProgramKey.value),
      order,
    })
    const byId = new Map(order.map((row) => [row.id, row.sequence]))
    roles.value = roles.value.map((role) =>
      byId.has(role.id) ? {...role, sequence: byId.get(role.id) as number} : role,
    )
    sortRoles.value = sortRoles.value.map((role, index) => ({...role, sequence: index + 1}))
    showGlassToast('Reihenfolge gespeichert', 'success')
  } catch (err) {
    showGlassToast('Reihenfolge konnte nicht gespeichert werden.', 'error')
    console.error(err)
    syncSortList()
  } finally {
    savingSort.value = false
  }
}

onMounted(loadMatrix)
</script>

<template>
  <div class="visibility-admin">
    <div class="visibility-admin__tabs">
      <button
        type="button"
        class="visibility-admin__tab"
        :class="{ 'visibility-admin__tab--active': view === 'matrix' }"
        @click="view = 'matrix'"
      >
        Matrix
      </button>
      <button
        type="button"
        class="visibility-admin__tab"
        :class="{ 'visibility-admin__tab--active': view === 'sort' }"
        @click="view = 'sort'"
      >
        Rollen sortieren
      </button>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--color-accent)]"/>
      <p class="mt-2 text-sm text-[var(--color-text-muted)]">Lade Sichtbarkeitsmatrix…</p>
    </div>

    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-md p-4">
      <p class="text-sm text-red-600">{{ error }}</p>
      <button type="button" class="mt-2 text-sm text-red-600 underline" @click="loadMatrix">
        Erneut versuchen
      </button>
    </div>

    <div v-else-if="view === 'matrix'" class="visibility-admin__matrix-layout">
      <div class="visibility-admin__filters">
        <div class="visibility-admin__filter">
          <div class="visibility-admin__filter-label">Activities</div>
          <div class="visibility-admin__lists">
            <select
              v-model="activityProgramKey"
              class="visibility-admin__listbox"
              size="8"
              aria-label="Activities Programm"
            >
              <option
                v-for="program in activityPrograms"
                :key="programKey(program.id)"
                :value="programKey(program.id)"
              >
                {{ programLabel(program) }}
              </option>
            </select>
            <select
              v-model="selectedActivityTypeIds"
              class="visibility-admin__listbox"
              multiple
              size="8"
              aria-label="Activities Typ"
            >
              <option
                v-for="type in activityTypesForProgram"
                :key="type.id"
                :value="String(type.id)"
              >
                {{ type.name }}
              </option>
            </select>
          </div>
          <p class="visibility-admin__hint">Rechts leer = alle Typen des gewählten Programms.</p>
        </div>

        <div class="visibility-admin__filter">
          <div class="visibility-admin__filter-label">Rollen</div>
          <div class="visibility-admin__chips">
            <button
              v-for="program in rolePrograms"
              :key="programKey(program.id)"
              type="button"
              class="visibility-admin__chip"
              :class="{ 'visibility-admin__chip--on': selectedRoleProgramKeys.includes(programKey(program.id)) }"
              :aria-pressed="selectedRoleProgramKeys.includes(programKey(program.id))"
              @click="toggleRoleChip(programKey(program.id))"
            >
              <img
                v-if="logoFor(program.id, program.name, program.logo_stem)"
                :src="programLogoSrc(logoFor(program.id, program.name, program.logo_stem))"
                :alt="programLogoAlt(logoFor(program.id, program.name, program.logo_stem))"
                class="visibility-admin__logo"
              />
              {{ programLabel(program) }}
            </button>
          </div>
          <p class="visibility-admin__hint">Keine Auswahl = nichts. Mehrere Programme: ODER.</p>
        </div>
      </div>

      <div class="matrix-wrapper">
        <table class="sticky-matrix">
          <thead class="sticky-top">
            <tr>
              <th class="sticky-left activity-col">Activity</th>
              <th
                v-for="role in filteredRoles"
                :key="role.id"
                class="role-col"
                :title="roleTooltip(role)"
              >
                <div class="cell-head">
                  <img
                    v-if="logoFor(role.first_program, role.program, role.logo_stem)"
                    :src="programLogoSrc(logoFor(role.first_program, role.program, role.logo_stem))"
                    :alt="programLogoAlt(logoFor(role.first_program, role.program, role.logo_stem))"
                    class="visibility-admin__logo"
                  />
                  <span>{{ role.name }}</span>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="activity in filteredActivities" :key="activity.id">
              <td class="sticky-left activity-col" :title="activityTooltip(activity)">
                <div class="cell-head">
                  <img
                    v-if="logoFor(activity.first_program, activity.program, activity.logo_stem)"
                    :src="programLogoSrc(logoFor(activity.first_program, activity.program, activity.logo_stem))"
                    :alt="programLogoAlt(logoFor(activity.first_program, activity.program, activity.logo_stem))"
                    class="visibility-admin__logo"
                  />
                  <span>{{ activity.name }}</span>
                </div>
              </td>
              <td v-for="role in filteredRoles" :key="role.id" class="cell-check">
                <input
                  type="checkbox"
                  :checked="isVisible(role.id, activity.id)"
                  :disabled="toggling"
                  @click.prevent="toggleCell(role.id, activity.id)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-else class="visibility-admin__sort">
      <div class="visibility-admin__filter">
        <div class="visibility-admin__filter-label">Programm</div>
        <select v-model="sortProgramKey" class="visibility-admin__select">
          <option
            v-for="program in sortPrograms"
            :key="programKey(program.id)"
            :value="programKey(program.id)"
          >
            {{ programLabel(program) }}
          </option>
        </select>
      </div>

      <div class="visibility-admin__sort-list">
        <div class="visibility-admin__sort-hint">
          Ziehen zum Umsortieren · speichert <code>m_role.sequence</code> nur für dieses Programm
        </div>
        <draggable
          v-model="sortRoles"
          item-key="id"
          handle=".drag-handle"
          ghost-class="drag-ghost"
          animation="150"
        >
          <template #item="{ element: role, index }">
            <div class="visibility-admin__sort-row">
              <span class="visibility-admin__sort-index">{{ index + 1 }}</span>
              <span class="drag-handle">⋮⋮</span>
              <span class="flex-1 truncate">{{ role.name }}</span>
            </div>
          </template>
        </draggable>
        <p v-if="!sortRoles.length" class="px-3 py-4 text-sm text-[var(--color-text-subtle)]">
          Keine Rollen für dieses Programm.
        </p>
      </div>

      <button
        type="button"
        class="glass-btn-accent !px-4 !py-2 !text-sm self-start disabled:opacity-50"
        :disabled="savingSort || !sortRoles.length"
        @click="saveSort"
      >
        {{ savingSort ? 'Speichern…' : 'Reihenfolge speichern' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.visibility-admin {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 100%;
  height: 100%;
  min-height: 0;
  overflow: hidden;
}

.visibility-admin__tabs {
  display: flex;
  gap: 0.35rem;
  flex-shrink: 0;
}

.visibility-admin__tab {
  padding: 0.35rem 0.75rem;
  border-radius: var(--radius);
  border: 1px solid var(--color-border);
  background: var(--color-bg-muted, #f5f5f5);
  color: var(--color-text);
  font-size: 0.875rem;
  cursor: pointer;
}

.visibility-admin__tab--active {
  background: color-mix(in srgb, var(--color-accent) 16%, #fff);
  border-color: color-mix(in srgb, var(--color-accent) 40%, var(--color-border));
  font-weight: 650;
}

.visibility-admin__matrix-layout,
.visibility-admin__sort {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: 0;
  flex: 1 1 auto;
  overflow: hidden;
}

.visibility-admin__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  flex-shrink: 0;
}

.visibility-admin__filter {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-width: 0;
}

.visibility-admin__filter-label {
  font-size: 0.8rem;
  font-weight: 650;
  color: var(--color-text-muted);
}

.visibility-admin__hint {
  margin: 0;
  font-size: 0.7rem;
  color: var(--color-text-subtle);
}

.visibility-admin__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.visibility-admin__chip {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  border: 1px solid var(--color-border);
  background: #fff;
  font-size: 0.75rem;
  cursor: pointer;
}

.visibility-admin__chip--on {
  border-color: color-mix(in srgb, var(--color-accent) 50%, var(--color-border));
  background: color-mix(in srgb, var(--color-accent) 14%, #fff);
  font-weight: 650;
}

.visibility-admin__logo {
  width: 0.9rem;
  height: 0.9rem;
  flex-shrink: 0;
  object-fit: contain;
}

.visibility-admin__lists {
  display: flex;
  gap: 0.5rem;
}

.visibility-admin__listbox,
.visibility-admin__select {
  min-width: 10rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: #fff;
  color: var(--color-text);
  font-size: 0.75rem;
  padding: 0.2rem;
}

.visibility-admin__listbox {
  min-height: 8rem;
}

.matrix-wrapper {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  border: 1px solid var(--color-border);
  border-radius: 0.375rem;
}

.sticky-matrix {
  border-collapse: separate;
  border-spacing: 0;
  width: max-content;
  min-width: 100%;
  font-size: 0.7rem;
}

.sticky-top th {
  position: sticky;
  top: 0;
  z-index: 10;
  background: var(--color-bg-muted);
  font-weight: 650;
  color: var(--color-text);
  padding: 0.28rem 0.4rem;
  border-bottom: 1px solid var(--color-border);
  text-align: center;
}

.sticky-left {
  position: sticky;
  left: 0;
  z-index: 5;
}

.sticky-top th.sticky-left {
  z-index: 15;
  text-align: left;
}

.sticky-matrix tbody tr:nth-child(odd) td {
  background: #fff;
}

.sticky-matrix tbody tr:nth-child(even) td {
  background: color-mix(in srgb, var(--color-text) 4.5%, #fff);
}

.sticky-matrix tbody tr:hover td {
  background: color-mix(in srgb, var(--color-accent) 10%, #fff);
}

.activity-col {
  min-width: 9.5rem;
  max-width: 14rem;
  padding: 0.2rem 0.45rem;
}

.role-col {
  min-width: 4.5rem;
  max-width: 7.5rem;
}

.cell-head {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  min-width: 0;
}

.cell-head span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cell-check {
  text-align: center;
  padding: 0.15rem 0.3rem;
  border-bottom: 1px solid var(--color-border);
}

.visibility-admin__sort-list {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  border: 1px solid var(--color-border);
  border-radius: 0.375rem;
  background: #fff;
}

.visibility-admin__sort-hint {
  padding: 0.45rem 0.75rem;
  font-size: 0.7rem;
  color: var(--color-text-subtle);
  border-bottom: 1px solid var(--color-border);
  background: var(--color-bg-muted);
}

.visibility-admin__sort-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.4rem 0.75rem;
  border-bottom: 1px solid var(--color-border);
  font-size: 0.8125rem;
}

.visibility-admin__sort-index {
  width: 2rem;
  text-align: right;
  font-size: 0.7rem;
  color: var(--color-text-subtle);
}

.drag-handle {
  cursor: move;
  user-select: none;
  color: var(--color-text-subtle);
}

.drag-ghost {
  opacity: 0.45;
}
</style>
