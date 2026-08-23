<script setup>
import {ref, watch, onMounted, computed} from 'vue'
import {useRoute, useRouter} from 'vue-router'
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
import SharePointAdmin from '@/components/molecules/SharePointAdmin.vue'
import '@vueform/multiselect/themes/default.css'
import {showGlassToast} from '@/composables/useGlassToast'
import {ADMIN_DEFAULT_SECTION, isAdminSection} from '@/constants/adminNav'

defineOptions({name: 'Admin'})

const route = useRoute()
const router = useRouter()

const activeTab = computed(() => {
  const section = String(route.params.section || '')
  return isAdminSection(section) ? section : ADMIN_DEFAULT_SECTION
})

watch(
  () => route.params.section,
  (section) => {
    const key = String(section || '')
    if (!isAdminSection(key)) {
      void router.replace(`/plan/admin/${ADMIN_DEFAULT_SECTION}`)
    }
  },
  {immediate: true}
)

const parameters = ref([])
const conditions = ref([])
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

onMounted(async () => {
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
    showGlassToast('Regional Partner erfolgreich synchronisiert!', 'success')
  } catch (error) {
    showGlassToast('Fehler beim Synchronisieren: ' + (error.response?.data?.message || error.message), 'error')
  }
}

const syncDrahtEvents = async () => {
  if (!confirm('Möchtest du wirklich alle Events aus DRAHT synchronisieren?\n\nDies wird alle Events aus DRAHT in die Datenbank importieren.')) {
    return
  }

  try {
    await axios.get('/admin/draht/sync-draht-events/3')
    showGlassToast('Events erfolgreich synchronisiert!', 'success')
  } catch (error) {
    showGlassToast('Fehler beim Synchronisieren: ' + (error.response?.data?.message || error.message), 'error')
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
    showGlassToast('Bitte wähle eine Saison aus', 'info')
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
      showGlassToast(`✅ ${response.data.message}\n\nRegeneriert: ${response.data.regenerated}\nFehlgeschlagen: ${response.data.failed}\nGesamt: ${response.data.total}`, 'success')
    } else {
      showGlassToast('Fehler: ' + (response.data.message || response.data.error || 'Unbekannter Fehler'), 'error')
    }
  } catch (error) {
    showGlassToast('Fehler beim Regenerieren der Links: ' + (error.response?.data?.message || error.message), 'error')
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
        showGlassToast(message + `\n\nFehler:\n${response.data.errors.join('\n')}`, 'error')
      } else {
        showGlassToast(message, 'success')
      }
    } else {
      showGlassToast('Fehler: ' + (response.data.message || 'Unbekannter Fehler'), 'error')
    }
  } catch (error) {
    showGlassToast('Fehler bei der Logo-Bereinigung: ' + (error.response?.data?.message || error.message), 'error')
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

    await axios.put('/contao/write-rounds', null, {params})
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
  <div class="h-full min-h-0 overflow-auto p-4 lg:p-6">
        <div v-if="activeTab === 'conditions'">
          <h2 class="text-xl font-bold mb-4">Parameter-Anzeige-Bedingungen</h2>
          <div
              v-for="(cond, index) in conditions"
              :key="cond.id || index"
              class="flex items-center justify-center gap-4 px-3 py-2 rounded glass-row-item hover:bg-[var(--color-bg-hover)]"
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

            <button class="text-red-500 text-lg" @click="removeCondition(index)"
                    @update:modelValue="cond._dirty = true">
              🗑
            </button>
          </div>

          <button class="px-4 py-2 rounded bg-green-500 text-white" @click="addCondition">
            ➕ Bedingung hinzufügen
          </button>
        </div>


        <div v-if="activeTab === 'user-regional-partners'">
          <h2 class="text-xl font-bold mb-4">User-Regional Partner Relations</h2>
          <UserRegionalPartnerRelations/>
        </div>

        <div v-if="activeTab === 'sync'">
          <h2 class="text-xl font-bold mb-6">Sync aus DRAHT</h2>

          <div class="space-y-6">
            <!-- Regional Partner Sync -->
            <div class="glass-surface-lg border border-[var(--color-border)]">
              <h3 class="text-lg font-semibold mb-2">Regional Partner synchronisieren</h3>
              <p class="text-[var(--color-text-muted)] mb-4">
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
            <div class="glass-surface-lg border border-[var(--color-border)]">
              <h3 class="text-lg font-semibold mb-2">Events synchronisieren</h3>
              <p class="text-[var(--color-text-muted)] mb-4">
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
            <div class="glass-surface-lg border border-[var(--color-border)]">
              <h3 class="text-lg font-semibold mb-2">Teams in Finalrunden aus Contao laden</h3>
              <p class="text-[var(--color-text-muted)] mb-4">
                Dieser Button ist hier, damit man die Funktion gut auf dev testen kann. Kommt bald wieder weg :)
              </p>
              <div class="flex items-center gap-2">
                <input v-model.number="contaoEventId" type="number" placeholder="Event ID"
                       class="px-3 py-2 border rounded w-36"/>
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
          <quality/>
        </div>


        <div v-if="activeTab === 'main-tables'">
          <MainTablesAdmin/>
        </div>

        <div v-if="activeTab === 'system-news'">
          <SystemNews/>
        </div>

        <div v-if="activeTab === 'mparameter'">
          <h2 class="text-xl font-bold mb-4">Tabelle m_parameter (Legacy)</h2>
          <MParameter/>
        </div>

        <div v-if="activeTab === 'nowandnext'">
          <h2 class="text-xl font-bold mb-4">Was passiert gerade? Und was als nächstes?</h2>
          <NowAndNext/>
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
              <div
                  class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"></div>
              <span class="ml-2 text-sm font-medium text-[var(--color-text-muted)]">Nur Tabelle</span>
            </label>
          </div>
          <statistics :table-only="statisticsTableOnly"/>
        </div>

        <div v-if="activeTab === 'external-api'">
          <ExternalApiManagement/>
        </div>

        <div v-if="activeTab === 'sharepoint'">
          <SharePointAdmin/>
        </div>

        <div v-if="activeTab === 'hilfsfunktionen'">
          <h2 class="text-xl font-bold mb-6">Hilfsfunktionen</h2>

          <div class="space-y-6">
            <!-- Regenerate Public Links -->
            <div class="glass-surface-lg border border-[var(--color-border)]">
              <h3 class="text-lg font-semibold mb-2">Öffentliche Links regenerieren</h3>
              <p class="text-[var(--color-text-muted)] mb-4">
                Regeneriert alle öffentlichen Links und QR-Codes für alle Events einer ausgewählten Saison.
                Dies erstellt neue Links und QR-Codes und aktualisiert sie auch in DRAHT.
              </p>
              <div class="flex items-center gap-4">
                <select
                    v-model="selectedSeason"
                    class="px-4 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
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
            <div class="glass-surface-lg border border-[var(--color-border)]">
              <h3 class="text-lg font-semibold mb-2">Logo-Bereinigung</h3>
              <p class="text-[var(--color-text-muted)] mb-2">
                Diese Funktion bereinigt verwaiste Logos:
              </p>
              <ul class="list-disc list-inside mb-4 space-y-1 text-sm text-[var(--color-text-muted)]">
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
</template>

<style scoped>
button:focus {
  outline: none;
}
</style>
