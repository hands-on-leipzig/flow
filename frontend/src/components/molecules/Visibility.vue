<template>
  <div class="visibility-admin">

    <!-- Filters -->
    <div class="filters mb-4 flex gap-4 items-center">
      <div class="flex items-center gap-2">
        <label class="text-sm font-medium text-gray-700">Activity Type:</label>
        <select 
          v-model="activityFilter" 
          @change="loadMatrix"
          class="border border-gray-300 rounded-md px-3 py-2 text-sm"
        >
          <option value="all">Alle</option>
          <option value="1">Robot-Game</option>
          <option value="2">Jury</option>
          <option value="3">Ausstellung</option>
          <option value="4">Mittagspause</option>
          <option value="5">Eröffnung</option>
          <option value="6">Preisverleihung</option>
          <option value="7">Orga</option>
          <option value="8">Live-Challenge</option>
          <option value="9">Zusatzblock</option>
          <option value="10">Eröffnung (Explore)</option>
          <option value="11">Preisverleihung (Explore)</option>
        </select>
      </div>
      
      <div class="flex items-center gap-2">
        <label class="text-sm font-medium text-gray-700">FIRST Program:</label>
        <select 
          v-model="roleFilter" 
          @change="loadMatrix"
          class="border border-gray-300 rounded-md px-3 py-2 text-sm"
        >
          <option value="all">Alle</option>
          <option value="2">Explore</option>
          <option value="3">Challenge</option>
          <option value="null">Allgemein</option>
        </select>
      </div>
      
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="text-center py-8">
      <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
      <p class="mt-2 text-sm text-gray-600">Loading visibility matrix...</p>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-md p-4">
      <p class="text-sm text-red-600">{{ error }}</p>
      <button 
        @click="loadMatrix" 
        class="mt-2 text-sm text-red-600 hover:text-red-800 underline"
      >
        Try again
      </button>
    </div>

    <!-- Matrix Table (Flipped: Activities as rows, Roles as columns) -->
    <div v-else class="matrix-wrapper">
      <table class="sticky-matrix">
        <thead class="sticky-top">
          <tr>
            <th class="sticky-left bg-gray-50 font-medium text-gray-900 px-4 py-3 text-left">
              Activity
            </th>
            <th 
              v-for="role in roles" 
              :key="role.id"
              class="bg-gray-50 font-medium text-gray-900 px-3 py-3 text-center min-w-[120px]"
            >
              <div class="flex items-center justify-center">
                <div 
                  class="w-3 h-3 rounded-full mr-2 flex-shrink-0"
                  :class="getActivityColor(role.program)"
                ></div>
                {{ role.name }}
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="activity in activities" :key="activity.id" class="border-b border-gray-200">
            <td class="sticky-left bg-white font-medium text-gray-900 px-4 py-3">
              <div class="flex items-center">
                <div 
                  class="w-3 h-3 rounded-full mr-2 flex-shrink-0"
                  :class="getActivityColor(activity.program)"
                ></div>
                {{ activity.name }}
              </div>
            </td>
            <td 
              v-for="role in roles" 
              :key="role.id"
              class="px-3 py-3 text-center"
            >
              <input 
                type="checkbox" 
                :checked="isVisible(role.id, activity.id)"
                @change="toggleVisibility(role.id, activity.id, $event.target.checked)"
                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                :disabled="toggling"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

// Reactive data
const loading = ref(false)
const error = ref(null)
const toggling = ref(false)
const roles = ref([])
const activities = ref([])
const matrix = ref([])

// Filters
const roleFilter = ref('all')
const activityFilter = ref('all')

// Load initial data
onMounted(() => {
  loadRoles()
  loadActivities()
  loadMatrix()
})

// API calls
const loadRoles = async () => {
  try {
    console.log('Loading roles...')
    const response = await axios.get('/visibility/roles')
    console.log('Roles response:', response.data)
    roles.value = response.data
  } catch (err) {
    console.error('Failed to load roles:', err)
    console.error('Error response:', err.response?.data)
    console.error('Error status:', err.response?.status)
  }
}

const loadActivities = async () => {
  try {
    console.log('Loading activities...')
    const response = await axios.get('/visibility/activity-types')
    console.log('Activities response:', response.data)
    activities.value = response.data
  } catch (err) {
    console.error('Failed to load activities:', err)
    console.error('Error response:', err.response?.data)
    console.error('Error status:', err.response?.status)
  }
}

const loadMatrix = async () => {
  loading.value = true
  error.value = null
  
  try {
    const params = {
      role_filter: roleFilter.value,
      activity_filter: activityFilter.value
    }
    
    console.log('Loading matrix with params:', params)
    const response = await axios.get('/visibility/matrix', { params })
    console.log('Matrix response:', response.data)
    
    // Update roles and activities from matrix response
    roles.value = response.data.roles
    activities.value = response.data.activities
    matrix.value = response.data.matrix
  } catch (err) {
    error.value = 'Failed to load visibility matrix'
    console.error('Failed to load matrix:', err)
    console.error('Error response:', err.response?.data)
    console.error('Error status:', err.response?.status)
  } finally {
    loading.value = false
  }
}

// Helper functions
const isVisible = (roleId, activityId) => {
  const role = matrix.value.find(r => r.role.id === roleId)
  if (!role) return false
  
  const activity = role.activities.find(a => a.activity.id === activityId)
  return activity ? activity.visible : false
}

const getActivityColor = (program) => {
  switch (program) {
    case 'CHALLENGE':
      return 'bg-red-500'
    case 'EXPLORE':
      return 'bg-green-500'
    case 'DISCOVER':
      return 'bg-gray-500'
    default:
      return 'bg-gray-400'
  }
}

const toggleVisibility = async (roleId, activityId, visible) => {
  toggling.value = true
  
  try {
    console.log('Toggling visibility:', { roleId, activityId, visible })
    const response = await axios.post('/visibility/toggle', {
      role_id: roleId,
      activity_type_detail_id: activityId,
      visible: visible
    })
    console.log('Toggle response:', response.data)
    
    // Update local state
    const role = matrix.value.find(r => r.role.id === roleId)
    console.log('Found role:', role)
    if (role) {
      const activity = role.activities.find(a => a.activity.id === activityId)
      console.log('Found activity:', activity)
      if (activity) {
        activity.visible = visible
        console.log('Updated activity visibility to:', visible)
      } else {
        console.warn('Activity not found in matrix for role:', roleId, 'activity:', activityId)
      }
    } else {
      console.warn('Role not found in matrix:', roleId)
    }
  } catch (err) {
    error.value = 'Failed to update visibility'
    console.error('Failed to toggle visibility:', err)
    console.error('Error response:', err.response?.data)
  } finally {
    toggling.value = false
  }
}
</script>

<style scoped>
.matrix-wrapper {
  overflow: auto;
  max-height: 70vh;
  max-width: 90vw;
  border: 1px solid #e5e7eb;
  border-radius: 0.375rem;
}

.sticky-matrix {
  border-collapse: separate;
  border-spacing: 0;
  width: 100%;
}

.sticky-top {
  position: sticky;
  top: 0;
  z-index: 10;
}

.sticky-left {
  position: sticky;
  left: 0;
  z-index: 5;
}

.sticky-top.sticky-left {
  z-index: 15;
}

.filters {
  background: #f9fafb;
  padding: 1rem;
  border-radius: 0.375rem;
  border: 1px solid #e5e7eb;
}
</style>

<style scoped>
.visibility-admin {
  max-width: 100%;
}
</style>
