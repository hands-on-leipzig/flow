<script setup>
import {ref, onMounted} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'

const logos = ref([])
const eventStore = useEventStore()
const selectedEvent = eventStore.selectedEvent
const uploadFile = ref(null)

const fetchLogos = async () => {
  const {data} = await axios.get('/logos')
  logos.value = data
}

const uploadLogo = async () => {
  if (!uploadFile.value) return
  const formData = new FormData()
  formData.append('file', uploadFile.value)
  formData.append('regional_partner', selectedEvent.regional_partner)

  await axios.post('/logos', formData)
  await fetchLogos()
}

const updateLogo = async (logo) => {
  await axios.patch(`/logos/${logo.id}`, {
    title: logo.title,
    link: logo.link
  }).then(() => {
    uploadFile.value = null
    refs.fileInput.value = ''
  })
}

const toggleEventLogo = async (logo) => {
  await axios.post(`/logos/${logo.id}/toggle-event`, {
    event_id: selectedEvent.value.id
  })
  await fetchLogos()
}

const deleteLogo = async (logo) => {
  await axios.delete(`/logos/${logo.id}`)
  await fetchLogos()
}

const handleFileChange = (e) => {
  const file = e.target.files?.[0]
  if (file) {
    uploadFile.value = file
  }
}


onMounted(fetchLogos)
</script>

<template>
  <div class="space-y-6 p-6">
    <!-- Upload -->
    <div class="flex items-center space-x-4">
      <input type="file" @change="handleFileChange" ref="fileInput"/>
      <button @click="uploadLogo" class="px-4 py-2 bg-blue-600 text-white rounded">Upload</button>
    </div>

    <!-- Logos -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
      <div v-for="logo in logos" :key="logo.id" class="border rounded p-4 shadow space-y-2 bg-white">
        <img :src="`${logo.url}/${logo.path}`" alt="Logo" class="h-20 mx-auto"/>

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
                :checked="logo.events.some(e => e.id === selectedEvent?.id)"
                @change="toggleEventLogo(logo)"
            />
          </label>

          <button @click="deleteLogo(logo)" class="text-red-600 hover:text-red-800">
            🗑️
          </button>
        </div>
      </div>
    </div>
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
