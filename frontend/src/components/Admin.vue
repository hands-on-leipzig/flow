<script setup>
import {ref, watch, onMounted} from 'vue'
import axios from 'axios'
import Multiselect from '@vueform/multiselect'
import Quality from '@/components/molecules/Quality.vue'
import Statistics from '@/components/molecules/Statistics.vue'
import MParameter from '@/components/molecules/MParameter.vue'
import NowAndNext from '@/components/molecules/NowAndNext.vue'
import UserRegionalPartnerRelations from '@/components/molecules/UserRegionalPartnerRelations.vue'
import MainTablesAdmin from '@/components/molecules/MainTablesAdmin.vue'
import SystemNews from '@/components/molecules/SystemNews.vue'
import ExternalApiManagement from '@/components/molecules/ExternalApiManagement.vue'
import '@vueform/multiselect/themes/default.css'

const activeTab = ref('statistics')

const parameters = ref([])
const conditions = ref([])
const isDevEnvironment = ref(false)
const seasons = ref([])
const selectedSeason = ref(null)
const regeneratingLinks = ref(false)
const cleaningLogos = ref(false)

// Toggle for "Nur Tabelle" mode in Statistics
const statisticsTableOnly = ref(false)

// Check environment on mount
onMounted(async () => {
  try {
    const response = await axios.get('/environment')
    isDevEnvironment.value = response.data.is_dev || false
  } catch (error) {
    console.error('Failed to fetch environment:', error)
    // Default to false (not dev) if check fails
    isDevEnvironment.value = false
  }
  
  // Fetch seasons
  try {
    const seasonsResponse = await axios.get('/seasons')
    // Ensure we have an array (axios wraps responses, but this endpoint returns array directly)
    seasons.value = Array.isArray(seasonsResponse.data) ? seasonsResponse.data : []
    if (seasons.value.length === 0) {
      console.warn('No seasons found in API response:', seasonsResponse.data)
    }
  } catch (error) {
    console.error('Failed to fetch seasons:', error)
    if (error.response) {
      console.error('Response status:', error.response.status)
      console.error('Response data:', error.response.data)
    }
    seasons.value = []
  }
})

const syncDrahtRegions = async () => {
  if (!confirm('Möchtest du wirklich alle Regional Partner aus DRAHT synchronisieren?\n\nDies wird alle Regional Partner aus DRAHT in die Datenbank importieren.')) {
    return
  }
  
  try {
    await axios.get('/admin/draht/sync-draht-regions')
    alert('Regional Partner erfolgreich synchronisiert!')
  } catch (error) {
    alert('Fehler beim Synchronisieren: ' + (error.response?.data?.message || error.message))
  }
}

const syncDrahtEvents = async () => {
  if (!confirm('Möchtest du wirklich alle Events aus DRAHT synchronisieren?\n\nDies wird alle Events aus DRAHT in die Datenbank importieren.')) {
    return
  }
  
  try {
    await axios.get('/admin/draht/sync-draht-events/2')
    alert('Events erfolgreich synchronisiert!')
  } catch (error) {
    alert('Fehler beim Synchronisieren: ' + (error.response?.data?.message || error.message))
  }
}

const fetchParameters = async () => {
  const {data} = await axios.get('/parameter')
  parameters.value = data
  console.log(parameters.value)
}

const fetchConditions = async () => {
  const {data} = await axios.get('/parameter/condition')
  conditions.value = data
}

const addCondition = async () => {
  conditions.value.push({
    parameter: '',
    if_parameter: '',
    is: '=',
    value: '',
    action: 'hide',
    _new: true,
    _dirty: false,
  })
}

const removeCondition = async (index) => {
  const cond = conditions.value[index]
  if (cond.id) await axios.delete(`/parameter/condition/${cond.id}`)
  conditions.value.splice(index, 1)
}

watch(conditions, async (newVal) => {
  for (const cond of newVal) {
    if (cond._dirty) {
      if (cond._new) {
        const {data} = await axios.post('/parameter/condition', cond)
        Object.assign(cond, data)
        cond._new = false
      } else if (cond.id) {
        await axios.put(`/parameter/condition/${cond.id}`, cond)
      }
      cond._dirty = false
    }
  }
}, {deep: true})

