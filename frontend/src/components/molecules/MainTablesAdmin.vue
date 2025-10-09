<template>
  <div class="main-tables-admin">
    <div class="mb-6">
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Main Tables Management</h2>
      
      <!-- Table Selector -->
      <div class="mb-4">
        <label for="table-selector" class="block text-sm font-medium text-gray-700 mb-2">
          Select Table to Edit:
        </label>
        <select
          id="table-selector"
          v-model="selectedTable"
          @change="loadTableData"
          class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
        >
          <option value="">Choose a table...</option>
          <option v-for="table in availableTables" :key="table.name" :value="table.name">
            {{ table.displayName }} ({{ table.recordCount }} records)
          </option>
        </select>
      </div>

      <!-- Export Button -->
      <div class="mb-4">
        <button
          @click="exportAllTables"
          :disabled="exporting"
          class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
        >
          <svg v-if="exporting" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <svg v-else class="-ml-1 mr-3 h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
          {{ exporting ? 'Exporting...' : 'Export All Tables' }}
        </button>

        <button
          @click="createGitHubPR"
          :disabled="loading || creatingPR"
          class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50"
        >
          <svg v-if="creatingPR" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <svg v-else class="-ml-1 mr-3 h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16l2.879-2.879m0 0a3 3 0 104.243-4.242 3 3 0 00-4.243 4.242zM21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          {{ creatingPR ? 'Creating PR...' : 'Create GitHub PR' }}
        </button>
      </div>
    </div>

    <!-- Special UI for m_parameter table -->
    <div v-if="selectedTable === 'm_parameter'" class="bg-white shadow overflow-hidden sm:rounded-md">
      <div class="px-4 py-5 sm:p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">
          {{ getTableDisplayName(selectedTable) }} - Advanced Editor
        </h3>
        <MParameter />
      </div>
    </div>

    <!-- Generic Table Content for other tables -->
    <div v-else-if="selectedTable && tableData.length > 0" class="bg-white shadow overflow-hidden sm:rounded-md">
      <div class="px-4 py-5 sm:p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">
          {{ getTableDisplayName(selectedTable) }} - {{ tableData.length }} records
        </h3>

        <!-- Add New Record Button -->
        <div class="mb-4">
          <button
            @click="addNewRecord"
            class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
          >
            <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Add New Record
          </button>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th
                  v-for="column in tableColumns"
                  :key="column"
                  class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                >
                  {{ column }}
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="(record, index) in tableData" :key="record.id || index">
                <td
                  v-for="column in tableColumns"
                  :key="column"
                  class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"
                >
                  <input
                    v-if="editingRecord === index"
                    v-model="editingData[column]"
                    :type="getInputType(column)"
                    class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  />
                  <span v-else>{{ record[column] || '-' }}</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                  <div v-if="editingRecord === index" class="flex space-x-2">
                    <button
                      @click="saveRecord(index)"
                      class="text-green-600 hover:text-green-900"
                    >
                      Save
                    </button>
                    <button
                      @click="cancelEdit"
                      class="text-gray-600 hover:text-gray-900"
                    >
                      Cancel
                    </button>
                  </div>
                  <div v-else class="flex space-x-2">
                    <button
                      @click="editRecord(index)"
                      class="text-blue-600 hover:text-blue-900"
                    >
                      Edit
                    </button>
                    <button
                      @click="deleteRecord(index)"
                      class="text-red-600 hover:text-red-900"
                    >
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="selectedTable && selectedTable !== 'm_parameter' && tableData.length === 0" class="text-center py-12">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
      </svg>
      <h3 class="mt-2 text-sm font-medium text-gray-900">No records found</h3>
      <p class="mt-1 text-sm text-gray-500">This table is empty.</p>
      <div class="mt-6">
        <button
          @click="addNewRecord"
          class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
        >
          <svg class="-ml-1 mr-2 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Add First Record
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-else-if="loading && selectedTable !== 'm_parameter'" class="text-center py-12">
      <svg class="animate-spin -ml-1 mr-3 h-8 w-8 text-blue-600 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
      <p class="mt-2 text-sm text-gray-500">Loading table data...</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import MParameter from './MParameter.vue'

