<script setup lang="ts">
import draggable from 'vuedraggable'
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

// Daten
const items = ref<any[]>([])          // Backend-Daten
const loading = ref(true)
const error = ref<string|null>(null)

// UI-State
const expandedId = ref<number|null>(null)
const draftById = ref<Record<number, any>>({})
const savingId = ref<number|null>(null)

// Filter (Checkbox-Varianten)
const filterContexts = ref<string[]>(['input','expert'])
const filterPrograms = ref<number[]>([0,2,3])   // 0=gemeinsam, 2=Explore, 3=Challenge
const filterLevels   = ref<number[]>([1])

// Hilfs-Optionen
const contexts = ['protected', 'input', 'expert']
const types    = ['integer', 'decimal', 'time', 'date', 'boolean']

// Backend laden
async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await axios.get('/params')
    items.value = Array.isArray(data) ? data : (data?.items ?? [])
    items.value.sort((a,b) => (a.sequence ?? 0) - (b.sequence ?? 0))
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

  // Program (null/undefined => 0 = gemeinsam)
  const norm = (fp: any) => (fp == null ? 0 : Number(fp))
  list = filterPrograms.value.length
    ? list.filter(i => filterPrograms.value.includes(norm(i.first_program)))
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
    await axios.post(`/params/${item.id}`, payload)
    // In Originalliste zurückschreiben
    Object.assign(item, payload)
  } catch (e) {
    console.error('Update fehlgeschlagen', e)
    alert('Speichern fehlgeschlagen.')
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
    await axios.post('/params/reorder', { order: payload })
  } catch (e) {
    console.error('Reihenfolge speichern fehlgeschlagen', e)
    alert('Reihenfolge konnte nicht gespeichert werden.')
  }
}

// Farbstreifen nach context
const contextBarClass = (ctx: string | null | undefined) => {
  switch (ctx) {
    case 'input':     return 'bg-white border border-gray-300';
    case 'expert':    return 'bg-blue-500';
    case 'protected': return 'bg-black';
    default:          return 'bg-gray-200';
  }
}

// Icon je first_program (1=CHALLENGE, 2=EXPLORE)
const programIcon = (fp: number | null | undefined) => {
  const v = fp == null ? 0 : Number(fp)
  if (v === 2) return new URL('@/assets/FLL_Explore.png', import.meta.url).toString()
  if (v === 3) return new URL('@/assets/FLL_Challenge.png', import.meta.url).toString()
  return null // 0 (gemeinsam) → kein Icon
}
</script>

