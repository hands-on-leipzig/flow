<script setup lang="ts">
import {ref, watch, onMounted, computed} from 'vue'
import axios from 'axios'

type Header = { key: string; title: string }
type Cell = { render?: boolean; rowspan?: number; colspan?: number; text?: string }
type Row = {
  separator?: boolean
  timeIso?: string
  timeLabel?: string
  cells?: Record<string, Cell>
}


const props = withDefaults(defineProps<{
  planId: number
  initialView?: 'roles' | 'teams' | 'rooms'
  reload?: number             // optionaler „Tick“, bei Änderung neu laden
}>(), {
  initialView: 'roles',
})

const view = ref<'roles' | 'teams' | 'rooms'>(props.initialView)
const loading = ref(false)
const error = ref<string | null>(null)
const headers = ref<Header[]>([])
const rows = ref<Row[]>([])

const headerKeys = computed(() => headers.value.map(h => h.key))

async function load() {
  if (!props.planId) return
  loading.value = true
  error.value = null
  try {
    const { data } = await axios.get(`/api/public/plans/${props.planId}/schedule/${view.value}`)
    headers.value = Array.isArray(data?.headers) ? data.headers : []
    rows.value = Array.isArray(data?.rows) ? data.rows : []
  } catch (e: any) {
    error.value = e?.message || 'Fehler beim Laden'
    headers.value = []
    rows.value = []
  } finally {
    loading.value = false
  }
}

watch(() => props.planId, () => load())
watch(view, () => load())
watch(() => props.reload, () => load())

onMounted(load)

function setView(v: 'roles'|'teams'|'rooms') {
  if (view.value !== v) view.value = v
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <!-- Kopf -->
    <div class="flex items-center justify-between">
      <h2 class="text-xl font-normal tracking-tight">Plan {{ props.planId }} – {{ view === 'roles' ? 'Rollen' : view === 'teams' ? 'Teams' : 'Räume' }}</h2>
      <div class="inline-flex rounded-md shadow-sm overflow-hidden border border-gray-300">
        <button
          class="px-3 py-1.5 text-sm"
          :class="view==='roles' ? 'bg-gray-900 text-white' : 'bg-white text-gray-900 hover:bg-gray-100'"
          @click="setView('roles')">Rollen</button>
        <button
          class="px-3 py-1.5 text-sm border-l border-gray-300"
          :class="view==='teams' ? 'bg-gray-900 text-white' : 'bg-white text-gray-900 hover:bg-gray-100'"
          @click="setView('teams')">Teams</button>
        <button
          class="px-3 py-1.5 text-sm border-l border-gray-300"
          :class="view==='rooms' ? 'bg-gray-900 text-white' : 'bg-white text-gray-900 hover:bg-gray-100'"
          @click="setView('rooms')">Räume</button>
      </div>
    </div>

    <!-- Hinweis -->
    <p class="text-xs text-gray-500">
      Freie Blöcke werden hier nicht angezeigt, weil sie den Ablauf nicht beeinflussen.
    </p>

    <!-- Fehlermeldung -->
    <div v-if="error" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-md p-2">
      {{ error }}
    </div>

    <!-- Tabelle -->
    <div class="overflow-auto rounded-md border border-gray-200 bg-white">
      <table class="w-full table-fixed text-sm">
        <thead class="sticky top-0 bg-gray-50">
          <tr>
            <th v-for="h in headers" :key="h.key"
                class="text-left font-normal px-2 py-2 border-b border-gray-200">
              {{ h.title }}
            </th>
          </tr>
        </thead>

        <tbody>
          <!-- Laden -->
          <tr v-if="loading">
            <td :colspan="headers.length" class="px-3 py-8 text-center text-gray-500">
              Lädt …
            </td>
          </tr>

          <!-- Inhalt -->
          <template v-else>
            <template v-for="(r, ridx) in rows" :key="ridx">
              <!-- Separator (Tageswechsel / Abschlusspuffer) -->
              <tr v-if="r.separator" class="bg-white">
                <td :colspan="headers.length" class="h-3 p-0"></td>
              </tr>

              <tr v-else class="odd:bg-gray-50 even:bg-gray-100">
                <!-- Zeitspalte (erste Spalte in headers) -->
                <td v-if="headerKeys[0]==='time'" class="align-top px-2 py-2 whitespace-pre-line">
                  <template v-if="r.timeLabel">
                    <!-- Erwartet "dd.mm. HH:MM" -->
                    <span class="block">
                      {{ (r.timeLabel || '').split(' ')[0] }}
                    </span>
                    <span class="block">
                      {{ (r.timeLabel || '').split(' ')[1] || '' }}
                    </span>
                  </template>
                </td>

                <!-- Restliche Spalten -->
                <template v-for="(h, cidx) in headers.slice(1)" :key="h.key + '-' + ridx">
                  <template v-if="r.cells && r.cells[h.key]">
                    <td v-if="r.cells[h.key].render !== false"
                        class="align-top px-2 py-2 whitespace-pre-line"
                        :rowspan="r.cells[h.key].rowspan || 1"
                        :colspan="r.cells[h.key].colspan || 1"
                        :class="(r.cells[h.key].text && r.cells[h.key].text!.trim() !== '') ? 'bg-white' : ''">
                      {{ r.cells[h.key].text || '' }}
                    </td>
                    <!-- wenn render === false: nichts rendern (Rowspan wirkt) -->
                  </template>
                  <td v-else class="align-top px-2 py-2"></td>
                </template>
              </tr>
            </template>

            <!-- Keine Daten -->
            <tr v-if="rows.length === 0 && !loading">
              <td :colspan="headers.length" class="px-3 py-6 text-center text-gray-500">
                Keine Aktivitäten im Zeitraum.
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
/* alle Spalten gleich breit */
table { table-layout: fixed; }
/* kein fett im Header */
th { font-weight: 400; }
/* Inhalte dürfen Zeilenumbrüche enthalten */
td { white-space: pre-line; }
/* Zeitspalte genau wie Zellen (kein Bold) */
</style>