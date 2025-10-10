<template>
  <div class="main-tables-admin">
    <div class="mb-6">
      <h2 class="text-2xl font-bold text-gray-900 mb-4">Main Tables Management</h2>
      
      <!-- Table Tabs -->
      <div class="mb-6">
        <div class="border-b border-gray-200 relative">
          <!-- Left scroll indicator -->
          <div 
            v-if="showLeftScroll"
            class="absolute left-0 top-0 bottom-0 w-8 bg-gradient-to-r from-white to-transparent z-10 flex items-center justify-center cursor-pointer hover:bg-gray-50"
            @mouseenter="scrollLeft"
            @mouseleave="stopScrolling"
          >
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
            </svg>
          </div>
          
          <!-- Right scroll indicator -->
          <div 
            v-if="showRightScroll"
            class="absolute right-0 top-0 bottom-0 w-8 bg-gradient-to-l from-white to-transparent z-10 flex items-center justify-center cursor-pointer hover:bg-gray-50"
            @mouseenter="scrollRight"
            @mouseleave="stopScrolling"
          >
            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
          </div>
          
          <nav 
            ref="tabContainer"
            class="-mb-px flex space-x-6 overflow-x-auto scrollbar-hide"
            @scroll="updateScrollIndicators"
          >
            <button
              v-for="table in availableTables"
              :key="table.name"
              :data-table="table.name"
              @click="selectTable(table.name)"
              :class="[
                selectedTable === table.name
                  ? 'border-blue-500 text-blue-600 bg-blue-50'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 hover:bg-gray-50',
                'whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm transition-all duration-200 ease-in-out rounded-t-lg tab-button'
              ]"
            >
              <span class="flex items-center">
                {{ table.displayName }}
                <span class="ml-2 text-xs bg-gray-200 text-gray-700 px-2 py-1 rounded-full font-semibold">
                  {{ table.recordCount }}
                </span>
              </span>
            </button>
          </nav>
        </div>
      </div>

      <!-- Export Button -->
      <div class="mb-4">
        <button
          @click="createGitHubPR"
          :disabled="loading || creatingPR"
          class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 disabled:opacity-50"
        >
          {{ creatingPR ? 'Creating PR...' : 'Export m_ table data' }}
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

    <!-- Special UI for m_visibility table -->
    <div v-else-if="selectedTable === 'm_visibility'" class="bg-white shadow overflow-hidden sm:rounded-md">
      <div class="px-4 py-5 sm:p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">
          {{ getTableDisplayName(selectedTable) }} - Advanced Editor
        </h3>
        <Visibility />
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
    <div v-else-if="selectedTable && selectedTable !== 'm_parameter' && selectedTable !== 'm_visibility' && tableData.length === 0" class="text-center py-12">
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
    <div v-else-if="loading && selectedTable !== 'm_parameter' && selectedTable !== 'm_visibility'" class="text-center py-12">
      <svg class="animate-spin -ml-1 mr-3 h-8 w-8 text-blue-600 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
      </svg>
      <p class="mt-2 text-sm text-gray-500">Loading table data...</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import MParameter from './MParameter.vue'
import Visibility from './Visibility.vue'

// Reactive data
const selectedTable = ref('')
const tableData = ref([])
const tableColumns = ref([])
const loading = ref(false)
const editingRecord = ref(null)
const editingData = ref({})
const creatingPR = ref(false)

// Scroll functionality
const tabContainer = ref(null)
const showLeftScroll = ref(false)
const showRightScroll = ref(false)
const scrollInterval = ref(null)

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
const selectTable = (tableName) => {
  selectedTable.value = tableName
  loadTableData()
  
  // Scroll to the selected tab
  setTimeout(() => {
    scrollToSelectedTab()
  }, 50)
}

