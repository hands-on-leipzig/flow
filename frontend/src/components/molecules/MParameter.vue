<script setup>
import draggable from 'vuedraggable'
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

// Filter-Modelle
const filterContext = ref('all')        // 'all' | 'input' | 'expert' | 'protected' | 'finale'
const filterLevel = ref('all')          // 'all' | <zahl>
const filterProgram = ref('all')        // 'all' | 1 (CHALLENGE) | 2 (EXPLORE)

// Daten
const items = ref([])                   // Originaldaten vom Backend
const loading = ref(true)
const error = ref(null)

// UI-State
const expandedId = ref(null)            // welche Zeile ist aufgeklappt
const draftById = ref({})               // { [id]: { ...draft } } – Kopie für Edit
const savingId = ref(null)              // zeigt "Speichern läuft" pro Zeile an

// Hilfs-Optionen
const contexts = ['input', 'expert', 'protected', 'finale']
const types = ['integer', 'decimal', 'time', 'date', 'boolean']
// Annahme: 1 = CHALLENGE, 2 = EXPLORE
const programs = [
  { value: 1, label: 'CHALLENGE' },
  { value: 2, label: 'EXPLORE' },
]

// Backend laden
async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await axios.get('/params')
    // Erwartete Form: Array von Parametern
    // { id, name, ui_label, ui_description, context, level, type, value, min, max, step, first_program, sequence }
    items.value = Array.isArray(data) ? data : (data?.items ?? [])
    // Nach sequence sortieren (failsafe)
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

  if (filterContext.value !== 'all') {
    list = list.filter(i => i.context === filterContext.value)
  }
  if (filterLevel.value !== 'all') {
    list = list.filter(i => String(i.level) === String(filterLevel.value))
  }
  if (filterProgram.value !== 'all') {
    list = list.filter(i => String(i.first_program) === String(filterProgram.value))
  }

  // immer nach sequence
  list.sort((a,b) => (a.sequence ?? 0) - (b.sequence ?? 0))
  return list
})

// Levels dynamisch aus Daten (für Filter)
const levelOptions = computed(() => {
  const set = new Set(items.value.map(i => i.level))
  return Array.from(set).sort((a,b) => a - b)
})

