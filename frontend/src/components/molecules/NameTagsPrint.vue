<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import StaffingScopeLeading from '@/components/volunteers/StaffingScopeLeading.vue'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {eventPrograms, programDisplayName, programId} from '@/utils/eventPrograms'
import {flowFilename} from '@/utils/flowFilename'
import type {StaffingFilterKey} from '@/utils/volunteerStaffingFilters'
import type {StaffingRole} from '@/volunteers/staffingTypes'

defineOptions({name: 'NameTagsPrint'})

const props = defineProps<{
  logoId?: number | null
}>()

type PersonLeaf = 'coaches' | 'players' | 'helpers'

type PersonLeafRow = {
  key: string
  leaf: PersonLeaf
  label: string
}

type PersonGroup = {
  key: StaffingFilterKey
  label: string
  leaves: PersonLeafRow[]
}

type StaffingPayload = {
  roles?: StaffingRole[]
}

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const eventDate = computed(() => eventStore.selectedEvent?.date)

const roles = ref<StaffingRole[]>([])
const selected = ref<Set<string>>(new Set())
const skipOffset = ref(0)
const busy = ref(false)

const hasCrossHelpers = computed(() =>
  roles.value.some((role) => !role.is_local && role.first_program == null),
)
const hasLocalHelpers = computed(() => roles.value.some((role) => role.is_local))
const helperProgramIds = computed(() => {
  const ids = new Set<number>()
  for (const role of roles.value) {
    if (role.is_local) continue
    const id = role.first_program
    if (id != null && id > 0) ids.add(id)
  }
  return ids
})

const groups = computed<PersonGroup[]>(() => {
  const rows: PersonGroup[] = []
  if (hasCrossHelpers.value) {
    rows.push({
      key: 'cross',
      label: 'Übergreifend',
      leaves: [{key: 'cross:helpers', leaf: 'helpers', label: 'Helfer:innen'}],
    })
  }
  for (const program of eventPrograms(eventStore.selectedEvent)) {
    const id = programId(program)
    if (id <= 0) continue
    const leaves: PersonLeafRow[] = [
      {key: `program:${id}:coaches`, leaf: 'coaches', label: 'Coach:innen'},
      {key: `program:${id}:players`, leaf: 'players', label: 'Teammitglieder'},
    ]
    if (helperProgramIds.value.has(id)) {
      leaves.push({key: `program:${id}:helpers`, leaf: 'helpers', label: 'Helfer:innen'})
    }
    rows.push({
      key: `program:${id}`,
      label: programDisplayName(program),
      leaves,
    })
  }
  if (hasLocalHelpers.value) {
    rows.push({
      key: 'local',
      label: 'Zusätzlich',
      leaves: [{key: 'local:helpers', leaf: 'helpers', label: 'Helfer:innen'}],
    })
  }
  return rows
})

const allLeafKeys = computed(() => groups.value.flatMap((group) => group.leaves.map((leaf) => leaf.key)))

watch(allLeafKeys, (keys, previous) => {
  const prev = new Set(previous ?? [])
  if (selected.value.size === 0) {
    selected.value = new Set(keys)
    return
  }
  const next = new Set<string>()
  for (const key of keys) {
    if (selected.value.has(key) || !prev.has(key)) next.add(key)
  }
  selected.value = next
}, {immediate: true})

function groupChecked(leaves: PersonLeafRow[]): boolean {
  return leaves.length > 0 && leaves.every((leaf) => selected.value.has(leaf.key))
}

function toggleGroup(leaves: PersonLeafRow[], on: boolean) {
  const next = new Set(selected.value)
  for (const leaf of leaves) {
    if (on) next.add(leaf.key)
    else next.delete(leaf.key)
  }
  selected.value = next
}

function toggleLeaf(key: string, on: boolean) {
  const next = new Set(selected.value)
  if (on) next.add(key)
  else next.delete(key)
  selected.value = next
}

function filtersFromSelection(): Record<string, Record<string, boolean>> {
  const filters: Record<string, Record<string, boolean>> = {}
  for (const group of groups.value) {
    const bucket: Record<string, boolean> = {}
    for (const leaf of group.leaves) {
      bucket[leaf.leaf] = selected.value.has(leaf.key)
    }
    filters[group.key] = bucket
  }
  return filters
}

async function errorFromBody(data: unknown): Promise<string> {
  if (data && typeof data === 'object' && !(data instanceof Blob) && 'error' in data) {
    const message = (data as {error?: string}).error
    if (message) return message
  }
  if (data instanceof Blob) {
    try {
      const json = JSON.parse(await data.text()) as {error?: string}
      if (json.error) return json.error
    } catch {
      // not JSON
    }
  }
  return 'PDF erzeugen fehlgeschlagen'
}

async function errorMessage(error: unknown): Promise<string> {
  return errorFromBody(axios.isAxiosError(error) ? error.response?.data : null)
}

async function loadStaffing() {
  if (!eventId.value) {
    roles.value = []
    return
  }
  try {
    const {data} = await axios.get<StaffingPayload>(`/events/${eventId.value}/staffing`)
    roles.value = data.roles ?? []
  } catch {
    roles.value = []
  }
}

watch(eventId, () => {
  void loadStaffing()
}, {immediate: true})

async function downloadPdf() {
  if (!eventId.value || selected.value.size === 0 || busy.value) return
  busy.value = true
  try {
    const response = await axios.post(
      `/export/name-tags/${eventId.value}`,
      {
        logo_id: props.logoId ?? null,
        skip_offset: Math.max(0, Math.min(9, Number(skipOffset.value) || 0)),
        filters: filtersFromSelection(),
      },
      {responseType: 'blob'},
    )
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename'] || flowFilename('Namensschilder', 'pdf', eventDate.value)
    link.click()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    showGlassToast(await errorMessage(error), 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article class="liquid-surface-inner role-sheets">
    <p class="role-sheets__sub">
      No-Show-Teams und Teams über der geplanten Anzahl werden nicht übernommen.
    </p>

    <div class="role-sheets__body">
      <div v-for="group in groups" :key="group.key" class="role-sheets__group">
        <label class="role-sheets__option role-sheets__option--program">
          <input
              type="checkbox"
              class="accent-[var(--color-accent)]"
              :checked="groupChecked(group.leaves)"
              @change="toggleGroup(group.leaves, ($event.target as HTMLInputElement).checked)"
          />
          <span class="role-sheets__program-label">
            <StaffingScopeLeading :filter-key="group.key" size="chip" :boxed="false"/>
            <span>{{ group.label }}</span>
          </span>
        </label>
        <label
            v-for="leaf in group.leaves"
            :key="leaf.key"
            class="role-sheets__option role-sheets__option--role"
        >
          <input
              type="checkbox"
              class="accent-[var(--color-accent)]"
              :checked="selected.has(leaf.key)"
              @change="toggleLeaf(leaf.key, ($event.target as HTMLInputElement).checked)"
          />
          <span>{{ leaf.label }}</span>
        </label>
      </div>
    </div>

    <footer class="role-sheets__actions">
      <label class="role-sheets__skip">
        <span>Überspringen:</span>
        <input
            v-model.number="skipOffset"
            type="number"
            min="0"
            max="9"
            class="role-sheets__skip-input"
        />
      </label>
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

.role-sheets__sub {
  margin: 0;
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
  gap: 0.75rem;
}

.role-sheets__skip {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.role-sheets__skip-input {
  width: 3rem;
  border: 1px solid var(--color-border);
  border-radius: 0.25rem;
  padding: 0.15rem 0.25rem;
  text-align: center;
  font-size: 0.875rem;
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