const scrollToSelectedTab = () => {
  if (!tabContainer.value) return
  
  const selectedButton = tabContainer.value.querySelector(`button[data-table="${selectedTable.value}"]`)
  if (selectedButton) {
    const containerRect = tabContainer.value.getBoundingClientRect()
    const buttonRect = selectedButton.getBoundingClientRect()
    
    const scrollLeft = tabContainer.value.scrollLeft
    const buttonLeft = buttonRect.left - containerRect.left + scrollLeft
    const buttonRight = buttonLeft + buttonRect.width
    const containerWidth = containerRect.width
    
    if (buttonLeft < scrollLeft) {
      // Button is to the left of visible area
      tabContainer.value.scrollLeft = buttonLeft - 20
    } else if (buttonRight > scrollLeft + containerWidth) {
      // Button is to the right of visible area
      tabContainer.value.scrollLeft = buttonRight - containerWidth + 20
    }
  }
}

// Scroll functionality methods
const updateScrollIndicators = () => {
  if (!tabContainer.value) return
  
  const container = tabContainer.value
  const scrollLeft = container.scrollLeft
  const scrollWidth = container.scrollWidth
  const clientWidth = container.clientWidth
  
  // Add a small tolerance to account for sub-pixel rendering
  const tolerance = 1
  
  showLeftScroll.value = scrollLeft > tolerance
  showRightScroll.value = scrollLeft < (scrollWidth - clientWidth - tolerance)
}

const scrollLeft = () => {
  if (!tabContainer.value) return
  
  console.log('Starting left scroll, current scrollLeft:', tabContainer.value.scrollLeft)
  
  // Clear any existing interval first
  stopScrolling()
  
  scrollInterval.value = setInterval(() => {
    if (tabContainer.value) {
      const oldScrollLeft = tabContainer.value.scrollLeft
      tabContainer.value.scrollLeft -= 15
      console.log('Left scroll: old =', oldScrollLeft, 'new =', tabContainer.value.scrollLeft)
      
      // Update indicators after scrolling
      updateScrollIndicators()
      
      // Stop if we've reached the beginning
      if (tabContainer.value.scrollLeft <= 0) {
        console.log('Reached beginning, stopping left scroll')
        stopScrolling()
      }
    }
  }, 16) // ~60fps
}

const scrollRight = () => {
  if (!tabContainer.value) return
  
  console.log('Starting right scroll, current scrollLeft:', tabContainer.value.scrollLeft)
  
  // Clear any existing interval first
  stopScrolling()
  
  scrollInterval.value = setInterval(() => {
    if (tabContainer.value) {
      const oldScrollLeft = tabContainer.value.scrollLeft
      tabContainer.value.scrollLeft += 15
      console.log('Right scroll: old =', oldScrollLeft, 'new =', tabContainer.value.scrollLeft)
      
      // Update indicators after scrolling
      updateScrollIndicators()
      
      // Stop if we've reached the end
      const maxScrollLeft = tabContainer.value.scrollWidth - tabContainer.value.clientWidth
      if (tabContainer.value.scrollLeft >= maxScrollLeft) {
        console.log('Reached end, stopping right scroll')
        stopScrolling()
      }
    }
  }, 16) // ~60fps
}

const stopScrolling = () => {
  if (scrollInterval.value) {
    clearInterval(scrollInterval.value)
    scrollInterval.value = null
  }
}

const loadTableData = async () => {
  if (!selectedTable.value || selectedTable.value === 'm_parameter' || selectedTable.value === 'm_visibility') return
  
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
  // Initialize scroll indicators after a short delay to ensure DOM is ready
  setTimeout(() => {
    updateScrollIndicators()
  }, 100)
  
  // Add resize listener
  window.addEventListener('resize', updateScrollIndicators)
})

// Cleanup on unmount
onUnmounted(() => {
  stopScrolling()
  window.removeEventListener('resize', updateScrollIndicators)
})
</script>

<style scoped>
.main-tables-admin {
  max-width: 100%;
}

.scrollbar-hide {
  -ms-overflow-style: none;
  scrollbar-width: none;
}

.scrollbar-hide::-webkit-scrollbar {
  display: none;
}

/* Smooth transitions for tab interactions */
.tab-button {
  transition: all 0.2s ease-in-out;
}

.tab-button:hover {
  transform: translateY(-1px);
}

.tab-button:active {
  transform: translateY(0);
}
</style>
