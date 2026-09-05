<template>
  <div class="main-tables-admin">
    <div class="main-tables-admin__header">
      <h2 class="text-2xl font-bold text-[var(--color-text)]">alle m-Tabellen</h2>
      <button
        type="button"
        @click="createGitHubPR"
        :disabled="loading || creatingPR"
        class="glass-btn-accent !px-4 !py-2 !text-sm disabled:opacity-50"
      >
        {{ creatingPR ? 'PR wird erstellt…' : 'GitHub PR erstellen' }}
      </button>
    </div>

    <div class="main-tables-admin__body">
      <aside class="main-tables-admin__nav glass-card liquid-surface-inner !p-2">
        <div
          v-for="table in availableTables"
          :key="table.name"
          class="main-tables-admin__nav-item"
          :class="{ 'main-tables-admin__nav-item--active': selectedTable === table.name }"
        >
          <button
            type="button"
            class="main-tables-admin__nav-select"
            @click="selectTable(table.name)"
          >
            <span class="main-tables-admin__nav-label font-mono">{{ table.name }}</span>
          </button>
          <RouterLink
            v-if="specialEditorsByTable[table.name]"
            :to="specialEditorsByTable[table.name].path"
            class="main-tables-admin__jump"
            :title="`Öffne ${specialEditorsByTable[table.name].label}`"
            @click.stop
          >
            <i :class="specialEditorsByTable[table.name].icon" aria-hidden="true"/>
            <span class="sr-only">{{ specialEditorsByTable[table.name].label }}</span>
          </RouterLink>
          <span class="glass-chip !px-2 !py-0.5 !text-xs shrink-0">{{ table.recordCount }}</span>
        </div>
        <p v-if="!availableTables.length && !loadingTables" class="text-sm text-[var(--color-text-subtle)] px-2 py-1">
          Keine m_-Tabellen gefunden.
        </p>
      </aside>

      <section class="main-tables-admin__content min-w-0">
        <div v-if="!selectedTable" class="main-tables-admin__panel glass-card liquid-surface-inner flex items-center justify-center">
          <p class="text-[var(--color-text-subtle)]">Tabelle links auswählen</p>
        </div>

        <!-- Form replaces main pane -->
        <div v-else-if="viewMode === 'form'" class="main-tables-admin__panel glass-card liquid-surface-inner">
          <div class="main-tables-admin__panel-toolbar">
            <div class="flex items-center gap-3 min-w-0">
              <button type="button" class="glass-btn-secondary !px-3 !py-1.5 !text-sm" @click="backToList">
                ← Zur Liste
              </button>
              <h3 class="text-lg font-medium text-[var(--color-text)] !mb-0 truncate">
                {{ formIsCreate ? 'Neu' : 'Bearbeiten' }} — {{ selectedTable }}
              </h3>
            </div>
            <button
              type="button"
              class="glass-btn-accent !px-3 !py-2 !text-sm disabled:opacity-50"
              :disabled="saving"
              @click="saveForm"
            >
              {{ saving ? 'Speichern…' : 'Speichern' }}
            </button>
          </div>

          <div class="main-tables-admin__form-scroll">
            <div v-if="schemaLoading" class="p-6 text-[var(--color-text-subtle)]">Schema wird geladen…</div>
            <form v-else class="main-tables-admin__form" @submit.prevent="saveForm">
              <div
                v-for="col in formColumns"
                :key="col.name"
                class="main-tables-admin__field"
              >
                <label class="main-tables-admin__label" :for="`field-${col.name}`">
                  {{ col.name }}
                  <span v-if="!col.nullable" class="text-red-600">*</span>
                </label>
                <p class="main-tables-admin__restriction">{{ col.restriction }}</p>

                <!-- FK select -->
                <select
                  v-if="foreignKeys[col.name]"
                  :id="`field-${col.name}`"
                  v-model="formData[col.name]"
                  class="main-tables-admin__input"
                  :disabled="!col.writable || (formIsCreate === false && col.name === primaryKey)"
                >
                  <option v-if="col.nullable" :value="null">— null —</option>
                  <option
                    v-for="opt in foreignKeys[col.name].options"
                    :key="String(opt.id)"
                    :value="opt.id"
                  >
                    {{ opt.label }}
                  </option>
                </select>

                <!-- Enum -->
                <select
                  v-else-if="col.is_enum && col.enum_values"
                  :id="`field-${col.name}`"
                  v-model="formData[col.name]"
                  class="main-tables-admin__input"
                  :disabled="!col.writable || (!formIsCreate && col.name === primaryKey)"
                >
                  <option v-if="col.nullable" :value="null">— null —</option>
                  <option v-for="v in col.enum_values" :key="v" :value="v">{{ v }}</option>
                </select>

                <!-- Set multi -->
                <select
                  v-else-if="col.is_set && col.enum_values"
                  :id="`field-${col.name}`"
                  v-model="formData[col.name]"
                  class="main-tables-admin__input"
                  multiple
                  :disabled="!col.writable"
                >
                  <option v-for="v in col.enum_values" :key="v" :value="v">{{ v }}</option>
                </select>

                <!-- Booleanish -->
                <select
                  v-else-if="col.is_booleanish"
                  :id="`field-${col.name}`"
                  v-model="formData[col.name]"
                  class="main-tables-admin__input"
                  :disabled="!col.writable"
                >
                  <option v-if="col.nullable" :value="null">Unset</option>
                  <option :value="0">0</option>
                  <option :value="1">1</option>
                </select>

                <!-- Textarea -->
                <textarea
                  v-else-if="isTextarea(col)"
                  :id="`field-${col.name}`"
                  v-model="formData[col.name]"
                  class="main-tables-admin__input main-tables-admin__textarea"
                  rows="3"
                  :disabled="!col.writable || (!formIsCreate && col.name === primaryKey)"
                />

                <!-- Number / date / text -->
                <input
                  v-else
                  :id="`field-${col.name}`"
                  v-model="formData[col.name]"
                  class="main-tables-admin__input"
                  :type="inputTypeFor(col)"
                  :disabled="!col.writable || (col.auto_increment && formIsCreate) || (!formIsCreate && col.name === primaryKey)"
                  :maxlength="col.max_length || undefined"
                />
              </div>
            </form>
          </div>
        </div>

        <!-- Generic list -->
        <div v-else class="main-tables-admin__panel glass-card liquid-surface-inner">
          <div class="main-tables-admin__panel-toolbar">
            <h3 class="text-lg font-medium text-[var(--color-text)] !mb-0">
              {{ selectedTable }} —
              {{ filteredTableData.length }}{{ listFilter.trim() ? ` / ${tableData.length}` : '' }} Datensätze
            </h3>
            <div class="flex items-center gap-2 shrink-0 flex-wrap">
              <input
                v-model="listFilter"
                type="search"
                class="main-tables-admin__filter"
                placeholder="Filter…"
                aria-label="Tabelle filtern"
              />
              <button
                type="button"
                class="glass-btn-accent !px-3 !py-2 !text-sm inline-flex items-center gap-2"
                :disabled="loading || schemaLoading"
                @click="startCreate"
              >
                <i class="bi bi-plus-lg" aria-hidden="true"/>
                Neu
              </button>
            </div>
          </div>

          <div v-if="loading" class="p-6 text-[var(--color-text-subtle)]">Laden…</div>
          <div v-else class="main-tables-admin__table-scroll">
            <table class="min-w-full divide-y divide-[var(--color-border)]">
              <thead>
                <tr>
                  <th class="main-tables-admin__th">Aktionen</th>
                  <th
                    v-for="column in tableColumns"
                    :key="column"
                    class="main-tables-admin__th"
                  >
                    {{ column }}
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[var(--color-border)]">
                <tr v-if="!filteredTableData.length">
                  <td
                    class="px-6 py-8 text-center text-[var(--color-text-subtle)]"
                    :colspan="tableColumns.length + 1"
                  >
                    {{ tableData.length ? 'Kein Treffer' : 'Keine Datensätze' }}
                  </td>
                </tr>
                <tr v-for="record in filteredTableData" :key="String(record[primaryKey])">
                  <td class="px-4 py-3 whitespace-nowrap text-sm">
                    <div class="flex items-center gap-2">
                      <button
                        type="button"
                        class="text-[var(--color-accent)] hover:underline"
                        @click="startEdit(record)"
                      >
                        Bearbeiten
                      </button>
                      <button
                        v-if="record.can_delete"
                        type="button"
                        class="text-red-600 hover:underline"
                        @click="askDelete(record)"
                      >
                        Löschen
                      </button>
                      <span
                        v-else
                        class="inline-flex items-center gap-1 text-[var(--color-text-subtle)]"
                        :title="blockerTitle(record)"
                      >
                        <i class="bi bi-lock-fill" aria-hidden="true"/>
                        gesperrt
                      </span>
                    </div>
                  </td>
                  <td
                    v-for="column in tableColumns"
                    :key="column"
                    class="px-4 py-3 text-sm text-[var(--color-text)] max-w-[16rem] truncate"
                    :title="String(displayCell(record, column) ?? '')"
                  >
                    {{ displayCell(record, column) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

    <ConfirmationModal
      :show="!!recordToDelete"
      type="danger"
      title="Datensatz löschen?"
      :message="deleteRecordMessage"
      confirm-text="Löschen"
      cancel-text="Abbrechen"
      @confirm="confirmDelete"
      @cancel="recordToDelete = null"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import axios from 'axios'
import ConfirmationModal from './ConfirmationModal.vue'
import { showGlassToast } from '@/composables/useGlassToast'
import { specialEditorForTable } from '@/constants/adminNav'

const selectedTable = ref('')
const availableTables = ref([])
const tableData = ref([])
const tableColumns = ref([])
const schema = ref(null)
const foreignKeys = ref({})
const primaryKey = ref('id')
const loading = ref(false)
const loadingTables = ref(false)
const schemaLoading = ref(false)
const saving = ref(false)
const creatingPR = ref(false)
const viewMode = ref('list') // list | form
const formIsCreate = ref(true)
const formData = ref({})
const recordToDelete = ref(null)
const listFilter = ref('')

const formColumns = computed(() => {
  const cols = schema.value?.columns || []
  if (formIsCreate.value) {
    return cols.filter((c) => !c.auto_increment)
  }
  return cols
})

const filteredTableData = computed(() => {
  const q = listFilter.value.trim().toLowerCase()
  if (!q) return tableData.value
  return tableData.value.filter((record) =>
    tableColumns.value.some((column) =>
      String(displayCell(record, column) ?? '').toLowerCase().includes(q),
    ),
  )
})

const deleteRecordMessage = computed(() => {
  const r = recordToDelete.value
  if (!r) return ''
  const parts = [`Datensatz ${primaryKey.value}=${r[primaryKey.value]} wirklich löschen?`]
  const impact = r.cascade_impact || []
  if (impact.length) {
    const detail = impact
      .map((b) => `${b.table}.${b.column}: ${b.count} (${b.delete_rule})`)
      .join('; ')
    parts.push(`Auswirkungen: ${detail}`)
  }
  return parts.join(' ')
})

function apiError(error, fallback) {
  return error.response?.data?.error
    || error.response?.data?.message
    || error.message
    || fallback
}

async function loadTables() {
  loadingTables.value = true
  try {
    const { data } = await axios.get('/admin/main-tables/')
    availableTables.value = (data.tables || []).map((t) => ({
      name: t.name,
      recordCount: t.count ?? 0,
    }))
  } catch (error) {
    showGlassToast(apiError(error, 'Tabellenliste fehlgeschlagen'), 'error')
    availableTables.value = []
  } finally {
    loadingTables.value = false
  }
}

const specialEditorsByTable = computed(() => {
  const map = {}
  for (const table of availableTables.value) {
    const editor = specialEditorForTable(table.name)
    if (editor) map[table.name] = editor
  }
  return map
})

async function selectTable(tableName) {
  selectedTable.value = tableName
  viewMode.value = 'list'
  formData.value = {}
  listFilter.value = ''
  recordToDelete.value = null
  await Promise.all([loadSchema(tableName), loadTableData(tableName)])
}

async function loadSchema(tableName) {
  schemaLoading.value = true
  try {
    const { data } = await axios.get(`/admin/main-tables/${tableName}/schema`)
    schema.value = data
    foreignKeys.value = data.foreign_keys || {}
    primaryKey.value = data.primary_key || 'id'
    tableColumns.value = (data.columns || []).map((c) => c.name)
  } catch (error) {
    schema.value = null
    foreignKeys.value = {}
    showGlassToast(apiError(error, 'Schema laden fehlgeschlagen'), 'error')
  } finally {
    schemaLoading.value = false
  }
}

async function loadTableData(tableName) {
  loading.value = true
  try {
    const { data } = await axios.get(`/admin/main-tables/${tableName}`)
    tableData.value = data.data || []
    primaryKey.value = data.primary_key || primaryKey.value
  } catch (error) {
    tableData.value = []
    showGlassToast(apiError(error, 'Daten laden fehlgeschlagen'), 'error')
  } finally {
    loading.value = false
  }
}

function displayCell(record, column) {
  const raw = record[column]
  if (raw === null || raw === undefined) return 'null'
  const fk = foreignKeys.value[column]
  if (fk?.options?.length) {
    const opt = fk.options.find((o) => String(o.id) === String(raw))
    return opt ? opt.label : `#${raw}`
  }
  return raw
}

function blockerTitle(record) {
  const blockers = record.blockers || []
  if (!blockers.length) return 'Löschen gesperrt'
  return blockers.map((b) => `${b.table}.${b.column}: ${b.count}`).join('\n')
}

function isTextarea(col) {
  const t = String(col.sql_type || '').toLowerCase()
  if (t.includes('text')) return true
  if (col.max_length && col.max_length > 255) return true
  return false
}

function inputTypeFor(col) {
  const t = String(col.sql_type || '').toLowerCase()
  if (t.startsWith('date') && !t.includes('time') && !t.includes('datetime')) return 'date'
  if (t.includes('datetime') || t.includes('timestamp')) return 'datetime-local'
  if (t === 'time' || t.startsWith('time(')) return 'time'
  if (t.includes('int') || t.includes('decimal') || t.includes('float') || t.includes('double')) return 'number'
  return 'text'
}

function defaultForColumn(col) {
  if (col.default !== null && col.default !== undefined) {
    if (col.is_booleanish) {
      return Number(col.default)
    }
    return col.default
  }
  if (col.nullable) return null
  if (col.is_booleanish) return 0
  if (col.is_set) return []
  return ''
}

function startCreate() {
  if (!schema.value) return
  formIsCreate.value = true
  const data = {}
  for (const col of schema.value.columns) {
    if (col.auto_increment) continue
    if (col.is_set) {
      data[col.name] = []
    } else {
      data[col.name] = defaultForColumn(col)
    }
  }
  formData.value = data
  viewMode.value = 'form'
}

function startEdit(record) {
  if (!schema.value) return
  formIsCreate.value = false
  const data = {}
  for (const col of schema.value.columns) {
    let value = record[col.name]
    if (col.is_set) {
      value = value ? String(value).split(',').filter(Boolean) : []
    } else if (col.is_booleanish && value !== null && value !== undefined) {
      value = Number(value)
    }
    data[col.name] = value === undefined ? null : value
  }
  formData.value = data
  viewMode.value = 'form'
}

function backToList() {
  viewMode.value = 'list'
  formData.value = {}
}

async function saveForm() {
  if (!selectedTable.value || saving.value) return
  saving.value = true
  try {
    const payload = { ...formData.value }
    // Convert empty strings on nullable handled by API; ensure set arrays stay arrays
    if (formIsCreate.value) {
      await axios.post(`/admin/main-tables/${selectedTable.value}`, payload)
    } else {
      const id = payload[primaryKey.value]
      await axios.put(`/admin/main-tables/${selectedTable.value}/${id}`, payload)
    }
    showGlassToast('Gespeichert', 'success')
    viewMode.value = 'list'
    await Promise.all([loadTableData(selectedTable.value), loadTables()])
  } catch (error) {
    showGlassToast(apiError(error, 'Speichern fehlgeschlagen'), 'error')
  } finally {
    saving.value = false
  }
}

function askDelete(record) {
  recordToDelete.value = record
}

async function confirmDelete() {
  const record = recordToDelete.value
  if (!record || !selectedTable.value) return
  const id = record[primaryKey.value]
  try {
    await axios.delete(`/admin/main-tables/${selectedTable.value}/${id}`)
    showGlassToast('Gelöscht', 'success')
    recordToDelete.value = null
    await Promise.all([loadTableData(selectedTable.value), loadTables()])
  } catch (error) {
    showGlassToast(apiError(error, 'Löschen fehlgeschlagen'), 'error')
    recordToDelete.value = null
    if (error.response?.status === 409) {
      await loadTableData(selectedTable.value)
    }
  }
}

async function createGitHubPR() {
  creatingPR.value = true
  try {
    const response = await axios.post('/admin/main-tables/create-pr')
    if (response.data.success) {
      showGlassToast(response.data.message || 'GitHub PR erstellt', 'success')
    } else {
      showGlassToast(response.data.error || response.data.message || 'PR fehlgeschlagen', 'error')
    }
    if (response.data.output) {
      console.log('PR Creation Output:', response.data.output)
    }
  } catch (error) {
    showGlassToast(apiError(error, 'PR fehlgeschlagen'), 'error')
  } finally {
    creatingPR.value = false
  }
}

onMounted(() => {
  loadTables()
})
</script>

<style scoped>
.main-tables-admin {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 100%;
  height: 100%;
  min-height: 0;
  overflow: hidden;
}

.main-tables-admin__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  flex-shrink: 0;
}

.main-tables-admin__body {
  display: grid;
  grid-template-columns: minmax(14rem, 17rem) minmax(0, 1fr);
  gap: 1rem;
  align-items: stretch;
  min-height: 0;
  flex: 1 1 auto;
  overflow: hidden;
}

.main-tables-admin__nav {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-height: 0;
  height: 100%;
  overflow-x: hidden;
  overflow-y: auto;
}

.main-tables-admin__nav-item {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  width: 100%;
  text-align: left;
  padding: 0.35rem 0.45rem 0.35rem 0.35rem;
  border-radius: var(--radius);
  border: 1px solid transparent;
  background: transparent;
  color: var(--color-text);
  transition: background 0.15s ease, border-color 0.15s ease;
}

.main-tables-admin__nav-select {
  display: flex;
  align-items: center;
  min-width: 0;
  flex: 1 1 auto;
  text-align: left;
  background: transparent;
  border: 0;
  padding: 0.2rem 0.35rem;
  color: inherit;
  cursor: pointer;
}

.main-tables-admin__nav-item:hover {
  background: var(--color-bg-hover);
}

.main-tables-admin__nav-item--active {
  border-color: color-mix(in srgb, var(--color-accent) 35%, var(--color-border));
  background: color-mix(in srgb, var(--color-accent) 10%, #fff);
}

.main-tables-admin__nav-label {
  font-size: 0.8125rem;
  font-weight: 600;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.main-tables-admin__jump {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 1.6rem;
  height: 1.6rem;
  border-radius: var(--radius);
  color: var(--color-text-muted);
}

.main-tables-admin__jump:hover {
  background: color-mix(in srgb, var(--color-accent) 16%, transparent);
  color: var(--color-accent);
}

.main-tables-admin__content {
  min-width: 0;
  min-height: 0;
  height: 100%;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.main-tables-admin__panel {
  display: flex;
  flex-direction: column;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  padding: 0 !important;
  overflow: hidden;
}

.main-tables-admin__panel-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  flex-shrink: 0;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
}

.main-tables-admin__table-scroll,
.main-tables-admin__form-scroll {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
}

.main-tables-admin__form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.25rem;
  max-width: 40rem;
}

.main-tables-admin__field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.main-tables-admin__label {
  font-size: 0.875rem;
  font-weight: 650;
  color: var(--color-text);
}

.main-tables-admin__restriction {
  margin: 0;
  font-size: 0.72rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  color: var(--color-text-subtle);
  line-height: 1.35;
}

.main-tables-admin__input {
  width: 100%;
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  padding: 0.45rem 0.6rem;
  background: #fff;
  color: var(--color-text);
  font-size: 0.875rem;
}

.main-tables-admin__input:disabled {
  opacity: 0.6;
  background: var(--color-bg-muted, #f5f5f5);
}

.main-tables-admin__filter {
  width: min(16rem, 100%);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  padding: 0.4rem 0.6rem;
  background: #fff;
  color: var(--color-text);
  font-size: 0.875rem;
}

.main-tables-admin__textarea {
  resize: vertical;
  min-height: 4.5rem;
}

.main-tables-admin__th {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 0.75rem 1rem;
  text-align: left;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-subtle);
  background: color-mix(in srgb, #ffffff 92%, var(--color-bg-muted));
  border-bottom: 1px solid var(--color-border);
  box-shadow: 0 1px 0 var(--color-border);
}

@media (max-width: 900px) {
  .main-tables-admin__body {
    grid-template-columns: 1fr;
    grid-template-rows: minmax(10rem, 30%) minmax(0, 1fr);
  }
}
</style>
