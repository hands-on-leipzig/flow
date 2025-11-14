<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const relations = ref([])
const statistics = ref({})
const selectionData = ref({ users: [], regional_partners: [] })
const loading = ref(false)
const error = ref(null)

// Add relation form
const showAddForm = ref(false)
const selectedUserId = ref(null)
const selectedRegionalPartnerId = ref(null)
const addingRelation = ref(false)

// Search functionality
const userSearchQuery = ref('')
const filteredUsers = ref([])
const showUserDropdown = ref(false)
const selectedUser = ref(null)

const fetchData = async () => {
  loading.value = true
  error.value = null
  
  try {
    const [relationsResponse, statisticsResponse, selectionResponse] = await Promise.all([
      axios.get('/admin/user-regional-partners'),
      axios.get('/admin/user-regional-partners/statistics'),
      axios.get('/admin/user-regional-partners/selection-data')
    ])
    
    relations.value = relationsResponse.data.relations
    statistics.value = statisticsResponse.data
    selectionData.value = selectionResponse.data
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to fetch data'
    console.error('Error fetching user-regional partner data:', err)
  } finally {
    loading.value = false
  }
}

const addRelation = async () => {
  if (!selectedUserId.value || !selectedRegionalPartnerId.value) {
    error.value = 'Please select both a user and a regional partner'
    return
  }

  addingRelation.value = true
  error.value = null
  
  try {
    await axios.post('/admin/user-regional-partners', {
      user_id: selectedUserId.value,
      regional_partner_id: selectedRegionalPartnerId.value
    })
    
    // Reset form
    selectedUserId.value = null
    selectedRegionalPartnerId.value = null
    selectedUser.value = null
    userSearchQuery.value = ''
    showAddForm.value = false
    showUserDropdown.value = false
    
    // Refresh data
    await fetchData()
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to add relation'
    console.error('Error adding relation:', err)
  } finally {
    addingRelation.value = false
  }
}

const cancelAddRelation = () => {
  selectedUserId.value = null
  selectedRegionalPartnerId.value = null
  selectedUser.value = null
  userSearchQuery.value = ''
  showAddForm.value = false
  showUserDropdown.value = false
  error.value = null
}

// User search functionality
const searchUsers = () => {
  if (!userSearchQuery.value.trim()) {
    filteredUsers.value = []
    showUserDropdown.value = false
    return
  }

  const query = userSearchQuery.value.toLowerCase()
  filteredUsers.value = selectionData.value.users.filter(user => 
    user.subject?.toLowerCase().includes(query) ||
    user.id.toString().includes(query) ||
    user.display_name?.toLowerCase().includes(query) ||
    user.name?.toLowerCase().includes(query) ||
    user.email?.toLowerCase().includes(query)
  )
  
  showUserDropdown.value = filteredUsers.value.length > 0
}

const selectUser = (user) => {
  selectedUser.value = user
  selectedUserId.value = user.id
  userSearchQuery.value = user.display_name
  showUserDropdown.value = false
}

const clearUserSelection = () => {
  selectedUser.value = null
  selectedUserId.value = null
  userSearchQuery.value = ''
  showUserDropdown.value = false
}

const removeRelation = async (userId, regionalPartnerId) => {
  try {
    await axios.delete('/admin/user-regional-partners', {
      data: {
        user_id: userId,
        regional_partner_id: regionalPartnerId
      }
    })
    
    // Refresh data
    await fetchData()
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to remove relation'
    console.error('Error removing relation:', err)
  }
}