// Aufklappen / Draft füllen
function toggleExpand(item) {
  if (expandedId.value === item.id) {
    expandedId.value = null
    return
  }
  expandedId.value = item.id
  // Draft anlegen
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

// Änderungen verwerfen → Draft aus Original wiederherstellen
function discard(item) {
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

// Speichern (PUT /params/{id})
async function save(item) {
  const draft = draftById.value[item.id]
  if (!draft) return
  savingId.value = item.id
  try {
    const payload = { ...draft }
    await axios.put(`/params/${item.id}`, payload)
    // In Originalliste zurückschreiben
    Object.assign(item, payload)
  } catch (e) {
    console.error('Update fehlgeschlagen', e)
    alert('Speichern fehlgeschlagen.')
  } finally {
    savingId.value = null
  }
}

// Drag&Drop – gleiche Technik wie im Beispiel
async function onSort() {
  // neue Reihenfolge speichern: sequence = Index + 1
  const payload = filtered.value.map((p, idx) => ({
    id: p.id,
    sequence: idx + 1,
  }))

  // Wir müssen auch die items.value (Masterliste) aktualisieren,
  // damit Filterwechsel nicht die neue Reihenfolge verliert.
  // Map neue Reihenfolge in items.value:
  const mapSeq = new Map(payload.map(x => [x.id, x.sequence]))
  items.value = items.value
    .map(it => ({ ...it, sequence: mapSeq.get(it.id) ?? it.sequence }))
    .sort((a,b) => (a.sequence ?? 0) - (b.sequence ?? 0))

  try {
    await axios.post('/params/reorder', { order: payload })
  } catch (e) {
    console.error('Reihenfolge speichern fehlgeschlagen', e)
    alert('Reihenfolge konnte nicht gespeichert werden.')
    // Optional: reload() um Serverzustand wiederherzustellen
    // await load()
  }
}
</script>

<template>
  <div class="space-y-4">
    <!-- Filter -->
    <div class="flex flex-wrap gap-4 items-end">
      <div>
        <div class="text-xs text-gray-500 mb-1">Context</div>
        <select v-model="filterContext" class="border rounded px-2 py-1">
          <option value="all">Alle</option>
          <option v-for="c in contexts" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>

      <div>
        <div class="text-xs text-gray-500 mb-1">Level</div>
        <select v-model="filterLevel" class="border rounded px-2 py-1">
          <option value="all">Alle</option>
          <option v-for="lvl in levelOptions" :key="lvl" :value="lvl">{{ lvl }}</option>
        </select>
      </div>

      <div>
        <div class="text-xs text-gray-500 mb-1">Programm</div>
        <select v-model="filterProgram" class="border rounded px-2 py-1">
          <option value="all">Alle</option>
          <option v-for="p in programs" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
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
          v-model="items"   <!-- wichtig: wir sortieren die Masterliste; Filter greifen über computed -->
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

                <div class="flex-1 min-w-0">
                  <div class="font-medium truncate">
                    {{ item.name || '(ohne Name)' }}
                  </div>
                  <div class="text-xs text-gray-500 truncate">
                    {{ item.ui_label || '—' }} · {{ item.context }} · L{{ item.level }} · {{ item.type }} · {{ item.first_program || '—' }}
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
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <!-- Textfelder -->
                  <div>
                    <label class="block text-xs text-gray-500 mb-1">Name</label>
                    <input v-model="draftById[item.id].name" class="w-full border rounded px-2 py-1" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-500 mb-1">UI Label</label>
                    <input v-model="draftById[item.id].ui_label" class="w-full border rounded px-2 py-1" />
                  </div>
                  <div class="md:col-span-2">
                    <label class="block text-xs text-gray-500 mb-1">UI Beschreibung</label>
                    <textarea v-model="draftById[item.id].ui_description" rows="2" class="w-full border rounded px-2 py-1"></textarea>
                  </div>

                  <!-- Radios: context -->
                  <div>
                    <div class="text-xs text-gray-500 mb-1">Context</div>
                    <div class="flex flex-wrap gap-3">
                      <label v-for="c in contexts" :key="c" class="text-sm">
                        <input type="radio" :value="c" v-model="draftById[item.id].context" class="mr-1" />
                        {{ c }}
                      </label>
                    </div>
                  </div>

                  <!-- Radios: type -->
                  <div>
                    <div class="text-xs text-gray-500 mb-1">Type</div>
                    <div class="flex flex-wrap gap-3">
                      <label v-for="t in types" :key="t" class="text-sm">
                        <input type="radio" :value="t" v-model="draftById[item.id].type" class="mr-1" />
                        {{ t }}
                      </label>
                    </div>
                  </div>

                  <!-- Numbers / Werte -->
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

                  <!-- Level -->
                  <div>
                    <label class="block text-xs text-gray-500 mb-1">Level</label>
                    <input type="number" v-model.number="draftById[item.id].level" class="w-full border rounded px-2 py-1" />
                  </div>

                  <!-- Radios: first_program -->
                  <div>
                    <div class="text-xs text-gray-500 mb-1">Programm</div>
                    <div class="flex flex-wrap gap-3">
                      <label v-for="p in programs" :key="p.value" class="text-sm">
                        <input
                          type="radio"
                          :value="p.value"
                          v-model="draftById[item.id].first_program"
                          class="mr-1"
                        />
                        {{ p.label }}
                      </label>
                      <label class="text-sm">
                        <input
                          type="radio"
                          :value="null"
                          v-model="draftById[item.id].first_program"
                          class="mr-1"
                        />
                        (keins)
                      </label>
                    </div>
                  </div>
                </div>

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