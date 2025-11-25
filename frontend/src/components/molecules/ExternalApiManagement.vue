<script setup>
import {ref, onMounted} from 'vue'
import axios from 'axios'

const applications = ref([])
const loading = ref(false)
const error = ref(null)

// Application form
const showApplicationForm = ref(false)
const editingApplication = ref(null)
const applicationForm = ref({
  name: '',
  description: '',
  contact_email: '',
  webhook_url: '',
  allowed_ips: [],
  rate_limit: 1000,
  is_active: true
})
const ipInput = ref('')

// API key form
const showApiKeyForm = ref(false)
const selectedApplicationId = ref(null)
const apiKeyForm = ref({
  name: '',
  scopes: [],
  expires_at: ''
})
const newApiKey = ref(null) // Store the plain key when created
const showApiKeyModal = ref(false)

// Available scopes
const availableScopes = [
  {value: 'events:read', label: 'Events: Read'},
  {value: 'events:write', label: 'Events: Write'},
  {value: 'plans:read', label: 'Plans: Read'},
  {value: 'plans:write', label: 'Plans: Write'},
  {value: 'teams:read', label: 'Teams: Read'},
  {value: 'teams:write', label: 'Teams: Write'},
]

const fetchApplications = async () => {
  loading.value = true
  error.value = null

  try {
    const response = await axios.get('/admin/applications')
    applications.value = response.data
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to fetch applications'
    console.error('Error fetching applications:', err)
  } finally {
    loading.value = false
  }
}

const openApplicationForm = (application = null) => {
  editingApplication.value = application
  if (application) {
    applicationForm.value = {
      name: application.name,
      description: application.description || '',
      contact_email: application.contact_email,
      webhook_url: application.webhook_url || '',
      allowed_ips: application.allowed_ips || [],
      rate_limit: application.rate_limit,
      is_active: application.is_active
    }
  } else {
    applicationForm.value = {
      name: '',
      description: '',
      contact_email: '',
      webhook_url: '',
      allowed_ips: [],
      rate_limit: 1000,
      is_active: true
    }
  }
  showApplicationForm.value = true
}

const closeApplicationForm = () => {
  showApplicationForm.value = false
  editingApplication.value = null
  applicationForm.value = {
    name: '',
    description: '',
    contact_email: '',
    webhook_url: '',
    allowed_ips: [],
    rate_limit: 1000,
    is_active: true
  }
  ipInput.value = ''
}

const addIp = () => {
  if (ipInput.value.trim() && !applicationForm.value.allowed_ips.includes(ipInput.value.trim())) {
    applicationForm.value.allowed_ips.push(ipInput.value.trim())
    ipInput.value = ''
  }
}

const removeIp = (ip) => {
  applicationForm.value.allowed_ips = applicationForm.value.allowed_ips.filter(i => i !== ip)
}

const saveApplication = async () => {
  loading.value = true
  error.value = null

  try {
    if (editingApplication.value) {
      await axios.put(`/admin/applications/${editingApplication.value.id}`, applicationForm.value)
    } else {
      await axios.post('/admin/applications', applicationForm.value)
    }
    closeApplicationForm()
    await fetchApplications()
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to save application'
    if (err.response?.data?.errors) {
      error.value += ': ' + JSON.stringify(err.response.data.errors)
    }
    console.error('Error saving application:', err)
  } finally {
    loading.value = false
  }
}

const deleteApplication = async (application) => {
  if (!confirm(`Are you sure you want to delete "${application.name}"? This will also delete all associated API keys.`)) {
    return
  }

  loading.value = true
  error.value = null

  try {
    await axios.delete(`/admin/applications/${application.id}`)
    await fetchApplications()
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to delete application'
    console.error('Error deleting application:', err)
  } finally {
    loading.value = false
  }
}

const openApiKeyForm = (applicationId) => {
  selectedApplicationId.value = applicationId
  apiKeyForm.value = {
    name: '',
    scopes: [],
    expires_at: ''
  }
  showApiKeyForm.value = true
}

const closeApiKeyForm = () => {
  showApiKeyForm.value = false
  selectedApplicationId.value = null
  apiKeyForm.value = {
    name: '',
    scopes: [],
    expires_at: ''
  }
}

const createApiKey = async () => {
  if (!apiKeyForm.value.name) {
    error.value = 'Please enter a name for the API key'
    return
  }

  loading.value = true
  error.value = null

  try {
    const response = await axios.post(`/admin/applications/${selectedApplicationId.value}/api-keys`, apiKeyForm.value)
    newApiKey.value = response.data.plain_key
    showApiKeyModal.value = true
    closeApiKeyForm()
    await fetchApplications()
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to create API key'
    if (err.response?.data?.errors) {
      error.value += ': ' + JSON.stringify(err.response.data.errors)
    }
    console.error('Error creating API key:', err)
  } finally {
    loading.value = false
  }
}

