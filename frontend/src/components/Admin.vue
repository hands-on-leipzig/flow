<script setup>
import { ref, watch, onMounted, computed } from 'vue'
import { Menu, MenuButton, MenuItems, MenuItem } from '@headlessui/vue'
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

// Admin menu entries (shared by sidebar and mobile dropdown)
const adminMenuItems = [
  { key: 'statistics', label: 'Statistiken', icon: '📊', devOnly: false },
  { key: 'main-tables', label: 'Main Tables', icon: '📝', devOnly: true, devSuffix: '(nur Dev)' },
  { key: 'system-news', label: 'System News', icon: '📰', devOnly: false },
  { key: 'nowandnext', label: 'Now and Next', icon: '⏰', devOnly: false },
  { key: 'quality', label: 'Massentest', icon: '🧪', devOrLocalOnly: true, devSuffix: '(Dev oder lokal)' },
  { key: 'conditions', label: 'Parameter-Anzeige', icon: '📄', devOnly: false },
  { key: 'user-regional-partners', label: 'User-Regional Partner Relations', icon: '👥', devOnly: false },
  { key: 'sync', label: 'Draht Sync', icon: '🔁', devOnly: false },
  { key: 'external-api', label: 'External API', icon: '🔑', devOnly: false },
  { key: 'hilfsfunktionen', label: 'Hilfsfunktionen', icon: '🔧', devOnly: false },
]

const currentMenuLabel = computed(() => {
  const item = adminMenuItems.find(i => i.key === activeTab.value)
  return item ? `${item.icon} ${item.label}` : 'Admin'
})

// Tab available: devOrLocalOnly => Dev or local; devOnly => Dev only; else always
const isTabAvailable = (item) => {
  if (item.devOrLocalOnly) return isDevEnvironment.value || isLocal
  if (item.devOnly) return isDevEnvironment.value
  return true
}

const parameters = ref([])
const conditions = ref([])
const isDevEnvironment = ref(false)
const isLocal = typeof window !== 'undefined' && (window.location?.hostname === 'localhost' || window.location?.hostname === '127.0.0.1')
const seasons = ref([])
const selectedSeason = ref(null)
const regeneratingLinks = ref(false)
const cleaningLogos = ref(false)

// New refs for Contao update parameters and loading state
const contaoEventId = ref(null)
const contaoRound = ref('')
const updatingMatchSchedule = ref(false)

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

const updateMatchSchedule = async () => {
  updatingMatchSchedule.value = true
  try {
    const params = {}
    if (contaoEventId.value !== null && contaoEventId.value !== '') params.event = contaoEventId.value
    if (contaoRound.value && String(contaoRound.value).trim() !== '') params.round = contaoRound.value

    await axios.put('/contao/write-rounds', null, { params })
  } catch (error) {
    console.log('Fehler beim Aktualisieren des Spielplans: ' + (error.response?.data?.message || error.message))
  } finally {
    updatingMatchSchedule.value = false
  }
}

fetchParameters()
fetchConditions()
</script>