const regenerateLinksForSeason = async () => {
  if (!selectedSeason.value) {
    alert('Bitte wähle eine Saison aus')
    return
  }
  
  const seasonName = seasons.value.find(s => s.id === selectedSeason.value)?.name || 'unbekannt'
  if (!confirm(`Möchtest du wirklich alle öffentlichen Links für die Saison "${seasonName}" regenerieren?\n\nDies wird für alle Events dieser Saison neue Links und QR-Codes erstellen.`)) {
    return
  }
  
  regeneratingLinks.value = true
  try {
    const response = await axios.post(`/publish/regenerate-season/${selectedSeason.value}`)
    if (response.data.success) {
      alert(`✅ ${response.data.message}\n\nRegeneriert: ${response.data.regenerated}\nFehlgeschlagen: ${response.data.failed}\nGesamt: ${response.data.total}`)
    } else {
      alert('Fehler: ' + (response.data.message || response.data.error || 'Unbekannter Fehler'))
    }
  } catch (error) {
    alert('Fehler beim Regenerieren der Links: ' + (error.response?.data?.message || error.message))
  } finally {
    regeneratingLinks.value = false
  }
}

const cleanupOrphanedLogos = async () => {
  if (!confirm('Möchtest du wirklich die Logo-Bereinigung durchführen?\n\nDies wird:\n- Datenbankeinträge ohne Datei löschen\n- Dateien ohne Datenbankeintrag löschen (nur hochgeladene Logos)\n\nDiese Aktion kann nicht rückgängig gemacht werden.')) {
    return
  }
  
  cleaningLogos.value = true
  try {
    const response = await axios.post('/admin/helpers/logos/cleanup-orphaned')
    if (response.data.success) {
      const message = `✅ Logo-Bereinigung abgeschlossen!\n\n` +
        `Gelöschte DB-Einträge: ${response.data.deleted_db_entries}\n` +
        `Gelöschte Dateien: ${response.data.deleted_files}`
      if (response.data.errors && response.data.errors.length > 0) {
        alert(message + `\n\nFehler:\n${response.data.errors.join('\n')}`)
      } else {
        alert(message)
      }
    } else {
      alert('Fehler: ' + (response.data.message || 'Unbekannter Fehler'))
    }
  } catch (error) {
    alert('Fehler bei der Logo-Bereinigung: ' + (error.response?.data?.message || error.message))
  } finally {
    cleaningLogos.value = false
  }
}


fetchParameters()
fetchConditions()
</script>