// Reactive data
const selectedTable = ref('')
const tableData = ref([])
const tableColumns = ref([])
const loading = ref(false)
const editingRecord = ref(null)
const editingData = ref({})
const exporting = ref(false)
const creatingPR = ref(false)

// Available tables configuration
const availableTables = ref([
  { name: 'm_season', displayName: 'Seasons', recordCount: 0 },
  { name: 'm_level', displayName: 'Levels', recordCount: 0 },
  { name: 'm_room_type', displayName: 'Room Types', recordCount: 0 },
  { name: 'm_room_type_group', displayName: 'Room Type Groups', recordCount: 0 },
  { name: 'm_parameter', displayName: 'Parameters', recordCount: 0 },
  { name: 'm_activity_type', displayName: 'Activity Types', recordCount: 0 },
  { name: 'm_activity_type_detail', displayName: 'Activity Type Details', recordCount: 0 },
  { name: 'm_first_program', displayName: 'First Programs', recordCount: 0 },
  { name: 'm_insert_point', displayName: 'Insert Points', recordCount: 0 },
  { name: 'm_role', displayName: 'Roles', recordCount: 0 },
  { name: 'm_visibility', displayName: 'Visibility Rules', recordCount: 0 },
  { name: 'm_supported_plan', displayName: 'Supported Plans', recordCount: 0 }
])

// Methods
const loadTableData = async () => {
  if (!selectedTable.value || selectedTable.value === 'm_parameter') return
  
  loading.value = true
  try {
    const response = await axios.get(`/admin/main-tables/${selectedTable.value}`)
    tableData.value = response.data.data || []
    
    // Extract columns from first record
    if (tableData.value.length > 0) {
      tableColumns.value = Object.keys(tableData.value[0])
    } else {
      // If no data, get columns from API
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
    alert('Error saving record: ' + (error.response?.data?.message || error.message))
  }
}

const deleteRecord = async (index) => {
  if (!confirm('Are you sure you want to delete this record?')) return
  
  try {
    const record = tableData.value[index]
    if (record.id) {
      await axios.delete(`/admin/main-tables/${selectedTable.value}/${record.id}`)
    }
    tableData.value.splice(index, 1)
  } catch (error) {
    console.error('Error deleting record:', error)
    alert('Error deleting record: ' + (error.response?.data?.message || error.message))
  }
}

const addNewRecord = () => {
  const newRecord = {}
  tableColumns.value.forEach(column => {
    newRecord[column] = ''
  })
  tableData.value.push(newRecord)
  editingRecord.value = tableData.value.length - 1
  editingData.value = { ...newRecord }
}

const exportAllTables = async () => {
  exporting.value = true
  try {
    const response = await axios.get('/admin/main-tables/export', {
      responseType: 'blob'
    })
    
    // Create download link
    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', 'main-tables-data.json')
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
    
    // Check if seeder was generated
    const seederGenerated = response.headers['x-seeder-generated'] === 'true'
    const message = seederGenerated 
      ? 'Export completed successfully! MainDataSeeder.php has been generated for deployment.'
      : 'Export completed successfully!'
    alert(message)
  } catch (error) {
    console.error('Error exporting tables:', error)
    alert('Error exporting tables: ' + (error.response?.data?.message || error.message))
  } finally {
    exporting.value = false
  }
}

const createGitHubPR = async () => {
  creatingPR.value = true
  try {
    const response = await axios.post('/admin/main-tables/create-pr')
    
    // Show success message with PR details
    const message = response.data.success 
      ? `GitHub PR creation initiated successfully!\n\n${response.data.message}`
      : 'Failed to create GitHub PR'
    alert(message)
    
    // If there's output, show it
    if (response.data.output) {
      console.log('PR Creation Output:', response.data.output)
    }
  } catch (error) {
    console.error('Error creating GitHub PR:', error)
    alert('Error creating GitHub PR: ' + (error.response?.data?.message || error.message))
  } finally {
    creatingPR.value = false
  }
}

const getTableDisplayName = (tableName) => {
  const table = availableTables.value.find(t => t.name === tableName)
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

// Lifecycle
onMounted(() => {
  loadTableCounts()
})
</script>

<style scoped>
.main-tables-admin {
  max-width: 100%;
}
</style>
