<script setup>
import {ref, onMounted, computed} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'
import {useAdminEnvironment} from '@/composables/useAdminEnvironment'
import {isDevOrLocalToolAvailable} from '@/constants/adminNav'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'

defineOptions({name: 'AdminWartung'})

const {isLocal, ensureLoaded: ensureAdminEnvironment} = useAdminEnvironment()

const seasons = ref([])
const selectedSeason = ref(null)
const regeneratingLinks = ref(false)
const cleaningLogos = ref(false)
const rebuildingCalendar = ref(false)
const syncingRegions = ref(false)
const syncingEvents = ref(false)
const updatingMatchSchedule = ref(false)
const contaoEventId = ref(null)
const contaoRound = ref('af')

const confirmModal = ref({
  show: false,
  title: '',
  message: '',
  type: 'info',
  confirmText: 'Bestätigen',
  action: null,
})

const showDevTools = computed(() => isDevOrLocalToolAvailable(isLocal))

onMounted(async () => {
  void ensureAdminEnvironment()
  try {
    const seasonsResponse = await axios.get('/seasons')
    seasons.value = Array.isArray(seasonsResponse.data) ? seasonsResponse.data : []
  } catch (error) {
    console.error('Failed to fetch seasons:', error)
    seasons.value = []
  }
})

function openConfirm({title, message, type = 'info', confirmText = 'Bestätigen', action}) {
  confirmModal.value = {show: true, title, message, type, confirmText, action}
}

function closeConfirm() {
  confirmModal.value = {...confirmModal.value, show: false, action: null}
}

async function onConfirm() {
  const action = confirmModal.value.action
  closeConfirm()
  if (typeof action === 'function') await action()
}

async function runSyncRegions() {
  syncingRegions.value = true
  try {
    await axios.get('/admin/draht/sync-draht-regions')
    showGlassToast('Regional Partner erfolgreich synchronisiert!', 'success')
  } catch (error) {
    showGlassToast('Fehler beim Synchronisieren: ' + (error.response?.data?.message || error.message), 'error')
  } finally {
    syncingRegions.value = false
  }
}

async function runSyncEvents() {
  syncingEvents.value = true
  try {
    await axios.get('/admin/draht/sync-draht-events/3')
    showGlassToast('Events erfolgreich synchronisiert!', 'success')
  } catch (error) {
    showGlassToast('Fehler beim Synchronisieren: ' + (error.response?.data?.message || error.message), 'error')
  } finally {
    syncingEvents.value = false
  }
}

async function runRegenerateLinks() {
  if (!selectedSeason.value) {
    showGlassToast('Bitte wähle eine Saison aus', 'info')
    return
  }
  regeneratingLinks.value = true
  try {
    const response = await axios.post(`/publish/regenerate-season/${selectedSeason.value}`)
    if (response.data.success) {
      showGlassToast(
        `${response.data.message}\n\nRegeneriert: ${response.data.regenerated}\nFehlgeschlagen: ${response.data.failed}\nGesamt: ${response.data.total}`,
        'success',
      )
    } else {
      showGlassToast('Fehler: ' + (response.data.message || response.data.error || 'Unbekannter Fehler'), 'error')
    }
  } catch (error) {
    showGlassToast('Fehler beim Regenerieren der Links: ' + (error.response?.data?.message || error.message), 'error')
  } finally {
    regeneratingLinks.value = false
  }
}