<template>
  <div class="flex flex-col lg:flex-row min-h-screen">
    <!-- Desktop sidebar (lg and up) -->
    <aside
      v-if="!(activeTab === 'statistics' && statisticsTableOnly)"
      class="hidden lg:block w-64 flex-shrink-0 bg-gray-100 border-r p-4 space-y-2"
    >
      <button
        v-for="item in adminMenuItems"
        :key="item.key"
        type="button"
        class="w-full text-left px-3 py-2 rounded text-sm"
        :class="{
          'bg-white font-semibold shadow': activeTab === item.key,
          'opacity-50 cursor-not-allowed bg-gray-100': (item.devOnly || item.devOrLocalOnly) && !isTabAvailable(item),
          'hover:bg-gray-200': isTabAvailable(item)
        }"
        :disabled="(item.devOnly || item.devOrLocalOnly) && !isTabAvailable(item)"
        :title="(item.devOnly || item.devOrLocalOnly) && !isTabAvailable(item) ? `${item.label} ist nur auf Dev oder lokal verfügbar` : ''"
        @click="isTabAvailable(item) && (activeTab = item.key)"
      >
        {{ item.icon }} {{ item.label }}
        <span v-if="(item.devOnly || item.devOrLocalOnly) && !isTabAvailable(item)" class="ml-2 text-xs text-gray-500">{{ item.devSuffix }}</span>
      </button>
    </aside>

    <!-- Mobile: dropdown bar (below main nav) + content -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Mobile admin menu dropdown (below nav bar) -->
      <div
        class="lg:hidden sticky z-40 bg-gray-100 border-b border-gray-200 shadow-sm"
        style="top: var(--app-nav-height, 52px);"
      >
        <Menu as="div" class="relative">
          <MenuButton
            class="flex items-center justify-between w-full px-4 py-3 text-left text-sm font-medium text-gray-700 hover:bg-gray-200/80 transition-colors"
          >
            <span>{{ currentMenuLabel }}</span>
            <svg class="w-5 h-5 text-gray-500 flex-shrink-0 ml-2" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
          </MenuButton>
          <MenuItems
            class="absolute left-0 right-0 z-50 mt-0 max-h-[min(70vh,400px)] overflow-y-auto bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none border-b border-gray-200"
          >
            <div class="py-1">
              <MenuItem
                v-for="item in adminMenuItems"
                :key="item.key"
                v-slot="{ active }"
              >
                <button
                  type="button"
                  :disabled="(item.devOnly || item.devOrLocalOnly) && !isTabAvailable(item)"
                  :class="[
                    'w-full text-left px-4 py-3 text-sm flex items-center gap-2',
                    active ? 'bg-blue-50' : '',
                    activeTab === item.key ? 'font-semibold bg-gray-100' : '',
                    (item.devOnly || item.devOrLocalOnly) && !isTabAvailable(item) ? 'opacity-50 cursor-not-allowed' : ''
                  ]"
                  @click="isTabAvailable(item) && (activeTab = item.key)"
                >
                  <span>{{ item.icon }} {{ item.label }}</span>
                  <span v-if="(item.devOnly || item.devOrLocalOnly) && !isTabAvailable(item)" class="text-xs text-gray-500">{{ item.devSuffix }}</span>
                  <span v-if="activeTab === item.key" class="ml-auto text-blue-600">✓</span>
                </button>
              </MenuItem>
            </div>
          </MenuItems>
        </Menu>
      </div>

      <!-- Content -->
    <div class="flex-1 p-4 lg:p-6 overflow-auto">


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

          <!-- Temporary for testing: Update match schedule from Contao -->
          <div class="bg-white rounded-lg shadow p-6 border border-gray-200">
            <h3 class="text-lg font-semibold mb-2">Teams in Finalrunden aus Contao laden</h3>
            <p class="text-gray-600 mb-4">
              Dieser Button ist hier, damit man die Funktion gut auf dev testen kann. Kommt bald wieder weg :)
            </p>
            <div class="flex items-center gap-2">
              <input v-model.number="contaoEventId" type="number" placeholder="Event ID" class="px-3 py-2 border rounded w-36" />
              <select v-model="contaoRound" class="px-3 py-2 border rounded w-36">
                <option value="af">AF</option>
                <option value="vf">VF</option>
                <option value="hf">HF</option>
                <option value="f">F</option>
              </select>
              <button
                class="px-6 py-2 rounded bg-blue-500 text-white hover:bg-blue-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="updatingMatchSchedule"
                @click="updateMatchSchedule"
              >
                {{ updatingMatchSchedule ? '⏳ Aktualisiere...' : '🔁 Spielplan aktualisieren' }}
              </button>
            </div>
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
  </div>
</template>

<style scoped>
button:focus {
  outline: none;
}
</style>
