<script setup>
import {ref, onMounted, computed, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

const logos = ref([])
const eventStore = useEventStore()
const selectedEvent = computed(() => eventStore.selectedEvent)
const uploadFile = ref(null)
const fileInput = ref(null)
const selectedLogoForPreview = ref(null)
const logoToDelete = ref(null)

const fetchLogos = async () => {
  const {data} = await axios.get('/logos')
  logos.value = data
}

const uploadLogo = async () => {
  if (!uploadFile.value) return
  
  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent?.regional_partner) {
    alert('Bitte wählen Sie zuerst ein Event aus, bevor Sie ein Logo hochladen.')
    return
  }
  
  // Validate file
  if (uploadFile.value.size > 2 * 1024 * 1024) {
    alert('Datei ist zu groß. Maximum: 2MB')
    return
  }
  
  if (!uploadFile.value.type.startsWith('image/')) {
    alert('Datei muss ein Bild sein')
    return
  }
  
  try {
    const formData = new FormData()
    formData.append('file', uploadFile.value)
    formData.append('regional_partner', currentEvent.regional_partner)

    await axios.post('/logos', formData)
    await fetchLogos()
    
    // Clear the file input after successful upload
    uploadFile.value = null
    if (fileInput.value) {
      fileInput.value.value = ''
    }
  } catch (error) {
    console.error('Error uploading logo:', error)
    if (error.response?.status === 422) {
      alert('Validierungsfehler: ' + JSON.stringify(error.response.data, null, 2))
    } else {
      alert('Fehler beim Hochladen: ' + error.message)
    }
  }
}

const updateLogo = async (logo) => {
  try {
    await axios.patch(`/logos/${logo.id}`, {
      title: logo.title,
      link: logo.link
    })
  } catch (error) {
    console.error('Error updating logo:', error)
  }
}

const toggleEventLogo = async (logo) => {
  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent?.id) {
    console.error('No event selected')
    return
  }
  
  try {
    await axios.post(`/logos/${logo.id}/toggle-event`, {
      event_id: currentEvent.id
    })
    await fetchLogos()
  } catch (error) {
    console.error('Error toggling logo event:', error)
  }
}

const confirmDeleteLogo = (logo) => {
  logoToDelete.value = logo
}

const cancelDeleteLogo = () => {
  logoToDelete.value = null
}

const deleteLogo = async () => {
  if (!logoToDelete.value) return
  
  try {
    await axios.delete(`/logos/${logoToDelete.value.id}`)
    await fetchLogos()
    logoToDelete.value = null
  } catch (error) {
    console.error('Error deleting logo:', error)
    alert('Fehler beim Löschen des Logos: ' + error.message)
  }
}

const handleFileChange = (e) => {
  const file = e.target.files?.[0]
  if (file) {
    uploadFile.value = file
  }
}

const openLogoPreview = (logo) => {
  selectedLogoForPreview.value = logo
}

const closeLogoPreview = () => {
  selectedLogoForPreview.value = null
}

const deleteMessage = computed(() => {
  if (!logoToDelete.value) return ''
  return `Möchten Sie das Logo "${logoToDelete.value.title || 'Unbenannt'}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})


onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  await fetchLogos()
})
</script>

<template>
  <div class="space-y-6 p-6">
    <!-- No event selected warning -->
    <div v-if="!selectedEvent && !eventStore.selectedEvent" class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5 text-yellow-600" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
        </svg>
        <span class="text-sm font-medium text-yellow-800">Kein Event ausgewählt</span>
      </div>
      <p class="text-xs text-yellow-700 mt-1">Bitte wählen Sie zuerst ein Event aus, um Logos hochzuladen.</p>
    </div>

    <!-- Upload -->
    <div v-else class="flex items-center space-x-4">
      <input type="file" @change="handleFileChange" ref="fileInput" accept="image/*" class="border rounded px-3 py-1"/>
      <button @click="uploadLogo" :disabled="!uploadFile" class="px-4 py-2 bg-blue-600 text-white rounded disabled:bg-gray-400 disabled:cursor-not-allowed">
        {{ uploadFile ? 'Upload ' + uploadFile.name : 'Upload' }}
      </button>
      <span v-if="uploadFile" class="text-sm text-gray-600">File selected: {{ uploadFile.name }}</span>
    </div>

    <!-- Logos -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
      <div v-for="logo in logos" :key="logo.id" class="border rounded p-4 shadow space-y-2 bg-white">
        <img 
          :src="`${logo.url}/${logo.path}`" 
          alt="Logo" 
          class="h-20 mx-auto cursor-pointer hover:opacity-80 transition-opacity"
          @click="openLogoPreview(logo)"
        />

        <input
            v-model="logo.title"
            @change="updateLogo(logo)"
            class="w-full px-3 py-1 border rounded"
            placeholder="Titel"
            type="text"
        />

        <input
            v-model="logo.link"
            @change="updateLogo(logo)"
            class="w-full px-3 py-1 border rounded"
            placeholder="Link"
            type="url"
        />

        <div class="flex items-center justify-between">
          <label class="flex items-center space-x-2">
            <span class="text-sm">Zugewiesen</span>
            <input
                type="checkbox"
                class="toggle-switch"
                :checked="logo.events.some(e => e.id === (selectedEvent?.id || eventStore.selectedEvent?.id))"
                @change="toggleEventLogo(logo)"
            />
          </label>

          <button @click="confirmDeleteLogo(logo)" class="text-red-600 hover:text-red-800">
            🗑️
          </button>
        </div>
      </div>
    </div>

    <!-- Logo Preview Modal -->
    <div 
      v-if="selectedLogoForPreview" 
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
      @click="closeLogoPreview"
    >
      <div class="bg-white rounded-lg p-6 max-w-4xl max-h-[90vh] overflow-auto" @click.stop>
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-semibold">{{ selectedLogoForPreview.title || 'Logo Preview' }}</h3>
          <button 
            @click="closeLogoPreview"
            class="text-gray-500 hover:text-gray-700 text-2xl"
          >
            ×
          </button>
        </div>
        
        <div class="flex justify-center">
          <img 
            :src="`${selectedLogoForPreview.url}/${selectedLogoForPreview.path}`" 
            :alt="selectedLogoForPreview.title || 'Logo'"
            class="max-w-full max-h-[70vh] object-contain"
          />
        </div>
        
        <div v-if="selectedLogoForPreview.link" class="mt-4 text-center">
          <a 
            :href="selectedLogoForPreview.link" 
            target="_blank" 
            rel="noopener noreferrer"
            class="text-blue-600 hover:text-blue-800 underline"
          >
            {{ selectedLogoForPreview.link }}
          </a>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <ConfirmationModal
      :show="!!logoToDelete"
      title="Logo löschen"
      :message="deleteMessage"
      type="danger"
      confirm-text="Löschen"
      cancel-text="Abbrechen"
      @confirm="deleteLogo"
      @cancel="cancelDeleteLogo"
    />
  </div>
</template>

<style scoped>
.toggle-switch {
  appearance: none;
  width: 40px;
  height: 20px;
  background: #ccc;
  border-radius: 9999px;
  position: relative;
  transition: background 0.3s;
}

.toggle-switch:checked {
  background: #4ade80;
}

.toggle-switch::after {
  content: "";
  position: absolute;
  top: 2px;
  left: 2px;
  width: 16px;
  height: 16px;
  background: white;
  border-radius: 9999px;
  transition: transform 0.3s;
}

.toggle-switch:checked::after {
  transform: translateX(20px);
}
</style>
