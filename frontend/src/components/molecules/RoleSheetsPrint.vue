<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {flowFilename} from '@/utils/flowFilename'

defineOptions({name: 'RoleSheetsPrint'})

type CatalogProgram = {
  id: number
  display_name: string
  sequence: number
}

type CatalogRole = {
  id: number
  name: string
  first_program: number | null
}

type CatalogPayload = {
  programs: CatalogProgram[]
  roles: CatalogRole[]
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const eventDate = computed(() => eventStore.selectedEvent?.date)

const catalog = ref<CatalogPayload | null>(null)
const selected = ref<Set<number>>(new Set())
const busy = ref(false)

const allRoleIds = computed(() => (catalog.value?.roles ?? []).map((role) => role.id))

const groups = computed(() => {
  const roles = catalog.value?.roles ?? []
  const programs = [...(catalog.value?.programs ?? [])].sort((a, b) => a.sequence - b.sequence || a.id - b.id)
  const grouped = programs
    .map((program) => ({
      key: String(program.id),
      label: program.display_name,
      roles: roles.filter((role) => role.first_program === program.id),
    }))
    .filter((group) => group.roles.length > 0)
  const general = roles.filter((role) => role.first_program == null)
  if (general.length) {
    grouped.push({key: 'general', label: 'Allgemein', roles: general})
  }
  return grouped
})

const allChecked = computed(() => allRoleIds.value.length > 0 && allRoleIds.value.every((id) => selected.value.has(id)))

function groupChecked(roleIds: number[]): boolean {
  return roleIds.length > 0 && roleIds.every((id) => selected.value.has(id))
}

function setSelected(ids: Iterable<number>) {
  selected.value = new Set(ids)
}

function toggleAll(on: boolean) {
  setSelected(on ? allRoleIds.value : [])
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

async function loadCatalog() {
  if (!eventId.value) {
    catalog.value = null
    selected.value = new Set()
    return
  }
  try {
    const {data} = await axios.get<CatalogPayload>(`/print/${eventId.value}/role-sheets/catalog`)
    catalog.value = data
    setSelected((data.roles ?? []).map((role) => role.id))
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
  void loadCatalog()
}, {immediate: true})
</script>

<template>
  <section class="glass-card liquid-surface-inner role-sheets">
    <header class="role-sheets__head">
      <div>
        <h2 class="role-sheets__title">Rollenpläne</h2>
        <p class="role-sheets__sub">Bitte wähle Programme und Rollen.</p>
      </div>
      <button
          type="button"
          class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
          :disabled="selected.size === 0 || !eventId || busy"
          @click="downloadPdf"
      >
        <Spinner v-if="busy" size="sm"/>
        <span>{{ busy ? 'Erzeuge…' : 'PDF erzeugen' }}</span>
      </button>
    </header>

    <label class="role-sheets__option role-sheets__option--root">
      <input
          type="checkbox"
          class="accent-[var(--color-accent)]"
          :checked="allChecked"
          :disabled="allRoleIds.length === 0"
          @change="toggleAll(($event.target as HTMLInputElement).checked)"
      />
      <span>Alle</span>
    </label>

    <div v-for="group in groups" :key="group.key" class="role-sheets__group">
      <label class="role-sheets__option role-sheets__option--program">
        <input
            type="checkbox"
            class="accent-[var(--color-accent)]"
            :checked="groupChecked(group.roles.map((role) => role.id))"
            @change="toggleGroup(group.roles.map((role) => role.id), ($event.target as HTMLInputElement).checked)"
        />
        <span>{{ group.label }}</span>
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
  </section>
</template>

<style scoped>
.role-sheets {
  padding: 1rem 1.25rem;
  margin-bottom: 1rem;
}

.role-sheets__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.role-sheets__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 650;
}

.role-sheets__sub {
  margin: 0.2rem 0 0;
  font-size: 0.875rem;
  color: var(--color-text-subtle);
}

.role-sheets__group {
  margin-top: 0.5rem;
}

.role-sheets__option {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.15rem 0;
  font-size: 0.9375rem;
}

.role-sheets__option--root {
  font-weight: 650;
}

.role-sheets__option--program {
  font-weight: 600;
}

.role-sheets__option--role {
  padding-left: 1.5rem;
}
</style>