const deleteApiKey = async (applicationId, apiKeyId, apiKeyName) => {
  if (!confirm(`Are you sure you want to delete the API key "${apiKeyName}"?`)) {
    return
  }

  loading.value = true
  error.value = null

  try {
    await axios.delete(`/admin/applications/${applicationId}/api-keys/${apiKeyId}`)
    await fetchApplications()
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to delete API key'
    console.error('Error deleting API key:', err)
  } finally {
    loading.value = false
  }
}

const toggleApiKeyActive = async (applicationId, apiKey) => {
  loading.value = true
  error.value = null

  try {
    await axios.put(`/admin/applications/${applicationId}/api-keys/${apiKey.id}`, {
      is_active: !apiKey.is_active
    })
    await fetchApplications()
  } catch (err) {
    error.value = err.response?.data?.error || 'Failed to update API key'
    console.error('Error updating API key:', err)
  } finally {
    loading.value = false
  }
}

const copyApiKey = () => {
  if (newApiKey.value) {
    navigator.clipboard.writeText(newApiKey.value)
    alert('API key copied to clipboard!')
  }
}

const formatDate = (dateString) => {
  if (!dateString) return 'Never'
  return new Date(dateString).toLocaleString()
}

const isExpired = (expiresAt) => {
  if (!expiresAt) return false
  return new Date(expiresAt) < new Date()
}

onMounted(() => {
  fetchApplications()
})
</script>

