<script setup lang="ts">
import draggable from 'vuedraggable'
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import {showGlassToast} from '@/composables/useGlassToast'
import { programDisplayName, programId, type EventProgramRef } from '@/utils/eventPrograms'
import {useProgramsStore} from '@/stores/programs'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'

/** 0 = Übergreifend (first_program null), otherwise m_first_program.id */
type ProgramFilterKey = number

const programsStore = useProgramsStore()

// Daten
const items = ref<any[]>([])          // Backend-Daten
const catalogPrograms = computed<EventProgramRef[]>(() => {
  const rows = [...programsStore.catalog]
  rows.sort((a, b) => {
    const seqA = a.sequence ?? Number.POSITIVE_INFINITY
    const seqB = b.sequence ?? Number.POSITIVE_INFINITY
    if (seqA !== seqB) return seqA - seqB
    return programId(a) - programId(b)
  })
  return rows
})

const programsWithParameters = computed<EventProgramRef[]>(() => {
  const ids = new Set(
    items.value.map((row) => Number(row.first_program ?? 0)).filter((id) => id > 0),
  )
  return catalogPrograms.value.filter((program) => ids.has(programId(program)))
})
const loading = ref(true)
const error = ref<string|null>(null)

// UI-State
const expandedId = ref<number|null>(null)
const draftById = ref<Record<number, any>>({})
const savingId = ref<number|null>(null)

// Filter
const filterContexts = ref<string[]>(['input','expert'])
const activeProgramFilters = ref<Set<ProgramFilterKey>>(new Set())
const filterLevels   = ref<number[]>([1])

function logoForProgramId(id: number) {
    return catalogPrograms.value.find(p => programId(p) === id) || { first_program: id }
}

function itemProgramFilterKey(item: {first_program?: number | null}): ProgramFilterKey {
  const id = Number(item.first_program ?? 0)
  return id > 0 ? id : 0
}

function programFilterKeys(): ProgramFilterKey[] {
  return [0, ...programsWithParameters.value.map((program) => programId(program))]
}

function syncProgramFilters() {
  const keys = programFilterKeys()
  const kept = keys.filter((key) => activeProgramFilters.value.has(key))
  activeProgramFilters.value = new Set(kept.length > 0 ? kept : keys)
}

function isProgramFilterActive(key: ProgramFilterKey) {
  return activeProgramFilters.value.has(key)
}