<template>
  <div class="flex h-full min-h-screen">
    <!-- Sidebar -->
    <div v-if="!(activeTab === 'statistics' && statisticsTableOnly)" class="w-64 bg-gray-100 border-r p-4 space-y-2">

      <button
        class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
        :class="{ 'bg-white font-semibold shadow': activeTab === 'statistics' }"
        @click="activeTab = 'statistics'"
      >
        📊 Statistiken
      </button>

            <button
        class="w-full text-left px-3 py-2 rounded"
        :class="{ 
          'bg-white font-semibold shadow': activeTab === 'main-tables',
          'opacity-50 cursor-not-allowed bg-gray-100': !isDevEnvironment,
          'hover:bg-gray-200': isDevEnvironment
        }"
        @click="isDevEnvironment && (activeTab = 'main-tables')"
        :disabled="!isDevEnvironment"
        :title="!isDevEnvironment ? 'Main Tables sind nur auf Dev verfügbar' : ''"
      >
        📝 Main Tables
        <span v-if="!isDevEnvironment" class="ml-2 text-xs text-gray-500">(nur Dev)</span>
      </button>

      <button
        class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
        :class="{ 'bg-white font-semibold shadow': activeTab === 'system-news' }"
        @click="activeTab = 'system-news'"
      >
        📰 System News
      </button>

      <button
        class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
        :class="{ 'bg-white font-semibold shadow': activeTab === 'nowandnext' }"
        @click="activeTab = 'nowandnext'"
      >
        ⏰ Now and Next
      </button>

      <button
          class="w-full text-left px-3 py-2 rounded"
          :class="{ 
            'bg-white font-semibold shadow': activeTab === 'quality',
            'opacity-50 cursor-not-allowed bg-gray-100': !isDevEnvironment,
            'hover:bg-gray-200': isDevEnvironment
          }"
          @click="isDevEnvironment && (activeTab = 'quality')"
          :disabled="!isDevEnvironment"
          :title="!isDevEnvironment ? 'Massentest ist nur auf Dev verfügbar' : ''"
        >
        🧪 Massentest
        <span v-if="!isDevEnvironment" class="ml-2 text-xs text-gray-500">(nur Dev)</span>
      </button>

      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'conditions' }"
          @click="activeTab = 'conditions'"
      >
        📄 Parameter-Anzeige
      </button>
      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'user-regional-partners' }"
          @click="activeTab = 'user-regional-partners'"
      >
        👥 User-Regional Partner Relations
      </button>

      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'sync' }"
          @click="activeTab = 'sync'"
      >
        🔁 Draht Sync
      </button>

      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'external-api' }"
          @click="activeTab = 'external-api'"
      >
        🔑 External API
      </button>

      <button
          class="w-full text-left px-3 py-2 rounded hover:bg-gray-200"
          :class="{ 'bg-white font-semibold shadow': activeTab === 'hilfsfunktionen' }"
          @click="activeTab = 'hilfsfunktionen'"
      >
        🔧 Hilfsfunktionen
      </button>







    </div>

    <div class="flex-1 p-6 overflow-auto">


      <div v-if="activeTab === 'conditions'">
        <h2 class="text-xl font-bold mb-4">Parameter-Anzeige-Bedingungen</h2>
        <div
            v-for="(cond, index) in conditions"
            :key="cond.id || index"
            class="flex items-center justify-center gap-4 px-3 py-2 rounded bg-white hover:bg-gray-200"
        >
          <Multiselect
              v-model="cond.parameter"
              :options="parameters"
              label="name"
              track-by="name"
              valueProp="id"
              searchable
              placeholder="Parameter"
              class="min-w-[12rem]"
              @update:modelValue="cond._dirty = true"
          />

          <select v-model="cond.action" class="border px-2 py-1 rounded" @change="cond._dirty = true">
            <option value="show">anzeigen</option>
            <option value="hide">verstecken</option>
            <option value="disable">ausgrauen</option>
          </select>

          <span>wenn</span>

          <Multiselect
              v-model="cond.if_parameter"
              :options="parameters"
              label="name"
              track-by="name"
              valueProp="id"
              searchable
              placeholder="Wenn-Parameter"
              class="min-w-[12rem]"
              @update:modelValue="cond._dirty = true"
          />

          <select v-model="cond.is" class="border px-2 py-1 rounded" @change="cond._dirty = true">
            <option value="=">=</option>
            <option value="<">&lt;</option>
            <option value=">">&gt;</option>
          </select>

          <input v-model="cond.value" class="border px-2 py-1 rounded" placeholder="Wert"
                 @change="cond._dirty = true"/>

          <button class="text-red-500 text-lg" @click="removeCondition(index)" @update:modelValue="cond._dirty = true">
            🗑
          </button>
        </div>

        <button class="px-4 py-2 rounded bg-green-500 text-white" @click="addCondition">
          ➕ Bedingung hinzufügen
        </button>
      </div>


      <div v-if="activeTab === 'user-regional-partners'">
        <h2 class="text-xl font-bold mb-4">User-Regional Partner Relations</h2>
        <UserRegionalPartnerRelations />
      </div>

      <div v-if="activeTab === 'sync'">
        <h2 class="text-xl font-bold mb-6">Sync aus DRAHT</h2>
        
        <div class="space-y-6">
          <!-- Regional Partner Sync -->
          <div class="bg-white rounded-lg shadow p-6 border border-gray-200">
            <h3 class="text-lg font-semibold mb-2">Regional Partner synchronisieren</h3>
            <p class="text-gray-600 mb-4">
              Synchronisiert alle Regional Partner aus DRAHT in die Datenbank. 
              Bestehende Regional Partner werden aktualisiert, neue werden hinzugefügt.
            </p>
            <button 
              class="px-6 py-2 rounded bg-blue-500 text-white hover:bg-blue-600 transition-colors" 
              @click="syncDrahtRegions"
            >
              🔁 Regional Partner synchronisieren
            </button>
          </div>
          
          <!-- Events Sync -->
          <div class="bg-white rounded-lg shadow p-6 border border-gray-200">
            <h3 class="text-lg font-semibold mb-2">Events synchronisieren</h3>
            <p class="text-gray-600 mb-4">
              Synchronisiert alle Events aus DRAHT in die Datenbank. 
              Bestehende Events werden aktualisiert, neue werden hinzugefügt.
            </p>
            <button 
              class="px-6 py-2 rounded bg-blue-500 text-white hover:bg-blue-600 transition-colors" 
              @click="syncDrahtEvents"
            >
              🔁 Events synchronisieren
            </button>
          </div>
          
        </div>
      </div>

      <div v-if="activeTab === 'quality'">
        <h2 class="text-xl font-bold mb-4">Massentest</h2>
        <quality />
      </div>


      <div v-if="activeTab === 'main-tables'">
        <MainTablesAdmin />
      </div>

      <div v-if="activeTab === 'system-news'">
        <SystemNews />
      </div>

      <div v-if="activeTab === 'mparameter'">
        <h2 class="text-xl font-bold mb-4">Tabelle m_parameter (Legacy)</h2>
        <MParameter />
      </div>
      
      <div v-if="activeTab === 'nowandnext'">
        <h2 class="text-xl font-bold mb-4">Was passiert gerade? Und was als nächstes?</h2>
        <NowAndNext />
      </div>

      <div v-if="activeTab === 'statistics'">
        <div class="flex items-center justify-between mb-4">
          <h2 class="text-xl font-bold">Statistiken</h2>
          <label class="relative inline-flex items-center cursor-pointer">
            <input 
              v-model="statisticsTableOnly" 
              type="checkbox" 
              class="sr-only peer"
            />
            <div class="w-11 h-6 bg-gray-300 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
            <div class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"></div>
            <span class="ml-2 text-sm font-medium text-gray-700">Nur Tabelle</span>
          </label>
        </div>
        <statistics :table-only="statisticsTableOnly" />
      </div>

      <div v-if="activeTab === 'external-api'">
        <ExternalApiManagement />
      </div>

      <div v-if="activeTab === 'hilfsfunktionen'">
        <h2 class="text-xl font-bold mb-6">Hilfsfunktionen</h2>
        
        <div class="space-y-6">
          <!-- Regenerate Public Links -->
          <div class="bg-white rounded-lg shadow p-6 border border-gray-200">
            <h3 class="text-lg font-semibold mb-2">Öffentliche Links regenerieren</h3>
            <p class="text-gray-600 mb-4">
              Regeneriert alle öffentlichen Links und QR-Codes für alle Events einer ausgewählten Saison.
              Dies erstellt neue Links und QR-Codes und aktualisiert sie auch in DRAHT.
            </p>
            <div class="flex items-center gap-4">
              <select 
                v-model="selectedSeason" 
                class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                :disabled="regeneratingLinks"
              >
                <option :value="null">-- Saison auswählen --</option>
                <option 
                  v-for="season in seasons" 
                  :key="season.id" 
                  :value="season.id"
                >
                  {{ season.name }} ({{ season.year }})
                </option>
              </select>
              <button 
                class="px-6 py-2 rounded bg-green-500 text-white hover:bg-green-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed" 
                @click="regenerateLinksForSeason"
                :disabled="!selectedSeason || regeneratingLinks"
              >
                {{ regeneratingLinks ? '⏳ Regeneriere...' : '🔗 Links regenerieren' }}
              </button>
            </div>
          </div>

          <!-- Logo Cleanup -->
          <div class="bg-white rounded-lg shadow p-6 border border-gray-200">
            <h3 class="text-lg font-semibold mb-2">Logo-Bereinigung</h3>
            <p class="text-gray-600 mb-2">
              Diese Funktion bereinigt verwaiste Logos:
            </p>
            <ul class="list-disc list-inside mb-4 space-y-1 text-sm text-gray-600">
              <li>Löscht Datenbankeinträge, deren Dateien nicht mehr auf dem Server existieren</li>
              <li>Löscht Dateien ohne zugehörigen Datenbankeintrag (nur hochgeladene Logos, keine System-Logos)</li>
            </ul>
            <button 
              class="px-6 py-2 rounded bg-red-500 text-white hover:bg-red-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed" 
              @click="cleanupOrphanedLogos"
              :disabled="cleaningLogos"
            >
              {{ cleaningLogos ? '⏳ Bereinige...' : '🧹 Logo-Bereinigung durchführen' }}
            </button>
          </div>
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