<template>
  <div class="space-y-4">

    <!-- Filterleiste -->
    <div class="flex flex-wrap items-center gap-3 mb-3">
    <!-- Context -->
    <div class="inline-flex items-center gap-3 px-3 py-2 border border-gray-300 rounded-md bg-white shadow-sm whitespace-nowrap">
        <div class="text-sm font-medium text-gray-700">Context:</div>
        <div class="flex items-center gap-3">
        <label v-for="ctx in ['protected', 'input','expert',]" :key="ctx" class="flex items-center gap-1 text-sm text-gray-600">
            <input type="checkbox" v-model="filterContexts" :value="ctx" class="accent-gray-600" />
            {{ ctx }}
        </label>
        </div>
    </div>

    <!-- Program -->
    <div class="inline-flex items-center gap-3 px-3 py-2 border border-gray-300 rounded-md bg-white shadow-sm whitespace-nowrap">
        <div class="text-sm font-medium text-gray-700">Programm:</div>
        <div class="flex items-center gap-3">
        <label v-for="prog in [{value:0,label:'gemeinsam'},{value:2,label:'Explore'},{value:3,label:'Challenge'}]"
                :key="prog.value"
                class="flex items-center gap-1 text-sm text-gray-600">
            <input type="checkbox" v-model="filterPrograms" :value="prog.value" class="accent-gray-600" />
            {{ prog.label }}
        </label>
        </div>
    </div>

    <!-- Level -->
    <div class="inline-flex items-center gap-3 px-3 py-2 border border-gray-300 rounded-md bg-white shadow-sm whitespace-nowrap">
        <div class="text-sm font-medium text-gray-700">Level:</div>
        <div class="flex items-center gap-3">
        <label v-for="lvl in [1,2,3]" :key="lvl" class="flex items-center gap-1 text-sm text-gray-600">
            <input type="checkbox" v-model="filterLevels" :value="lvl" class="accent-gray-600" />
            {{ lvl }}
        </label>
        </div>
    </div>
    </div>

    <!-- Liste -->
    <div class="border rounded bg-white">
      <div v-if="loading" class="p-4 text-gray-500">Lade …</div>
      <div v-else-if="error" class="p-4 text-red-600">{{ error }}</div>
      <div v-else>
        <div class="px-3 py-2 text-xs text-gray-500 border-b bg-gray-50">
          Ziehen zum Umsortieren · Sortiert nach <code>sequence</code>
        </div>

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
                        <span class="w-8 text-right text-xs text-gray-500">#{{ item.sequence }}</span>
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
                            v-if="programIcon(item.first_program)"
                            :src="programIcon(item.first_program)"
                            alt=""
                            class="ml-2 w-5 h-5 flex-shrink-0"
                        />
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="font-medium truncate">
                            {{ item.name || '(ohne Name)' }}
                            <span class="ml-2 text-sm text-gray-500">
                                {{ item.ui_label || '—' }} = {{ item.value || '—' }} 
                            </span>
                            </div>
                        </div>

                        <button
                        class="text-sm px-2 py-1 rounded bg-gray-100 hover:bg-gray-200"
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
                            <label class="block text-xs text-gray-500 mb-1">ID</label>
                            <div class="w-full border rounded px-2 py-1 bg-gray-50 text-sm text-gray-700">
                            {{ item.id }}
                            </div>
                        </div>

                        <!-- Name -->
                        <div class="md:col-span-3">
                            <label class="block text-xs text-gray-500 mb-1">Name</label>
                            <input
                            v-model="draftById[item.id].name"
                            class="w-full border rounded px-2 py-1"
                            />
                        </div>

                        <!-- UI Label -->
                        <div class="md:col-span-4">
                            <label class="block text-xs text-gray-500 mb-1">UI Label</label>
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
                                <label class="block text-xs text-gray-500 mb-1">Context</label>
                                <select v-model="draftById[item.id].context" class="w-full border rounded px-2 py-1 bg-white text-sm text-gray-800 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option v-for="c in contexts" :key="c" :value="c">{{ c }}</option>
                                </select>
                                </div>

                                <div>
                                <label class="block text-xs text-gray-500 mb-1">Program</label>
                                <select v-model="draftById[item.id].first_program" class="w-full border rounded px-2 py-1 bg-white text-sm text-gray-800 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option :value="null">(gemeinsam)</option>
                                    <option :value="2">Explore</option>
                                    <option :value="3">Challenge</option>
                                </select>
                                </div>

                                <div>
                                <label class="block text-xs text-gray-500 mb-1">Level</label>
                                <select v-model.number="draftById[item.id].level" class="w-full border rounded px-2 py-1 bg-white text-sm text-gray-800 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option :value="1">1</option>
                                    <option :value="2">2</option>
                                    <option :value="3">3</option>
                                </select>
                                </div>
                            </div>

                            <!-- Box 2: Type / Value / Min / Max / Step -->
                            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                                <div>
                                <label class="block text-xs text-gray-500 mb-1">Type</label>
                                <select v-model="draftById[item.id].type" class="w-full border rounded px-2 py-1 bg-white text-sm text-gray-800 focus:ring-1 focus:ring-blue-500 focus:border-blue-500">
                                    <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                                </select>
                                </div>
                                <div>
                                <label class="block text-xs text-gray-500 mb-1">Wert</label>
                                <input v-model="draftById[item.id].value" class="w-full border rounded px-2 py-1" />
                                </div>
                                <div>
                                <label class="block text-xs text-gray-500 mb-1">Min</label>
                                <input v-model="draftById[item.id].min" class="w-full border rounded px-2 py-1" />
                                </div>
                                <div>
                                <label class="block text-xs text-gray-500 mb-1">Max</label>
                                <input v-model="draftById[item.id].max" class="w-full border rounded px-2 py-1" />
                                </div>
                                <div>
                                <label class="block text-xs text-gray-500 mb-1">Step</label>
                                <input v-model="draftById[item.id].step" class="w-full border rounded px-2 py-1" />
                                </div>
                            </div>
                        </div>

                        <!-- UI Beschreibung mit Spellcheck im Browser -->
                        <div class="mt-3">
                            <label class="block text-xs text-gray-500 mb-1">UI Beschreibung</label>
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
                            class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200"
                            @click="discard(item)"
                            >
                            Verwerfen
                            </button>
                        </div>
                    </div>

                </div> 

            </template> 

        </draggable>

        <div v-if="filtered.length === 0 && !loading" class="px-4 py-6 text-gray-500">
          Keine Einträge für diese Filter.
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.drag-ghost { opacity: 0.4; transform: scale(0.98); }
.drag-chosen { background-color: #e5e7eb; } /* gray-200 */
.drag-dragging { cursor: grabbing; }
</style>