async function runCleanupLogos() {
  cleaningLogos.value = true
  try {
    const response = await axios.post('/admin/helpers/logos/cleanup-orphaned')
    if (response.data.success) {
      let message =
        `Logo-Bereinigung abgeschlossen.\n\n` +
        `Gelöschte DB-Einträge: ${response.data.deleted_db_entries}\n` +
        `Gelöschte Dateien: ${response.data.deleted_files}`
      if (response.data.errors?.length) {
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

async function runRebuildCalendar() {
  rebuildingCalendar.value = true
  try {
    const response = await axios.post('/admin/calendar/rebuild', null, {timeout: 600000})
    const data = response.data
    if (data.success) {
      const message =
        `Kalender aktualisiert.\n\nNeu gebaut: ${data.rebuilt}\nBehalten (DRAHT fehlgeschlagen): ${data.kept}\nÜbersprungen: ${data.skipped}\nEntfernt: ${data.removed}\nFehlgeschlagen: ${data.failed}\nGesamt im Fenster: ${data.total}`
      if (data.errors?.length) {
        showGlassToast(message + `\n\nFehler:\n${data.errors.join('\n')}`, 'error')
      } else {
        showGlassToast(message, 'success')
      }
    } else {
      showGlassToast('Fehler: ' + (data.message || data.error || 'Unbekannter Fehler'), 'error')
    }
  } catch (error) {
    showGlassToast('Fehler beim Aktualisieren der Kalender-Einträge: ' + (error.response?.data?.message || error.message), 'error')
  } finally {
    rebuildingCalendar.value = false
  }
}

async function runUpdateMatchSchedule() {
  updatingMatchSchedule.value = true
  try {
    const params = {}
    if (contaoEventId.value !== null && contaoEventId.value !== '') params.event = contaoEventId.value
    if (contaoRound.value && String(contaoRound.value).trim() !== '') params.round = contaoRound.value
    await axios.put('/contao/write-rounds', null, {params})
    showGlassToast('Spielplan aktualisiert.', 'success')
  } catch (error) {
    showGlassToast('Fehler beim Aktualisieren des Spielplans: ' + (error.response?.data?.message || error.message), 'error')
  } finally {
    updatingMatchSchedule.value = false
  }
}

function confirmSyncRegions() {
  openConfirm({
    title: 'Regional Partner synchronisieren?',
    message: 'Alle Regional Partner aus DRAHT werden in die Datenbank importiert bzw. aktualisiert.',
    type: 'info',
    confirmText: 'Synchronisieren',
    action: runSyncRegions,
  })
}

function confirmSyncEvents() {
  openConfirm({
    title: 'Events synchronisieren?',
    message: 'Alle Events aus DRAHT werden in die Datenbank importiert bzw. aktualisiert.',
    type: 'info',
    confirmText: 'Synchronisieren',
    action: runSyncEvents,
  })
}

function confirmRegenerateLinks() {
  if (!selectedSeason.value) {
    showGlassToast('Bitte wähle eine Saison aus', 'info')
    return
  }
  const seasonName = seasons.value.find((s) => s.id === selectedSeason.value)?.name || 'unbekannt'
  openConfirm({
    title: 'Links regenerieren?',
    message: `Alle öffentlichen Links und QR-Codes für die Saison „${seasonName}“ werden neu erstellt und in DRAHT aktualisiert.`,
    type: 'warning',
    confirmText: 'Regenerieren',
    action: runRegenerateLinks,
  })
}

function confirmCleanupLogos() {
  openConfirm({
    title: 'Logo-Bereinigung durchführen?',
    message:
      'Löscht Datenbankeinträge ohne Datei und Dateien ohne Datenbankeintrag (nur hochgeladene Logos). Diese Aktion kann nicht rückgängig gemacht werden.',
    type: 'danger',
    confirmText: 'Bereinigen',
    action: runCleanupLogos,
  })
}

function confirmRebuildCalendar() {
  openConfirm({
    title: 'Kalender-Einträge aktualisieren?',
    message:
      'Schreibt event_calendar für veröffentlichte Events (Zukunft plus 90 Tage zurück) neu und entfernt Einträge außerhalb dieses Fensters. DRAHT wird je Event aufgerufen. Das kann einige Minuten dauern.',
    type: 'warning',
    confirmText: 'Aktualisieren',
    action: runRebuildCalendar,
  })
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-xl font-bold mb-2">Wartung</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Einmal-Aktionen für Sync, Veröffentlichungslinks und Datenpflege.
      </p>
    </div>

    <div class="wartung-grid">
      <div class="wartung-tile glass-card liquid-surface-inner">
        <h3 class="glass-card__title !mb-0">Regional Partner synchronisieren</h3>
        <p class="wartung-tile__body text-sm text-[var(--color-text-muted)]">
          Synchronisiert alle Regional Partner aus DRAHT. Bestehende werden aktualisiert, neue hinzugefügt.
        </p>
        <button
            type="button"
            class="wartung-tile__btn glass-btn-accent"
            :disabled="syncingRegions"
            @click="confirmSyncRegions"
        >
          <i class="bi bi-arrow-repeat" aria-hidden="true"/>
          {{ syncingRegions ? 'Synchronisiere…' : 'Regional Partner synchronisieren' }}
        </button>
      </div>

      <div class="wartung-tile glass-card liquid-surface-inner">
        <h3 class="glass-card__title !mb-0">Events synchronisieren</h3>
        <p class="wartung-tile__body text-sm text-[var(--color-text-muted)]">
          Synchronisiert alle Events aus DRAHT. Bestehende werden aktualisiert, neue hinzugefügt.
        </p>
        <button
            type="button"
            class="wartung-tile__btn glass-btn-accent"
            :disabled="syncingEvents"
            @click="confirmSyncEvents"
        >
          <i class="bi bi-arrow-repeat" aria-hidden="true"/>
          {{ syncingEvents ? 'Synchronisiere…' : 'Events synchronisieren' }}
        </button>
      </div>

      <div class="wartung-tile glass-card liquid-surface-inner">
        <h3 class="glass-card__title !mb-0">Öffentliche Links regenerieren</h3>
        <p class="wartung-tile__body text-sm text-[var(--color-text-muted)]">
          Regeneriert alle öffentlichen Links und QR-Codes für alle Events einer Saison und aktualisiert sie in DRAHT.
        </p>
        <div class="wartung-tile__controls">
          <select
              v-model="selectedSeason"
              class="glass-input liquid-surface-control !px-3 !py-2 w-full"
              :disabled="regeneratingLinks"
          >
            <option :value="null">— Saison auswählen —</option>
            <option
                v-for="season in seasons"
                :key="season.id"
                :value="season.id"
            >
              {{ season.name }} ({{ season.year }})
            </option>
          </select>
        </div>
        <button
            type="button"
            class="wartung-tile__btn glass-btn-accent"
            :disabled="!selectedSeason || regeneratingLinks"
            @click="confirmRegenerateLinks"
        >
          <i class="bi bi-link-45deg" aria-hidden="true"/>
          {{ regeneratingLinks ? 'Regeneriere…' : 'Links regenerieren' }}
        </button>
      </div>

      <div class="wartung-tile glass-card liquid-surface-inner">
        <h3 class="glass-card__title !mb-0">Logo-Bereinigung</h3>
        <p class="wartung-tile__body text-sm text-[var(--color-text-muted)]">
          Löscht Datenbankeinträge ohne Datei und Dateien ohne Datenbankeintrag (nur hochgeladene Logos).
        </p>
        <button
            type="button"
            class="wartung-tile__btn glass-btn-accent"
            :disabled="cleaningLogos"
            @click="confirmCleanupLogos"
        >
          <i class="bi bi-trash3" aria-hidden="true"/>
          {{ cleaningLogos ? 'Bereinige…' : 'Logo-Bereinigung durchführen' }}
        </button>
      </div>

      <div class="wartung-tile glass-card liquid-surface-inner">
        <h3 class="glass-card__title !mb-0">Kalender-Einträge aktualisieren</h3>
        <p class="wartung-tile__body text-sm text-[var(--color-text-muted)]">
          Schreibt die gespeicherten iCalendar-Einträge für veröffentlichte Events im Feed-Fenster neu
          (Zukunft plus 90 Tage zurück). Die Vorschau unter Kalender-Feeds zeigt das Ergebnis sofort.
        </p>
        <button
            type="button"
            class="wartung-tile__btn glass-btn-accent"
            :disabled="rebuildingCalendar"
            @click="confirmRebuildCalendar"
        >
          <i class="bi bi-calendar3" aria-hidden="true"/>
          {{ rebuildingCalendar ? 'Aktualisiere…' : 'Kalender aktualisieren' }}
        </button>
      </div>

      <div v-if="showDevTools" class="wartung-tile glass-card liquid-surface-inner">
        <h3 class="glass-card__title !mb-0">Teams in Finalrunden aus Contao laden</h3>
        <p class="wartung-tile__body text-sm text-[var(--color-text-muted)]">
          Lädt Finalrunden-Teams aus Contao in den Spielplan.
        </p>
        <div class="wartung-tile__controls wartung-tile__controls--row">
          <input
              v-model.number="contaoEventId"
              type="number"
              placeholder="Event ID"
              class="glass-input liquid-surface-control !px-3 !py-2 min-w-0 flex-1"
          />
          <select
              v-model="contaoRound"
              class="glass-input liquid-surface-control !px-3 !py-2 w-28 shrink-0"
          >
            <option value="af">AF</option>
            <option value="vf">VF</option>
            <option value="hf">HF</option>
            <option value="f">F</option>
          </select>
        </div>
        <button
            type="button"
            class="wartung-tile__btn glass-btn-accent"
            :disabled="updatingMatchSchedule"
            @click="runUpdateMatchSchedule"
        >
          <i class="bi bi-arrow-repeat" aria-hidden="true"/>
          {{ updatingMatchSchedule ? 'Aktualisiere…' : 'Spielplan aktualisieren' }}
        </button>
      </div>
    </div>

    <ConfirmationModal
        :show="confirmModal.show"
        :title="confirmModal.title"
        :message="confirmModal.message"
        :type="confirmModal.type"
        :confirm-text="confirmModal.confirmText"
        @confirm="onConfirm"
        @cancel="closeConfirm"
    />
  </div>
</template>

<style scoped>
.wartung-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(17.5rem, 1fr));
  gap: 1rem;
  align-items: stretch;
}

.wartung-tile {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: 16.5rem;
  height: 100%;
  box-sizing: border-box;
}

.wartung-tile__body {
  flex: 1 1 auto;
  margin: 0;
  line-height: 1.45;
}

.wartung-tile__controls {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.wartung-tile__controls--row {
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
}

.wartung-tile__btn {
  display: inline-flex;
  align-items: center;
  margin-top: auto;
  width: 100%;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.625rem 1rem;
  font-size: 0.875rem;
}

.wartung-tile__btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
