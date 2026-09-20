<script setup lang="ts">
import {computed, onActivated, onUnmounted, ref, watch} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import StaffingScopeLeading from '@/components/volunteers/StaffingScopeLeading.vue'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {eventPrograms, programDisplayName, programId, type EventProgramRef} from '@/utils/eventPrograms'
import {flowFilename} from '@/utils/flowFilename'
import {subscribePlanPreviewReload} from '@/utils/planPreviewSync'
import type {StaffingFilterKey} from '@/utils/volunteerStaffingFilters'

defineOptions({name: 'RoleSheetsPrint'})

type CatalogProgram = {
  id: number
  display_name: string
  sequence: number
  name?: string | null
  logo_stem?: string | null
}

type CatalogRole = {
  id: number
  name: string
  first_program: number | null
}

type CatalogPayload = {
  plan_id?: number
  programs: CatalogProgram[]
  roles: CatalogRole[]
}

type RoleSheetGroup = {
  key: StaffingFilterKey
  label: string
  roles: CatalogRole[]
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const eventDate = computed(() => eventStore.selectedEvent?.date)

const catalog = ref<CatalogPayload | null>(null)
const selected = ref<Set<number>>(new Set())
const busy = ref(false)

const groups = computed<RoleSheetGroup[]>(() => {
  const roles = catalog.value?.roles ?? []
  const grouped: RoleSheetGroup[] = []
  const seen = new Set<number>()

  const joint = roles.filter((role) => role.first_program == null)
  if (joint.length) {
    grouped.push({key: 'cross', label: 'Übergreifend', roles: joint})
  }

  for (const program of eventPrograms(eventStore.selectedEvent)) {
    const id = programId(program)
    if (id <= 0 || seen.has(id)) continue
    seen.add(id)
    const programRoles = roles.filter((role) => role.first_program === id)
    if (!programRoles.length) continue
    grouped.push({
      key: `program:${id}`,
      label: programDisplayName(program),
      roles: programRoles,
    })
  }

  const leftover = [...(catalog.value?.programs ?? [])].sort(
    (a, b) => a.sequence - b.sequence || a.id - b.id,
  )
  for (const program of leftover) {
    if (seen.has(program.id)) continue
    const programRoles = roles.filter((role) => role.first_program === program.id)
    if (!programRoles.length) continue
    seen.add(program.id)
    grouped.push({
      key: `program:${program.id}`,
      label: programDisplayName(catalogProgramRef(program)),
      roles: programRoles,
    })
  }

  return grouped
})

function catalogProgramRef(program: CatalogProgram): EventProgramRef {
  return {
    first_program: program.id,
    id: program.id,
    name: program.name,
    display_name: program.display_name,
    sequence: program.sequence,
    logo_stem: program.logo_stem,
  }
}

function groupChecked(roleIds: number[]): boolean {
  return roleIds.length > 0 && roleIds.every((id) => selected.value.has(id))
}

function setSelected(ids: Iterable<number>) {
  selected.value = new Set(ids)
}

function toggleGroup(roleIds: number[], on: boolean) {
  const next = new Set(selected.value)
  for (const id of roleIds) {
    if (on) next.add(id)
    else next.delete(id)
  }
  selected.value = next
}

function toggleRole(id: number, on: boolean) {
  const next = new Set(selected.value)
  if (on) next.add(id)
  else next.delete(id)
  selected.value = next
}

function applyCatalog(data: CatalogPayload, preserveSelection: boolean) {
  const nextIds = (data.roles ?? []).map((role) => role.id)
  const previousIds = new Set((catalog.value?.roles ?? []).map((role) => role.id))
  catalog.value = data
  if (!preserveSelection || selected.value.size === 0) {
    setSelected(nextIds)
    return
  }
  const next = new Set<number>()
  for (const id of nextIds) {
    if (selected.value.has(id) || !previousIds.has(id)) next.add(id)
  }
  setSelected(next)
}

async function loadCatalog(preserveSelection = false) {
  if (!eventId.value) {
    catalog.value = null
    selected.value = new Set()
    return
  }
  try {
    const {data} = await axios.get<CatalogPayload>(`/print/${eventId.value}/role-sheets/catalog`)
    applyCatalog(data, preserveSelection)
  } catch {
    catalog.value = null
    selected.value = new Set()
  }
}

async function downloadPdf() {
  if (!eventId.value || selected.value.size === 0 || busy.value) return
  busy.value = true
  try {
    const response = await axios.post(
      `/print/${eventId.value}/role-sheets`,
      {role_ids: [...selected.value]},
      {responseType: 'blob'},
    )
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename'] || flowFilename('Rollenplaene', 'pdf', eventDate.value)
    link.click()
    window.URL.revokeObjectURL(url)
  } catch {
    showGlassToast('PDF erzeugen fehlgeschlagen', 'error')
  } finally {
    busy.value = false
  }
}

watch(eventId, () => {
  void loadCatalog(false)
}, {immediate: true})

// keep-alive: roles on the plan change when a new Ablauf is generated
onActivated(() => {
  void loadCatalog(true)
})

const stopPreviewReload = subscribePlanPreviewReload(
  () => catalog.value?.plan_id,
  () => {
    void loadCatalog(true)
  },
)
onUnmounted(() => stopPreviewReload())
</script>

<template>
  <article class="liquid-surface-inner role-sheets">
    <header class="role-sheets__head">
      <h2 class="role-sheets__title">Teams und Rollen</h2>
      <p class="role-sheets__sub">Alle Aktivitäten pro Team bzw. Helfer:innen-Rolle</p>
    </header>

    <div class="role-sheets__body">
      <div v-for="group in groups" :key="group.key" class="role-sheets__group">
        <label class="role-sheets__option role-sheets__option--program">
          <input
              type="checkbox"
              class="accent-[var(--color-accent)]"
              :checked="groupChecked(group.roles.map((role) => role.id))"
              @change="toggleGroup(group.roles.map((role) => role.id), ($event.target as HTMLInputElement).checked)"
          />
          <span class="role-sheets__program-label">
            <StaffingScopeLeading :filter-key="group.key" size="chip" :boxed="false"/>
            <span>{{ group.label }}</span>
          </span>
        </label>
        <label
            v-for="role in group.roles"
            :key="role.id"
            class="role-sheets__option role-sheets__option--role"
        >
          <input
              type="checkbox"
              class="accent-[var(--color-accent)]"
              :checked="selected.has(role.id)"
              @change="toggleRole(role.id, ($event.target as HTMLInputElement).checked)"
          />
          <span>{{ role.name }}</span>
        </label>
      </div>
    </div>

    <footer class="role-sheets__actions">
      <button
          type="button"
          class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
          :disabled="selected.size === 0 || !eventId || busy"
          @click="downloadPdf"
      >
        <Spinner v-if="busy" size="sm"/>
        <span>{{ busy ? 'Erzeuge…' : 'PDF erzeugen' }}</span>
      </button>
    </footer>
  </article>
</template>

<style scoped>
.role-sheets {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  min-width: 0;
  padding: 1rem 1.05rem 1.05rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 90%, var(--liquid-tile-bg-inner));
  box-shadow:
    0 8px 20px rgba(15, 23, 42, 0.045),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.role-sheets__title {
  margin: 0;
  font-size: 0.98rem;
  font-weight: 650;
  letter-spacing: -0.015em;
  line-height: 1.3;
}

.role-sheets__sub {
  margin: 0.28rem 0 0;
  font-size: 0.8rem;
  line-height: 1.4;
  color: var(--color-text-muted);
}

.role-sheets__body {
  min-height: 0;
}

.role-sheets__actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
}

.role-sheets__group + .role-sheets__group {
  margin-top: 0.5rem;
}

.role-sheets__option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.15rem 0;
  font-size: 0.9375rem;
}

.role-sheets__option--program {
  font-weight: 600;
}

.role-sheets__program-label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}

.role-sheets__option--role {
  padding-left: 1.5rem;
}
</style>