function toggleProgramFilter(key: ProgramFilterKey) {
  const next = new Set(activeProgramFilters.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  activeProgramFilters.value = next
}

// Hilfs-Optionen
const contexts = ['protected', 'input', 'expert', 'afternoon']
const types    = ['integer', 'decimal', 'time', 'date', 'boolean']

// Backend laden
async function load() {
  loading.value = true
  error.value = null
  try {
    const [{ data: paramData }] = await Promise.all([
      axios.get('/mparams'),
      programsStore.ensureLoaded(),
    ])
    items.value = Array.isArray(paramData) ? paramData : (paramData?.items ?? [])
    items.value.sort((a,b) => (a.sequence ?? 0) - (b.sequence ?? 0))
    syncProgramFilters()
  } catch (e) {
    console.error(e)
    error.value = 'Fehler beim Laden.'
  } finally {
    loading.value = false
  }
}
onMounted(load)


// Gefilterte + sortierte Liste
const filtered = computed(() => {
  let list = [...items.value]

  // Context
  list = filterContexts.value.length
    ? list.filter(i => filterContexts.value.includes(i.context))
    : []

  // Program: Übergreifend (null) + catalog programs — same toggle set as Zuordnung
  list = activeProgramFilters.value.size
    ? list.filter((i) => activeProgramFilters.value.has(itemProgramFilterKey(i)))
    : []

  // Level (Checkbox-Logik)
  list = filterLevels.value.length
    ? list.filter(i => filterLevels.value.includes(Number(i.level)))
    : []

  // Sortierung
  list.sort((a,b) => (a.sequence ?? 0) - (b.sequence ?? 0))
  return list
})

// Aufklappen / Draft füllen
function toggleExpand(item:any) {
  if (expandedId.value === item.id) {
    expandedId.value = null
    return
  }
  expandedId.value = item.id
  draftById.value[item.id] = {
    name: item.name ?? '',
    ui_label: item.ui_label ?? '',
    ui_description: item.ui_description ?? '',
    context: item.context ?? 'input',
    level: item.level ?? 0,
    type: item.type ?? 'integer',
    value: item.value ?? '',
    min: item.min ?? '',
    max: item.max ?? '',
    step: item.step ?? '',
    first_program: item.first_program ?? null,
  }
}

function discard(item:any) {
  draftById.value[item.id] = {
    name: item.name ?? '',
    ui_label: item.ui_label ?? '',
    ui_description: item.ui_description ?? '',
    context: item.context ?? 'input',
    level: item.level ?? 0,
    type: item.type ?? 'integer',
    value: item.value ?? '',
    min: item.min ?? '',
    max: item.max ?? '',
    step: item.step ?? '',
    first_program: item.first_program ?? null,
  }
}

// Speichern 
async function save(item) {
  const draft = draftById.value[item.id]
  if (!draft) return

  // Sicherheitsabfrage: Name geändert?
  const nameChanged =
    (item.name ?? '') !== (draft.name ?? '')

  if (nameChanged) {
    const ok = confirm(
      `Der Name wurde geändert:\n\nAlt: "${item.name || '—'}"\nNeu: "${draft.name || '—'}"\n\nÄnderung wirklich speichern?`
    )
    if (!ok) return
  }

  savingId.value = item.id
  try {
    const payload = { ...draft }
    await axios.post(`/mparams/${item.id}`, payload)
    // In Originalliste zurückschreiben
    Object.assign(item, payload)
  } catch (e) {
    console.error('Update fehlgeschlagen', e)
    showGlassToast('Speichern fehlgeschlagen.', 'error')
  } finally {
    savingId.value = null
  }
}

// Drag&Drop – Reihenfolge sichern
async function onSort() {
  // globale Reihung (ohne Filter)
  const payload = items.value.map((p, idx) => ({ id: p.id, sequence: idx + 1 }))

  // lokale Reihenfolge spiegeln
  items.value = items.value
    .map((it, idx) => ({ ...it, sequence: idx + 1 }))

  try {
    await axios.post('/mparams/reorder', { order: payload })
  } catch (e) {
    console.error('Reihenfolge speichern fehlgeschlagen', e)
    showGlassToast('Reihenfolge konnte nicht gespeichert werden.', 'error')
  }
}

// Farbstreifen nach context
const contextBarClass = (ctx: string | null | undefined) => {
  switch (ctx) {
    case 'input':     return 'bg-white border border-[var(--color-border)]';
    case 'expert':    return 'bg-blue-500';
    case 'protected': return 'bg-black';
    case 'afternoon': return 'bg-amber-500';
    default:          return 'bg-gray-200';
  }
}

</script>

<template>
  <div class="mparam-shell">

    <!-- Filterleiste -->
    <div class="mparam-shell__filters flex flex-wrap items-center gap-3">
    <div class="vol-staffing-filters mparam-shell__program-filters">
      <button
          type="button"
          class="vol-staffing-filter"
          :class="{'vol-staffing-filter--active': isProgramFilterActive(0)}"
          :aria-pressed="isProgramFilterActive(0)"
          @click="toggleProgramFilter(0)"
      >
        <i class="bi bi-intersect vol-staffing-filter__icon" aria-hidden="true"/>
        <span class="vol-staffing-filter__label">Übergreifend</span>
      </button>
      <template v-if="programsWithParameters.length > 1">
      <button
          v-for="program in programsWithParameters"
          :key="`filter-program-${programId(program)}`"
          type="button"
          class="vol-staffing-filter"
          :class="{'vol-staffing-filter--active': isProgramFilterActive(programId(program))}"
          :aria-pressed="isProgramFilterActive(programId(program))"
          @click="toggleProgramFilter(programId(program))"
      >
        <ProgramLogo
            :program="program"
            size="chip"
            decorative
            class="vol-staffing-filter__logo"
        />
        <span class="vol-staffing-filter__label">{{ programDisplayName(program) }}</span>
      </button>
      </template>
    </div>

    <!-- Context -->
    <div class="glass-row-item inline-flex gap-3 px-3 py-2 whitespace-nowrap">
        <div class="text-sm font-medium text-[var(--color-text-muted)]">Context:</div>
        <div class="flex items-center gap-3">
        <label v-for="ctx in contexts" :key="ctx" class="flex items-center gap-1 text-sm text-[var(--color-text-muted)]">
            <input type="checkbox" v-model="filterContexts" :value="ctx" class="accent-gray-600" />
            {{ ctx }}
        </label>
        </div>
    </div>

    <!-- Level -->
    <div class="glass-row-item inline-flex gap-3 px-3 py-2 whitespace-nowrap">
        <div class="text-sm font-medium text-[var(--color-text-muted)]">Level:</div>
        <div class="flex items-center gap-3">
        <label v-for="lvl in [1,2,3]" :key="lvl" class="flex items-center gap-1 text-sm text-[var(--color-text-muted)]">
            <input type="checkbox" v-model="filterLevels" :value="lvl" class="accent-gray-600" />
            {{ lvl }}
        </label>
        </div>
    </div>
    </div>

    <!-- Liste -->
    <div class="mparam-shell__list border rounded bg-white">
      <div v-if="loading" class="p-4 text-[var(--color-text-subtle)]">Laden…</div>
      <div v-else-if="error" class="p-4 text-red-600">{{ error }}</div>
      <div v-else class="mparam-shell__list-inner">
        <div class="mparam-shell__list-hint px-3 py-2 text-xs text-[var(--color-text-subtle)] border-b bg-[var(--color-bg-muted)]">
          Ziehen zum Umsortieren · Sortiert nach <code>sequence</code>
        </div>

        <div class="mparam-shell__list-scroll">
        <draggable
          v-model="items"   
          item-key="id"
          handle=".drag-handle"
          @end="onSort"
          ghost-class="drag-ghost"
          chosen-class="drag-chosen"
          drag-class="drag-dragging"
          animation="150"
           >
            <template #item="{ element: item, index }">
                <div v-if="filtered.includes(item)" class="border-b">
                    <!-- Kopfzeile (kompakt) -->
                    <div class="flex items-center gap-3 px-3 py-2">
                        <span class="w-8 text-right text-xs text-[var(--color-text-subtle)]">#{{ item.sequence }}</span>
                        <span class="drag-handle cursor-move select-none">⋮⋮</span>

                        <!-- Fix breiter Kontext/Programm-Bereich -->
                        <div class="w-16 flex items-center">
                        <!-- schmaler vertikaler Farbstreifen -->
                        <span
                            :class="['h-6 w-1 rounded-sm', contextBarClass(item.context)]"
                            aria-hidden="true"
                        ></span>

                        <!-- Programm-Icon (optional, kein Text) -->
                        <img 
                            v-if="item.first_program"
                            :src="programLogoSrc(logoForProgramId(item.first_program))"
                            :alt="programLogoAlt(logoForProgramId(item.first_program))"
                            class="ml-2 w-5 h-5 flex-shrink-0"
                        />
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="font-medium truncate">
                            {{ item.name || '(ohne Name)' }}
                            <span class="ml-2 text-sm text-[var(--color-text-subtle)]">
                                {{ item.ui_label || '—' }} = {{ item.value || '—' }} 
                            </span>
                            </div>
                        </div>

                        <button
                        class="text-sm px-2 py-1 rounded bg-[var(--color-bg-muted)] hover:bg-[var(--color-bg-hover)]"
                        @click="toggleExpand(item)"
                        >
                        {{ expandedId === item.id ? 'Schließen' : 'Bearbeiten' }}
                        </button>
                    </div>

                    <!-- Detailbereich (Edit) -->
                    <div v-if="expandedId === item.id" class="px-3 pb-3">
                        <!-- ID / Name / UI Label -->
                        <div class="grid grid-cols-1 md:grid-cols-8 gap-3">
                        <!-- ID -->
                        <div class="md:col-span-1">
                            <label class="block text-xs text-[var(--color-text-subtle)] mb-1">ID</label>
                            <div class="w-full border rounded px-2 py-1 bg-[var(--color-bg-muted)] text-sm text-[var(--color-text-muted)]">
                            {{ item.id }}
                            </div>
                        </div>

                        <!-- Name -->
                        <div class="md:col-span-3">
                            <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Name</label>
                            <input
                            v-model="draftById[item.id].name"
                            class="w-full border rounded px-2 py-1"
                            />
                        </div>

                        <!-- UI Label -->
                        <div class="md:col-span-4">
                            <label class="block text-xs text-[var(--color-text-subtle)] mb-1">UI Label</label>
                            <input
                            v-model="draftById[item.id].ui_label"
                            class="w-full border rounded px-2 py-1"
                            />
                        </div>
                        </div>

                        <!-- Zwei Boxen nebeneinander -->
                        <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Box 1: Context / Program / Level -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div>
                                <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Context</label>
                                <select v-model="draftById[item.id].context" class="w-full border rounded px-2 py-1 bg-white text-sm text-[var(--color-text)] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option v-for="c in contexts" :key="c" :value="c">{{ c }}</option>
                                </select>
                                </div>

                                <div>
                                <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Program</label>
                                <select v-model="draftById[item.id].first_program" class="w-full border rounded px-2 py-1 bg-white text-sm text-[var(--color-text)] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option :value="null">Übergreifend</option>
                                    <option
                                        v-for="program in catalogPrograms"
                                        :key="program.id"
                                        :value="program.id"
                                    >{{ programDisplayName(program) }}</option>
                                </select>
                                </div>

                                <div>
                                <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Level</label>
                                <select v-model.number="draftById[item.id].level" class="w-full border rounded px-2 py-1 bg-white text-sm text-[var(--color-text)] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option :value="1">1</option>
                                    <option :value="2">2</option>
                                    <option :value="3">3</option>
                                </select>
                                </div>
                            </div>

                            <!-- Box 2: Type / Value / Min / Max / Step -->
                            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                                <div>
                                <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Type</label>
                                <select v-model="draftById[item.id].type" class="w-full border rounded px-2 py-1 bg-white text-sm text-[var(--color-text)] focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                                </select>
                                </div>
                                <div>
                                <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Wert</label>
                                <input v-model="draftById[item.id].value" class="w-full border rounded px-2 py-1" />
                                </div>
                                <div>
                                <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Min</label>
                                <input v-model="draftById[item.id].min" class="w-full border rounded px-2 py-1" />
                                </div>
                                <div>
                                <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Max</label>
                                <input v-model="draftById[item.id].max" class="w-full border rounded px-2 py-1" />
                                </div>
                                <div>
                                <label class="block text-xs text-[var(--color-text-subtle)] mb-1">Step</label>
                                <input v-model="draftById[item.id].step" class="w-full border rounded px-2 py-1" />
                                </div>
                            </div>
                        </div>

                        <!-- UI Beschreibung mit Spellcheck im Browser -->
                        <div class="mt-3">
                            <label class="block text-xs text-[var(--color-text-subtle)] mb-1">UI Beschreibung</label>
                            <textarea v-model="draftById[item.id].ui_description" rows="3" class="w-full border rounded px-2 py-1"
                        
                            spellcheck="true"
                            autocorrect="off"
                            autocomplete="on"
                        
                            ></textarea>
                        </div>

                        <!-- Aktionen -->
                        <div class="mt-3 flex gap-2">
                            <button
                            class="px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-50"
                            :disabled="savingId === item.id"
                            @click="save(item)"
                            >
                            {{ savingId === item.id ? 'Speichern…' : 'Speichern' }}
                            </button>
                            <button
                            class="px-3 py-1 rounded bg-[var(--color-bg-muted)] hover:bg-[var(--color-bg-hover)]"
                            @click="discard(item)"
                            >
                            Verwerfen
                            </button>
                        </div>
                    </div>

                </div> 

            </template> 

        </draggable>

        <div v-if="filtered.length === 0 && !loading" class="px-4 py-6 text-[var(--color-text-subtle)]">
          Keine Einträge für diese Filter.
        </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.mparam-shell {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  height: 100%;
  min-height: 0;
  overflow: hidden;
}

.mparam-shell__filters {
  flex-shrink: 0;
}

.mparam-shell__program-filters {
  flex: 1 1 100%;
  margin-bottom: 0;
}

.mparam-shell__list {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.mparam-shell__list-inner {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.mparam-shell__list-hint {
  flex-shrink: 0;
}

.mparam-shell__list-scroll {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
}

.drag-ghost { opacity: 0.4; transform: scale(0.98); }
.drag-chosen { background-color: #e5e7eb; } /* gray-200 */
.drag-dragging { cursor: grabbing; }
</style>