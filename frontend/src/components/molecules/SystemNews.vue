<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps({
  isDevEnvironment: {
    type: Boolean,
    default: false
  }
})

const { isDevEnvironment } = props

const newsList = ref([])
const loading = ref(false)
const error = ref(null)

// Form state
const showCreateForm = ref(false)
const newNews = ref({
  title: '',
  text: '',
  link: ''
})

const loadNews = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get('/admin/news')
    newsList.value = response.data
  } catch (err) {
    console.error('Error loading news:', err)
    error.value = 'Fehler beim Laden der News'
  } finally {
    loading.value = false
  }
}

const createNews = async () => {
  if (!newNews.value.title.trim() || !newNews.value.text.trim()) {
    alert('Bitte fülle Titel und Text aus')
    return
  }

  try {
    await axios.post('/admin/news', {
      title: newNews.value.title.trim(),
      text: newNews.value.text.trim(),
      link: newNews.value.link.trim() || null
    })

    // Reset form
    newNews.value = { title: '', text: '', link: '' }
    showCreateForm.value = false

    // Reload list
    await loadNews()
  } catch (err) {
    console.error('Error creating news:', err)
    alert('Fehler beim Erstellen der News')
  }
}

const deleteNews = async (id, title) => {
  if (!confirm(`News "${title}" wirklich löschen?`)) {
    return
  }

  try {
    await axios.delete(`/admin/news/${id}`)
    await loadNews()
  } catch (err) {
    console.error('Error deleting news:', err)
    alert('Fehler beim Löschen der News')
  }
}

const cancelCreate = () => {
  newNews.value = { title: '', text: '', link: '' }
  showCreateForm.value = false
}

onMounted(() => {
  loadNews()
})
</script>

<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold text-gray-800">System News</h1>
      <button
        v-if="!showCreateForm"
        @click="isDevEnvironment && (showCreateForm = true)"
        :disabled="!isDevEnvironment"
        :title="!isDevEnvironment ? 'Neue News erstellen ist nur auf Dev verfügbar' : ''"
        class="font-semibold py-2 px-4 rounded-lg transition-colors duration-200"
        :class="isDevEnvironment 
          ? 'bg-blue-600 hover:bg-blue-700 text-white' 
          : 'bg-gray-300 text-gray-500 cursor-not-allowed opacity-50'"
      >
        ➕ Neue News erstellen
        <span v-if="!isDevEnvironment" class="ml-2 text-xs">(nur Dev)</span>
      </button>
    </div>

    <!-- Create Form -->
    <div v-if="showCreateForm" class="bg-white border border-gray-300 rounded-lg p-6 mb-6 shadow-sm">
      <h2 class="text-xl font-semibold text-gray-800 mb-4">Neue News erstellen</h2>
      
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Titel *
          </label>
          <input
            v-model="newNews.title"
            type="text"
            placeholder="z.B. Neue Funktion: Robot-Game Vorschau"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            maxlength="255"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Text *
          </label>
          <textarea
            v-model="newNews.text"
            placeholder="Beschreibung der Änderung oder Neuigkeit..."
            rows="6"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Link (optional)
          </label>
          <input
            v-model="newNews.link"
            type="url"
            placeholder="https://..."
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            maxlength="500"
          />
        </div>

        <div class="flex gap-3 pt-2">
          <button
            @click="createNews"
            class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-6 rounded-lg transition-colors duration-200"
          >
            Erstellen
          </button>
          <button
            @click="cancelCreate"
            class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-semibold py-2 px-6 rounded-lg transition-colors duration-200"
          >
            Abbrechen
          </button>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="text-center py-8 text-gray-500">
      Lade News...
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="bg-red-50 border border-red-300 text-red-700 px-4 py-3 rounded-lg">
      {{ error }}
    </div>

    <!-- News List -->
    <div v-else-if="newsList.length === 0" class="text-center py-8 text-gray-500">
      Keine News vorhanden
    </div>

    <div v-else class="space-y-4">
      <div
        v-for="news in newsList"
        :key="news.id"
        class="bg-white border border-gray-300 rounded-lg p-5 shadow-sm hover:shadow-md transition-shadow duration-200"
      >
        <!-- Header -->
        <div class="flex justify-between items-start mb-3">
          <div class="flex-1">
            <h3 class="text-lg font-semibold text-gray-800">{{ news.title }}</h3>
            <p class="text-sm text-gray-500 mt-1">
              {{ new Date(news.created_at).toLocaleDateString('de-DE', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
              }) }}
            </p>
          </div>
          <button
            @click="deleteNews(news.id, news.title)"
            class="text-red-600 hover:text-red-800 hover:bg-red-50 p-2 rounded-lg transition-colors duration-200"
            title="Löschen"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
          </button>
        </div>

        <!-- Content -->
        <p class="text-gray-700 whitespace-pre-wrap mb-3">{{ news.text }}</p>

        <!-- Link -->
        <div v-if="news.link" class="mb-3">
          <a 
            :href="news.link" 
            target="_blank" 
            rel="noopener noreferrer"
            class="text-blue-600 hover:text-blue-800 hover:underline text-sm inline-flex items-center"
          >
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
            {{ news.link }}
          </a>
        </div>

        <!-- Read Statistics -->
        <div class="flex items-center gap-2 text-sm">
          <span 
            class="px-3 py-1 rounded-full font-medium"
            :class="news.read_count === news.total_users 
              ? 'bg-green-100 text-green-800' 
              : 'bg-yellow-100 text-yellow-800'"
          >
            📊 {{ news.read_count }} von {{ news.total_users }} Usern gelesen
            <span v-if="news.total_users > 0" class="ml-1">
              ({{ Math.round((news.read_count / news.total_users) * 100) }}%)
            </span>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Additional styles if needed */
</style>