onMounted(() => {
  fetchData()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-white p-4 rounded-lg shadow">
        <h3 class="text-sm font-medium text-gray-500">Total Users</h3>
        <p class="text-2xl font-bold text-gray-900">{{ statistics.total_users || 0 }}</p>
      </div>
      
      <div class="bg-white p-4 rounded-lg shadow">
        <h3 class="text-sm font-medium text-gray-500">Users with Regional Partners</h3>
        <p class="text-2xl font-bold text-green-600">{{ statistics.users_with_regional_partners || 0 }}</p>
      </div>
      
      <div class="bg-white p-4 rounded-lg shadow">
        <h3 class="text-sm font-medium text-gray-500">Users without Regional Partners</h3>
        <p class="text-2xl font-bold text-red-600">{{ statistics.users_without_regional_partners || 0 }}</p>
      </div>
      
      <div class="bg-white p-4 rounded-lg shadow">
        <h3 class="text-sm font-medium text-gray-500">Avg Partners per User</h3>
        <p class="text-2xl font-bold text-blue-600">{{ Math.round(statistics.average_regional_partners_per_user || 0) }}</p>
      </div>
    </div>

    <!-- Error Message -->
    <div v-if="error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
      {{ error }}
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center items-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
    </div>

    <!-- Add Relation Form -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
      <div class="px-4 py-5 sm:p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-medium text-gray-900">Add User-Regional Partner Relation</h3>
          <button
            v-if="!showAddForm"
            @click="showAddForm = true"
            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
          >
            ➕ Add Relation
          </button>
        </div>

        <!-- Add Form -->
        <div v-if="showAddForm" class="border border-gray-200 rounded-lg p-4 bg-gray-50">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <!-- User Selection -->
            <div>
              <label for="user-search" class="block text-sm font-medium text-gray-700 mb-2">
                Select User
              </label>
              <div class="relative">
                <input
                  id="user-search"
                  v-model="userSearchQuery"
                  @input="searchUsers"
                  @focus="searchUsers"
                  @blur="setTimeout(() => showUserDropdown = false, 200)"
                  type="text"
                  placeholder="Type to search by name, email, ID or subject..."
                  class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                />
                
                <!-- Clear button -->
                <button
                  v-if="selectedUser"
                  @click="clearUserSelection"
                  type="button"
                  class="absolute right-8 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600"
                >
                  ✕
                </button>
                
                <!-- Search icon -->
                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                  🔍
                </div>
                
                <!-- Dropdown with search results -->
                <div
                  v-if="showUserDropdown && filteredUsers.length > 0"
                  class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-auto"
                >
                  <div
                    v-for="user in filteredUsers"
                    :key="user.id"
                    @click="selectUser(user)"
                    class="px-3 py-2 hover:bg-gray-100 cursor-pointer border-b border-gray-100 last:border-b-0"
                  >
                    <div class="text-sm font-medium text-gray-900">{{ user.display_name }}</div>
                    <div v-if="user.name || user.email" class="text-xs text-gray-600">
                      <span v-if="user.name">{{ user.name }}</span>
                      <span v-if="user.name && user.email"> • </span>
                      <span v-if="user.email">{{ user.email }}</span>
                    </div>
                    <div v-if="user.subject" class="text-xs text-gray-500">{{ user.subject }}</div>
                  </div>
                </div>
                
                <!-- No results message -->
                <div
                  v-if="showUserDropdown && filteredUsers.length === 0 && userSearchQuery.trim()"
                  class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg"
                >
                  <div class="px-3 py-2 text-sm text-gray-500">
                    No users found matching "{{ userSearchQuery }}"
                  </div>
                </div>
              </div>
            </div>

            <!-- Regional Partner Selection -->
            <div>
              <label for="partner-select" class="block text-sm font-medium text-gray-700 mb-2">
                Select Regional Partner
              </label>
              <select
                id="partner-select"
                v-model="selectedRegionalPartnerId"
                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
              >
                <option value="">Choose a regional partner...</option>
                <option
                  v-for="partner in selectionData.regional_partners"
                  :key="partner.id"
                  :value="partner.id"
                >
                  {{ partner.display_name }}
                </option>
              </select>
            </div>
          </div>

          <!-- Form Actions -->
          <div class="flex justify-end space-x-3">
            <button
              @click="cancelAddRelation"
              class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
              Cancel
            </button>
            <button
              @click="addRelation"
              :disabled="addingRelation || !selectedUserId || !selectedRegionalPartnerId"
              class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span v-if="addingRelation" class="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></span>
              {{ addingRelation ? 'Adding...' : 'Add Relation' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Relations Table -->
    <div v-if="!loading" class="bg-white shadow rounded-lg overflow-hidden">
      <div class="px-4 py-5 sm:p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">User-Regional Partner Relations</h3>
        
        <div v-if="relations.length === 0" class="text-center py-8 text-gray-500">
          No user-regional partner relations found.
        </div>
        
        <div v-else class="space-y-4">
          <div 
            v-for="userRelation in relations" 
            :key="userRelation.user_id"
            class="border border-gray-200 rounded-lg p-4"
          >
            <!-- User Header -->
            <div class="flex items-center justify-between mb-3">
              <div>
                <h4 class="text-sm font-medium text-gray-900">
                  <span v-if="userRelation.user_name">{{ userRelation.user_name }}</span>
                  <span v-else-if="userRelation.user_email">{{ userRelation.user_email }}</span>
                  <span v-else-if="userRelation.user_subject">{{ userRelation.user_subject }}</span>
                  <span v-else>User ID: {{ userRelation.user_id }}</span>
                </h4>
                <div class="text-sm text-gray-500 space-y-1">
                  <p v-if="userRelation.user_email" class="text-gray-600">
                    📧 {{ userRelation.user_email }}
                  </p>
                  <p v-if="userRelation.user_subject" class="text-gray-500">
                    Subject: {{ userRelation.user_subject }}
                  </p>
                  <p v-if="!userRelation.user_name && !userRelation.user_email && !userRelation.user_subject" class="text-xs text-gray-400">
                    ID: {{ userRelation.user_id }}
                  </p>
                </div>
              </div>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                {{ userRelation.regional_partners.length }} Partner{{ userRelation.regional_partners.length !== 1 ? 's' : '' }}
              </span>
            </div>
            
            <!-- Regional Partners -->
            <div class="space-y-2">
              <div 
                v-for="partner in userRelation.regional_partners"
                :key="partner.id"
                class="flex items-center justify-between bg-gray-50 rounded-md p-3"
              >
                <div class="flex-1">
                  <h5 class="text-sm font-medium text-gray-900">{{ partner.name }}</h5>
                  <p class="text-xs text-gray-500">
                    Region: {{ partner.region }} | Dolibarr ID: {{ partner.dolibarr_id }}
                  </p>
                </div>
                
                <button
                  @click="removeRelation(userRelation.user_id, partner.id)"
                  class="ml-3 inline-flex items-center px-2 py-1 border border-transparent text-xs font-medium rounded text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                  Remove
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Most Common Regional Partners -->
    <div v-if="statistics.most_common_regional_partners?.length > 0" class="bg-white shadow rounded-lg overflow-hidden">
      <div class="px-4 py-5 sm:p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Most Common Regional Partners</h3>
        
        <div class="space-y-3">
          <div 
            v-for="partner in statistics.most_common_regional_partners"
            :key="partner.id"
            class="flex items-center justify-between p-3 bg-gray-50 rounded-md"
          >
            <div class="flex-1">
              <h4 class="text-sm font-medium text-gray-900">{{ partner.name }}</h4>
              <p class="text-xs text-gray-500">{{ partner.region }}</p>
            </div>
            <div class="text-right">
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                {{ partner.user_count }} User{{ partner.user_count !== 1 ? 's' : '' }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Custom styles if needed */
</style>
