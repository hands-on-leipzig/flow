<template>
  <div class="main-tables-admin">
    <div class="main-tables-admin__header">
      <h2 class="text-2xl font-bold text-[var(--color-text)]">m-Tabellen-Verwaltung</h2>
      <button
        type="button"
        @click="createGitHubPR"
        :disabled="loading || creatingPR"
        class="glass-btn-accent !px-4 !py-2 !text-sm disabled:opacity-50"
      >
        {{ creatingPR ? 'PR wird erstellt...' : 'm-Tabellen exportieren' }}
      </button>
    </div>

    <div class="main-tables-admin__body">
      <aside class="main-tables-admin__nav glass-card liquid-surface-inner !p-2">
        <button
          v-for="table in availableTables"
          :key="table.name"
          type="button"
          class="main-tables-admin__nav-item"
          :class="{ 'main-tables-admin__nav-item--active': selectedTable === table.name }"
          @click="selectTable(table.name)"
        >
          <span class="main-tables-admin__nav-label">{{ table.displayName }}</span>
          <span class="glass-chip !px-2 !py-0.5 !text-xs shrink-0">{{ table.recordCount }}</span>
        </button>
      </aside>

      <section class="main-tables-admin__content min-w-0">
        <!-- Special UI for m_parameter table -->
        <div v-if="selectedTable === 'm_parameter'" class="glass-card liquid-surface-inner overflow-hidden">
          <div class="px-4 py-5 sm:p-6">
            <h3 class="text-lg font-medium text-[var(--color-text)] mb-4">
              {{ getTableDisplayName(selectedTable) }} - Erweiterter Editor
            </h3>
            <MParameter />
          </div>
        </div>

        <!-- Special UI for m_visibility table -->
        <div v-else-if="selectedTable === 'm_visibility'" class="glass-card liquid-surface-inner overflow-hidden">
          <div class="px-4 py-5 sm:p-6">
            <h3 class="text-lg font-medium text-[var(--color-text)] mb-4">
              {{ getTableDisplayName(selectedTable) }} - Erweiterter Editor
            </h3>
            <Visibility />
          </div>
        </div>

        <!-- Generic Table Content for other tables -->
        <div v-else-if="selectedTable && tableData.length > 0" class="glass-card liquid-surface-inner overflow-hidden">
          <div class="px-4 py-5 sm:p-6">
            <h3 class="text-lg font-medium text-[var(--color-text)] mb-4">
              {{ getTableDisplayName(selectedTable) }} - {{ tableData.length }} Datensätze
            </h3>

            <div class="mb-4">
              <button
                type="button"
                @click="addNewRecord"
                class="glass-btn-accent !px-3 !py-2 !text-sm inline-flex items-center gap-2"
              >
                <i class="bi bi-plus-lg" aria-hidden="true"/>
                Neuen Datensatz hinzufügen
              </button>
            </div>

            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-[var(--color-border)]">
                <thead class="bg-[color-mix(in_srgb,var(--color-bg-muted)_70%,transparent)]">
                  <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-subtle)] uppercase tracking-wider">
                      Aktionen
                    </th>
                    <th
                      v-for="column in tableColumns"
                      :key="column"
                      class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-subtle)] uppercase tracking-wider"
                    >
                      {{ column }}
                    </th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border)]">
                  <tr v-for="(record, index) in tableData" :key="record.id || index">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                      <div v-if="editingRecord === index" class="flex space-x-2">
                        <button
                          type="button"
                          @click="saveRecord(index)"
                          class="text-green-600 hover:text-green-900"
                        >
                          Speichern
                        </button>
                        <button
                          type="button"
                          @click="cancelEdit"
                          class="text-[var(--color-text-muted)] hover:text-[var(--color-text)]"
                        >
                          Abbrechen
                        </button>
                      </div>
                      <div v-else class="flex space-x-2">
                        <button
                          type="button"
                          @click="editRecord(index)"
                          class="text-[var(--color-accent)] hover:opacity-80"
                        >
                          Bearbeiten
                        </button>
                        <button
                          type="button"
                          @click="confirmDeleteRecord(index)"
                          class="text-red-600 hover:text-red-900"
                        >
                          Löschen
                        </button>
                      </div>
                    </td>
                    <td
                      v-for="column in tableColumns"
                      :key="column"
                      class="px-6 py-4 whitespace-nowrap text-sm text-[var(--color-text)]"
                    >
                      <select
                        v-if="editingRecord === index && column === 'presence'"
                        v-model="editingData[column]"
                        class="glass-input liquid-surface-control !px-3 !py-2 w-full"
                      >
                        <option value="punctual">punctual — pünktlich da</option>
                        <option value="window">window — Zeitfenster / Rahmen</option>
                        <option value="info">info — Kontext / optional</option>
                      </select>
                      <input
                        v-else-if="editingRecord === index"
                        v-model="editingData[column]"
                        :type="getInputType(column)"
                        class="glass-input liquid-surface-control !px-3 !py-2 w-full"
                      />
                      <span v-else>{{ record[column] || '-' }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div
          v-else-if="selectedTable && selectedTable !== 'm_parameter' && selectedTable !== 'm_visibility' && tableData.length === 0"
          class="glass-card liquid-surface-inner text-center py-12"
        >
          <h3 class="mt-2 text-sm font-medium text-[var(--color-text)]">Keine Datensätze gefunden</h3>
          <p class="mt-1 text-sm text-[var(--color-text-subtle)]">Diese Tabelle ist leer.</p>
          <div class="mt-6">
            <button
              type="button"
              @click="addNewRecord"
              class="glass-btn-accent !px-4 !py-2 !text-sm inline-flex items-center gap-2"
            >
              <i class="bi bi-plus-lg" aria-hidden="true"/>
              Ersten Datensatz hinzufügen
            </button>
          </div>
        </div>

        <!-- Loading State -->
        <div
          v-else-if="loading && selectedTable !== 'm_parameter' && selectedTable !== 'm_visibility'"
          class="glass-card liquid-surface-inner text-center py-12"
        >
          <p class="text-sm text-[var(--color-text-subtle)]">Tabellendaten werden geladen...</p>
        </div>

        <div v-else-if="!selectedTable" class="glass-card liquid-surface-inner text-center py-12">
          <p class="text-sm text-[var(--color-text-subtle)]">Tabelle links auswählen.</p>
        </div>
      </section>
    </div>

    <ConfirmationModal
      :show="!!recordToDelete"
      title="Datensatz löschen"
      :message="deleteRecordMessage"
      type="danger"
      confirm-text="Löschen"
      cancel-text="Abbrechen"
      @confirm="deleteRecord"
      @cancel="cancelDeleteRecord"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import MParameter from './MParameter.vue'
import Visibility from './Visibility.vue'
import ConfirmationModal from './ConfirmationModal.vue'
import {showGlassToast} from '@/composables/useGlassToast'

const selectedTable = ref('')
const tableData = ref([])
const tableColumns = ref([])
const loading = ref(false)
const editingRecord = ref(null)
const editingData = ref({})
const creatingPR = ref(false)
const recordToDelete = ref(null)

const availableTables = ref([
  { name: 'm_activity_type', displayName: 'Activity Types', recordCount: 0 },
  { name: 'm_activity_type_detail', displayName: 'Activity Type Details', recordCount: 0 },
  { name: 'm_first_program', displayName: 'First Programs', recordCount: 0 },
  { name: 'm_level', displayName: 'Levels', recordCount: 0 },
  { name: 'm_parameter', displayName: 'Parameters', recordCount: 0 },
  { name: 'm_role', displayName: 'Roles', recordCount: 0 },
  { name: 'm_room_type', displayName: 'Room Types', recordCount: 0 },
  { name: 'm_room_type_group', displayName: 'Room Type Groups', recordCount: 0 },
  { name: 'm_season', displayName: 'Seasons', recordCount: 0 },
  { name: 'm_supported_plan', displayName: 'Supported Plans', recordCount: 0 },
  { name: 'm_visibility', displayName: 'Visibility Rules', recordCount: 0 },
])

const selectTable = (tableName) => {
  selectedTable.value = tableName
  loadTableData()
}

const loadTableData = async () => {
  if (!selectedTable.value || selectedTable.value === 'm_parameter' || selectedTable.value === 'm_visibility') return

  loading.value = true
  try {
    const response = await axios.get(`/admin/main-tables/${selectedTable.value}`)
    tableData.value = response.data.data || []

    if (tableData.value.length > 0) {
      tableColumns.value = Object.keys(tableData.value[0])
    } else {
      const columnsResponse = await axios.get(`/admin/main-tables/${selectedTable.value}/columns`)
      tableColumns.value = columnsResponse.data.columns || []
    }
  } catch (error) {
    console.error('Error loading table data:', error)
    tableData.value = []
    tableColumns.value = []
  } finally {
    loading.value = false
  }
}

const loadTableCounts = async () => {
  for (const table of availableTables.value) {
    try {
      const response = await axios.get(`/admin/main-tables/${table.name}/count`)
      table.recordCount = response.data.count || 0
    } catch (error) {
      console.error(`Error loading count for ${table.name}:`, error)
      table.recordCount = 0
    }
  }
}

const addNewRecord = () => {
  const newRecord = {}
  tableColumns.value.forEach((column) => {
    newRecord[column] = ''
  })
  tableData.value.push(newRecord)
  editingRecord.value = tableData.value.length - 1
  editingData.value = { ...newRecord }
}

const editRecord = (index) => {
  editingRecord.value = index
  editingData.value = { ...tableData.value[index] }
}

const cancelEdit = () => {
  editingRecord.value = null
  editingData.value = {}
}

const saveRecord = async (index) => {
  try {
    const record = tableData.value[index]
    const isNew = !record.id

    if (isNew) {
      const response = await axios.post(`/admin/main-tables/${selectedTable.value}`, editingData.value)
      tableData.value[index] = response.data.data
    } else {
      const response = await axios.put(`/admin/main-tables/${selectedTable.value}/${record.id}`, editingData.value)
      tableData.value[index] = response.data.data
    }

    editingRecord.value = null
    editingData.value = {}
  } catch (error) {
    console.error('Error saving record:', error)
    showGlassToast('Fehler beim Speichern des Datensatzes: ' + (error.response?.data?.message || error.message), 'error')
  }
}

const confirmDeleteRecord = (index) => {
  const record = tableData.value[index]
  recordToDelete.value = { index, id: record.id || null }
}

const cancelDeleteRecord = () => {
  recordToDelete.value = null
}

const deleteRecordMessage = computed(() => {
  return 'Datensatz wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.'
})

const deleteRecord = async () => {
  if (!recordToDelete.value) return

  try {
    const { index, id } = recordToDelete.value
    if (id) {
      await axios.delete(`/admin/main-tables/${selectedTable.value}/${id}`)
    }
    tableData.value.splice(index, 1)
    recordToDelete.value = null
  } catch (error) {
    console.error('Error deleting record:', error)
    showGlassToast('Fehler beim Löschen des Datensatzes: ' + (error.response?.data?.message || error.message), 'error')
    recordToDelete.value = null
  }
}

const createGitHubPR = async () => {
  creatingPR.value = true
  try {
    const response = await axios.post('/admin/main-tables/create-pr')

    const message = response.data.success
      ? `GitHub PR-Erstellung erfolgreich gestartet!\n\n${response.data.message}`
      : 'Fehler beim Erstellen des GitHub PR'
    showGlassToast(message, 'error')

    if (response.data.output) {
      console.log('PR Creation Output:', response.data.output)
    }
  } catch (error) {
    console.error('Error creating GitHub PR:', error)
    showGlassToast('Fehler beim Erstellen des GitHub PR: ' + (error.response?.data?.message || error.message), 'error')
  } finally {
    creatingPR.value = false
  }
}

const getTableDisplayName = (tableName) => {
  const table = availableTables.value.find((t) => t.name === tableName)
  return table ? table.displayName : tableName
}

const getInputType = (column) => {
  if (column.includes('id') || column.includes('sequence') || column.includes('year')) {
    return 'number'
  }
  if (column.includes('date') || column.includes('time')) {
    return 'datetime-local'
  }
  return 'text'
}

onMounted(() => {
  loadTableCounts()
})
</script>

<style scoped>
.main-tables-admin {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  max-width: 100%;
  min-height: 0;
}

.main-tables-admin__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.main-tables-admin__body {
  display: grid;
  grid-template-columns: minmax(14rem, 17rem) minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
  min-height: 0;
}

.main-tables-admin__nav {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  max-height: min(70vh, 40rem);
  overflow-y: auto;
  position: sticky;
  top: 0.5rem;
}

.main-tables-admin__nav-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  width: 100%;
  text-align: left;
  padding: 0.55rem 0.7rem;
  border-radius: var(--radius);
  border: 1px solid transparent;
  background: transparent;
  color: var(--color-text);
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.main-tables-admin__nav-item:hover {
  background: var(--color-bg-hover);
}

.main-tables-admin__nav-item--active {
  border-color: color-mix(in srgb, var(--color-accent) 35%, var(--color-border));
  background: color-mix(in srgb, var(--color-accent) 10%, #fff);
}

.main-tables-admin__nav-label {
  font-size: 0.875rem;
  font-weight: 600;
  line-height: 1.25;
}

.main-tables-admin__content {
  min-width: 0;
}

@media (max-width: 900px) {
  .main-tables-admin__body {
    grid-template-columns: 1fr;
  }

  .main-tables-admin__nav {
    position: static;
    max-height: 14rem;
  }
}
</style>