<template>
  <div class="space-y-6">
    <!-- Error message -->
    <div v-if="error" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
      {{ error }}
    </div>

    <!-- Header -->
    <div class="flex justify-between items-center">
      <h2 class="text-2xl font-bold">External API Management</h2>
      <button
        @click="openApplicationForm()"
        class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
      >
        ➕ Create Application
      </button>
    </div>

    <!-- Loading state -->
    <div v-if="loading && applications.length === 0" class="text-center py-8">
      Loading...
    </div>

    <!-- Applications list -->
    <div v-else class="space-y-4">
      <div
        v-for="application in applications"
        :key="application.id"
        class="bg-white rounded-lg shadow p-6 border border-gray-200"
      >
        <div class="flex justify-between items-start mb-4">
          <div>
            <h3 class="text-xl font-semibold">{{ application.name }}</h3>
            <p v-if="application.description" class="text-gray-600 mt-1">{{ application.description }}</p>
            <div class="mt-2 text-sm text-gray-500">
              <p>Contact: {{ application.contact_email }}</p>
              <p>Rate Limit: {{ application.rate_limit }} requests/hour</p>
              <p>Status: 
                <span :class="application.is_active ? 'text-green-600' : 'text-red-600'">
                  {{ application.is_active ? 'Active' : 'Inactive' }}
                </span>
              </p>
            </div>
          </div>
          <div class="flex gap-2">
            <button
              @click="openApplicationForm(application)"
              class="px-3 py-1 bg-gray-500 text-white rounded text-sm hover:bg-gray-600"
            >
              Edit
            </button>
            <button
              @click="deleteApplication(application)"
              class="px-3 py-1 bg-red-500 text-white rounded text-sm hover:bg-red-600"
            >
              Delete
            </button>
          </div>
        </div>

        <!-- API Keys section -->
        <div class="mt-4 border-t pt-4">
          <div class="flex justify-between items-center mb-2">
            <h4 class="font-semibold">API Keys</h4>
            <button
              @click="openApiKeyForm(application.id)"
              class="px-3 py-1 bg-green-500 text-white rounded text-sm hover:bg-green-600"
            >
              ➕ Create API Key
            </button>
          </div>

          <div v-if="application.api_keys && application.api_keys.length > 0" class="space-y-2">
            <div
              v-for="apiKey in application.api_keys"
              :key="apiKey.id"
              class="bg-gray-50 rounded p-3 flex justify-between items-center"
            >
              <div class="flex-1">
                <div class="flex items-center gap-2">
                  <span class="font-medium">{{ apiKey.name }}</span>
                  <span
                    :class="[
                      'px-2 py-0.5 rounded text-xs',
                      apiKey.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                    ]"
                  >
                    {{ apiKey.is_active ? 'Active' : 'Inactive' }}
                  </span>
                  <span
                    v-if="apiKey.expires_at && isExpired(apiKey.expires_at)"
                    class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800"
                  >
                    Expired
                  </span>
                </div>
                <div class="text-sm text-gray-600 mt-1">
                  <p>Scopes: {{ apiKey.scopes && apiKey.scopes.length > 0 ? apiKey.scopes.join(', ') : 'None' }}</p>
                  <p>Last used: {{ formatDate(apiKey.last_used_at) }}</p>
                  <p v-if="apiKey.expires_at">Expires: {{ formatDate(apiKey.expires_at) }}</p>
                </div>
              </div>
              <div class="flex gap-2">
                <button
                  @click="toggleApiKeyActive(application.id, apiKey)"
                  class="px-2 py-1 bg-gray-500 text-white rounded text-xs hover:bg-gray-600"
                >
                  {{ apiKey.is_active ? 'Deactivate' : 'Activate' }}
                </button>
                <button
                  @click="deleteApiKey(application.id, apiKey.id, apiKey.name)"
                  class="px-2 py-1 bg-red-500 text-white rounded text-xs hover:bg-red-600"
                >
                  Delete
                </button>
              </div>
            </div>
          </div>
          <div v-else class="text-gray-500 text-sm">
            No API keys created yet
          </div>
        </div>
      </div>

      <div v-if="applications.length === 0" class="text-center py-8 text-gray-500">
        No applications created yet. Click "Create Application" to get started.
      </div>
    </div>

    <!-- Application Form Modal -->
    <div
      v-if="showApplicationForm"
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
      @click.self="closeApplicationForm"
    >
      <div class="bg-white rounded-lg p-6 max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
        <h3 class="text-xl font-bold mb-4">
          {{ editingApplication ? 'Edit Application' : 'Create Application' }}
        </h3>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Name *</label>
            <input
              v-model="applicationForm.name"
              type="text"
              class="w-full border rounded px-3 py-2"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Description</label>
            <textarea
              v-model="applicationForm.description"
              class="w-full border rounded px-3 py-2"
              rows="3"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Contact Email *</label>
            <input
              v-model="applicationForm.contact_email"
              type="email"
              class="w-full border rounded px-3 py-2"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Webhook URL</label>
            <input
              v-model="applicationForm.webhook_url"
              type="url"
              class="w-full border rounded px-3 py-2"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Rate Limit (requests/hour)</label>
            <input
              v-model.number="applicationForm.rate_limit"
              type="number"
              min="1"
              max="100000"
              class="w-full border rounded px-3 py-2"
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Allowed IPs (optional)</label>
            <div class="flex gap-2 mb-2">
              <input
                v-model="ipInput"
                type="text"
                placeholder="Enter IP address"
                class="flex-1 border rounded px-3 py-2"
                @keyup.enter="addIp"
              />
              <button
                @click="addIp"
                class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
              >
                Add
              </button>
            </div>
            <div v-if="applicationForm.allowed_ips.length > 0" class="flex flex-wrap gap-2">
              <span
                v-for="ip in applicationForm.allowed_ips"
                :key="ip"
                class="px-2 py-1 bg-gray-200 rounded text-sm flex items-center gap-1"
              >
                {{ ip }}
                <button @click="removeIp(ip)" class="text-red-600 hover:text-red-800">×</button>
              </span>
            </div>
          </div>

          <div class="flex items-center">
            <input
              v-model="applicationForm.is_active"
              type="checkbox"
              class="mr-2"
            />
            <label class="text-sm font-medium">Active</label>
          </div>
        </div>

        <div class="flex justify-end gap-2 mt-6">
          <button
            @click="closeApplicationForm"
            class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600"
          >
            Cancel
          </button>
          <button
            @click="saveApplication"
            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
            :disabled="loading"
          >
            {{ editingApplication ? 'Update' : 'Create' }}
          </button>
        </div>
      </div>
    </div>

    <!-- API Key Form Modal -->
    <div
      v-if="showApiKeyForm"
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
      @click.self="closeApiKeyForm"
    >
      <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold mb-4">Create API Key</h3>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-1">Name *</label>
            <input
              v-model="apiKeyForm.name"
              type="text"
              class="w-full border rounded px-3 py-2"
              placeholder="e.g., Production Key"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Scopes</label>
            <div class="space-y-2 max-h-40 overflow-y-auto border rounded p-2">
              <label
                v-for="scope in availableScopes"
                :key="scope.value"
                class="flex items-center"
              >
                <input
                  v-model="apiKeyForm.scopes"
                  type="checkbox"
                  :value="scope.value"
                  class="mr-2"
                />
                <span class="text-sm">{{ scope.label }}</span>
              </label>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1">Expires At (optional)</label>
            <input
              v-model="apiKeyForm.expires_at"
              type="datetime-local"
              class="w-full border rounded px-3 py-2"
            />
          </div>
        </div>

        <div class="flex justify-end gap-2 mt-6">
          <button
            @click="closeApiKeyForm"
            class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600"
          >
            Cancel
          </button>
          <button
            @click="createApiKey"
            class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600"
            :disabled="loading"
          >
            Create
          </button>
        </div>
      </div>
    </div>

    <!-- API Key Display Modal -->
    <div
      v-if="showApiKeyModal"
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
      @click.self="showApiKeyModal = false"
    >
      <div class="bg-white rounded-lg p-6 max-w-2xl w-full mx-4">
        <h3 class="text-xl font-bold mb-4">API Key Created</h3>
        <p class="text-red-600 font-semibold mb-4">
          ⚠️ Store this key securely - it will not be shown again!
        </p>
        <div class="bg-gray-100 rounded p-4 mb-4">
          <code class="text-sm break-all">{{ newApiKey }}</code>
        </div>
        <div class="flex justify-end gap-2">
          <button
            @click="copyApiKey"
            class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600"
          >
            Copy to Clipboard
          </button>
          <button
            @click="showApiKeyModal = false; newApiKey = null"
            class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
button:focus {
  outline: none;
}
</style>

