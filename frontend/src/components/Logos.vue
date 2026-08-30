<script setup>
import {ref, onMounted, computed} from 'vue'
import axios from 'axios'
import draggable from 'vuedraggable'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import ItemCard from '@/components/molecules/ItemCard.vue'
import PanelSplitter from '@/components/atoms/PanelSplitter.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import {showGlassToast} from '@/composables/useGlassToast'
import {programLogoSrc, seasonLogoSrc} from '@/utils/images'
import {buildAushangRows} from '@/utils/logoPreviewLayout'

defineOptions({name: 'Logos'})

const logos = ref([])
const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const selectedEvent = computed(() => eventStore.selectedEvent)
const uploadFile = ref(null)
const fileInput = ref(null)
const selectedLogoForPreview = ref(null)
const logoToDelete = ref(null)
const isUploading = ref(false)
const isDragging = ref(false)
const leftWidth = ref(50)

const fetchLogos = async ({force = false} = {}) => {
  if (force) planCache.invalidateLogos()
  logos.value = await planCache.getLogos()
}

const uploadLogo = async () => {
  if (!uploadFile.value) return

  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent?.regional_partner) {
    showGlassToast('Bitte wähle zuerst ein Event aus, bevor du ein Logo hochlädst.', 'info')
    return
  }

  if (uploadFile.value.size > 2 * 1024 * 1024) {
    showGlassToast('Datei ist zu groß. Maximum: 2MB', 'error')
    return
  }

  if (!uploadFile.value.type.startsWith('image/') && !/\.svg$/i.test(uploadFile.value.name)) {
    showGlassToast('Datei muss ein Bild sein', 'error')
    return
  }

  isUploading.value = true

  try {
    const formData = new FormData()
    formData.append('file', uploadFile.value)
    formData.append('regional_partner', currentEvent.regional_partner)

    const response = await axios.post('/logos', formData)
    const uploadedLogo = response.data

    await fetchLogos({force: true})

    if (currentEvent?.id && uploadedLogo?.id) {
      try {
        await axios.post(`/logos/${uploadedLogo.id}/toggle-event`, {
          event_id: currentEvent.id
        })
        await fetchLogos({force: true})
      } catch (toggleError) {
        console.error('Error toggling logo after upload:', toggleError)
      }
    }

    uploadFile.value = null
    if (fileInput.value) {
      fileInput.value.value = ''
    }
  } catch (error) {
    console.error('Error uploading logo:', error)
    if (error.response?.status === 422) {
      showGlassToast('Validierungsfehler: ' + JSON.stringify(error.response.data, null, 2), 'error')
    } else {
      showGlassToast('Fehler beim Hochladen: ' + error.message, 'error')
    }
  } finally {
    isUploading.value = false
  }
}

const updateLogo = async (logo) => {
  try {
    let normalizedLink = logo.link
    if (normalizedLink && normalizedLink.trim()) {
      normalizedLink = normalizedLink.trim()
      if (!normalizedLink.match(/^https?:\/\//i)) {
        normalizedLink = 'https://' + normalizedLink
      }
      logo.link = normalizedLink
    }

    await axios.patch(`/logos/${logo.id}`, {
      title: logo.title,
      link: normalizedLink
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
    await fetchLogos({force: true})
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

const deleteLogoMessage = computed(() => {
  if (!logoToDelete.value) return ''
  const name = (logoToDelete.value.title || '').trim() || 'Unbenannt'
  return `„${name}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

const currentEventId = computed(() => selectedEvent.value?.id || eventStore.selectedEvent?.id || null)

function isLogoOnEvent(logo) {
  const eventId = currentEventId.value
  if (!eventId) return false
  return (logo.events || []).some(e => e.id === eventId)
}

async function commitUpload(file) {
  if (!file || isUploading.value) return
  uploadFile.value = file
  await uploadLogo()
  if (fileInput.value) fileInput.value.value = ''
}

function openComposerPicker() {
  if (isUploading.value) return
  fileInput.value?.click()
}

const deleteLogo = async () => {
  if (!logoToDelete.value) return

  try {
    await axios.delete(`/logos/${logoToDelete.value.id}`)
    await fetchLogos({force: true})
    logoToDelete.value = null
  } catch (error) {
    console.error('Error deleting logo:', error)
    const errorMessage = error.response?.data?.message || 'Ein Fehler ist aufgetreten.'
    const errorDetails = error.response?.data?.details || null

    if (errorDetails) {
      showGlassToast(`${errorMessage}\n\n${errorDetails}`, 'error')
    } else {
      showGlassToast(errorMessage, 'error')
    }
  }
}

/** Apply sort_order locally so the list updates before the API round-trip. */
const applyLocalSortOrder = (orderedAssigned, eventId) => {
  const orderById = new Map(orderedAssigned.map((logo, index) => [logo.id, index]))
  logos.value = logos.value.map((logo) => {
    const nextOrder = orderById.get(logo.id)
    if (nextOrder === undefined) return logo
    return {
      ...logo,
      events: logo.events.map((event) => {
        if (event.id !== eventId) return event
        return {
          ...event,
          pivot: {
            ...(event.pivot || {}),
            sort_order: nextOrder,
          },
        }
      }),
    }
  })
}

const handleFileChange = (e) => {
  const file = e.target.files?.[0]
  if (file) void commitUpload(file)
}

const handleComposerDrop = (e) => {
  e.preventDefault()
  if (isUploading.value) return
  const file = e.dataTransfer?.files?.[0]
  if (file) void commitUpload(file)
}

const openLogoPreview = (logo) => {
  selectedLogoForPreview.value = logo
}

const closeLogoPreview = () => {
  selectedLogoForPreview.value = null
}

const sortedLogos = computed(() => {
  const currentEvent = selectedEvent.value || eventStore.selectedEvent
  if (!currentEvent) {
    return logos.value
  }

  return [...logos.value].sort((a, b) => {
    const aEvent = a.events.find(e => e.id === currentEvent.id)
    const bEvent = b.events.find(e => e.id === currentEvent.id)

    if (aEvent && bEvent) {
      const aOrder = aEvent.pivot?.sort_order || 0
      const bOrder = bEvent.pivot?.sort_order || 0
      return aOrder - bOrder
    }

    if (aEvent && !bEvent) return -1
    if (!aEvent && bEvent) return 1

    return 0
  })
})

/** Full manage list (assigned first) — writable for vuedraggable. */
const manageLogosList = computed({
  get() {
    return sortedLogos.value
  },
  set(ordered) {
    const eventId = currentEventId.value
    if (!eventId) return
    const assignedInOrder = ordered.filter((logo) =>
        (logo.events || []).some((e) => e.id === eventId)
    )
    applyLocalSortOrder(assignedInOrder, eventId)
  },
})

/** Assigned logos only — drives right-side usage previews. */
const assignedLogosList = computed(() => {
  const eventId = currentEventId.value
  if (!eventId) return []
  return sortedLogos.value.filter((logo) =>
      (logo.events || []).some((e) => e.id === eventId)
  )
})

/** First assigned logo — Namensaufkleber organizer slot. */
const firstAssignedLogo = computed(() => assignedLogosList.value[0] ?? null)

const seasonName = computed(() =>
    selectedEvent.value?.season_rel?.name
    || selectedEvent.value?.seasonRel?.name
    || null
)

const nameTagSeasonLogoSrc = computed(() => seasonLogoSrc(seasonName.value, 'h'))
const nameTagProgramLogoSrc = computed(() => programLogoSrc('CHALLENGE', 'h'))

/** Fit Blade pixel sizes into the A4 landscape preview (~45% of PDF scale). */
const AUSHANG_PREVIEW_SCALE = 0.45

const aushangPreview = computed(() => {
  const built = buildAushangRows(assignedLogosList.value.map((logo) => logo.url))
  const scale = AUSHANG_PREVIEW_SCALE
  return {
    layout: {
      ...built.layout,
      logoSize: Math.round(built.layout.logoSize * scale),
      singleRowMinHeight: Math.round(built.layout.singleRowMinHeight * scale),
    },
    rows: built.rows.map((row) => ({
      ...row,
      minHeight: row.minHeight != null ? Math.round(row.minHeight * scale) : undefined,
    })),
  }
})

async function onManageReorderEnd() {
  isDragging.value = false
  const eventId = currentEventId.value
  if (!eventId) return

  const ordered = assignedLogosList.value
  planCache.invalidateLogos()

  try {
    await axios.post('/logos/update-sort-order', {
      event_id: eventId,
      logo_orders: ordered.map((logo, index) => ({
        logo_id: logo.id,
        sort_order: index,
      })),
    })
  } catch (error) {
    console.error('Error updating logo order:', error)
    showGlassToast('Fehler beim Aktualisieren der Reihenfolge: ' + error.message, 'error')
    await fetchLogos({force: true})
  }
}

onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  await fetchLogos()
})
</script>

<template>
  <div class="space-y-5">
    <div
        v-if="!selectedEvent && !eventStore.selectedEvent"
        class="glass-alert-warning flex items-start gap-2"
    >
      <i class="bi bi-exclamation-triangle-fill mt-0.5" aria-hidden="true"></i>
      <div>
        <div class="text-sm font-semibold">Kein Event ausgewählt</div>
        <p class="text-xs mt-0.5 opacity-90">Bitte wähle zuerst ein Event aus, um Logos hochzuladen.</p>
      </div>
    </div>

    <template v-else>
      <div class="logos-workspace">
        <div class="logos-workspace__split">
          <!-- Left: manage + sort -->
          <section
              class="logos-workspace__pane logos-workspace__left"
              :style="{ flex: `0 0 ${leftWidth}%` }"
          >
            <div class="glass-card liquid-surface-inner logos-workspace__card">
          <h2 class="glass-card__heading">Logos verwalten</h2>
          <p class="glass-settings-hint !mb-1">
            Logos werden in dieser Reihenfolge angezeigt.
          </p>
          <p class="glass-settings-hint !mb-4">
            Das erste Logo wird für die Namensaufkleber verwendet.
          </p>

          <div class="space-y-2">
            <div
                class="logo-composer"
                data-logo-composer
                @click="openComposerPicker"
                @dragover.prevent
                @drop="handleComposerDrop"
            >
              <ItemCard dashed>
                <template #title>
                  <span class="logo-composer__title">
                    {{ isUploading ? 'Lade hoch…' : 'Neues Logo' }}
                  </span>
                </template>
                <p class="item-card__hint">
                  PNG, JPG oder SVG · max. 2&nbsp;MB. Datei wählen oder hierher ziehen.
                </p>
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/*,.svg"
                    class="sr-only"
                    :disabled="isUploading"
                    @click.stop
                    @change="handleFileChange"
                />
              </ItemCard>
            </div>

            <draggable
                v-model="manageLogosList"
                class="logo-manage-list"
                item-key="id"
                filter="input, button, a, .no-drag"
                :prevent-on-filter="true"
                @start="isDragging = true"
                @end="onManageReorderEnd"
            >
              <template #item="{ element: logo }">
                <ItemCard
                    interactive
                    class="cursor-move"
                    :inactive="!isLogoOnEvent(logo)"
                    :class="{ 'opacity-55': isDragging }"
                >
                  <template #leading>
                    <div
                        class="text-[var(--color-text-subtle)] cursor-move select-none leading-none px-0.5"
                        title="Ziehen zum Sortieren"
                        aria-hidden="true"
                    >
                      ⋮⋮
                    </div>
                    <ToggleSwitch
                        :model-value="isLogoOnEvent(logo)"
                        :disabled="!currentEventId"
                        @update:modelValue="toggleEventLogo(logo)"
                    />
                  </template>
                  <template #title>
                    <input
                        v-model="logo.title"
                        type="text"
                        placeholder="Titel"
                        class="item-card__title glass-input glass-input--sm liquid-surface-control"
                        @change="updateLogo(logo)"
                    />
                  </template>
                  <template #trailing>
                    <IconDangerButton label="Logo löschen" @click="confirmDeleteLogo(logo)"/>
                  </template>

                  <div class="logo-card__body">
                    <input
                        v-model="logo.link"
                        type="url"
                        placeholder="https://domain.tld"
                        class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
                        @change="updateLogo(logo)"
                    />
                    <button
                        type="button"
                        class="logo-card__art no-drag"
                        title="Vorschau"
                        @click="openLogoPreview(logo)"
                    >
                      <img :src="logo.url" alt="" class="logo-card__img"/>
                    </button>
                  </div>
                </ItemCard>
              </template>
            </draggable>
          </div>
            </div>
          </section>

          <PanelSplitter
              v-model="leftWidth"
              class="hidden lg:flex logos-workspace__splitter"
              :min="32"
              :max="68"
              storage-key="flow-logos-split"
          />

          <!-- Right: usage previews -->
          <section class="logos-workspace__pane logos-workspace__right">
            <div class="glass-card liquid-surface-inner logos-workspace__card">
          <h2 class="glass-card__heading">Vorschau</h2>
          <p class="glass-settings-hint !mb-4">
            So erscheinen die aktiven Logos auf dem öffentlichen Plan und in den PDFs.
          </p>

          <div v-if="assignedLogosList.length === 0" class="text-sm text-[var(--color-text-subtle)] italic">
            Keine Logos aktiv. Aktiviere Logos links, um die Vorschau zu sehen.
          </div>

          <div v-else class="logo-preview-stack">
            <!-- Öffentlicher Plan — same glass chip footer as PublicEvent -->
            <section class="logo-preview-panel liquid-surface-inner">
              <h3 class="logo-preview-panel__title">Öffentlicher Plan</h3>
              <div class="logo-public-stage pe-page" aria-label="Öffentlicher-Plan-Vorschau">
                <div class="logo-public-stage__content">
                  <footer class="pe-logos glass-card liquid-surface-inner">
                    <div class="pe-logos__grid">
                      <div
                          v-for="logo in assignedLogosList"
                          :key="`public-${logo.id}`"
                          class="pe-logos__item"
                          :class="{ 'pe-logos__item--static': !logo.link }"
                      >
                        <img :alt="logo.title || 'Logo'" :src="logo.url"/>
                      </div>
                    </div>
                  </footer>
                </div>
              </div>
            </section>

            <!-- PDF footer strip — white page rectangle -->
            <section class="logo-preview-panel liquid-surface-inner">
              <h3 class="logo-preview-panel__title">Fußzeile (PDFs)</h3>
              <div class="logo-paper logo-paper--footer" aria-label="Fußzeilen-Vorschau">
                <div class="logo-footer-strip">
                  <div
                      v-for="logo in assignedLogosList"
                      :key="`footer-${logo.id}`"
                      class="logo-footer-strip__cell"
                  >
                    <img :src="logo.url" :alt="logo.title || 'Logo'" class="logo-footer-strip__img"/>
                  </div>
                </div>
              </div>
            </section>

            <!-- QR Aushang — DIN A4 landscape paper -->
            <section class="logo-preview-panel liquid-surface-inner">
              <h3 class="logo-preview-panel__title">Aushang mit QR-Code</h3>
              <div class="logo-paper logo-paper--a4-landscape" aria-label="Aushang-Logo-Vorschau">
                <div class="logo-aushang-stage">
                  <div class="logo-aushang-qr-placeholder" aria-hidden="true">
                    <div class="logo-aushang-qr-placeholder__box"/>
                    <span>Online Zeitplan</span>
                  </div>
                  <div class="logo-aushang-logos">
                    <div
                        v-for="(row, rowIndex) in aushangPreview.rows"
                        :key="`aushang-row-${rowIndex}`"
                        class="logo-aushang-row"
                        :class="{ 'logo-aushang-row--spaced': rowIndex > 0 }"
                        :style="row.minHeight ? { minHeight: `${row.minHeight}px` } : undefined"
                    >
                      <div
                          v-for="(cell, cellIndex) in row.cells"
                          :key="`aushang-cell-${rowIndex}-${cellIndex}`"
                          class="logo-aushang-cell"
                          :style="{ width: `${cell.widthPercent}%` }"
                      >
                        <img
                            v-if="cell.type === 'logo'"
                            :src="cell.url"
                            alt=""
                            class="logo-aushang-img"
                            :style="{
                              maxWidth: `${aushangPreview.layout.logoSize}px`,
                              maxHeight: `${aushangPreview.layout.logoSize}px`,
                            }"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <!-- Namensaufkleber — first organizer logo only -->
            <section class="logo-preview-panel liquid-surface-inner">
              <h3 class="logo-preview-panel__title">Namensaufkleber</h3>
              <p class="logo-preview-panel__hint">
                Nur das erste Logo (Programm + Saison + Veranstalter).
              </p>
              <div class="logo-nametag" aria-label="Namensaufkleber-Vorschau">
                <div class="logo-nametag__text">
                  <div class="logo-nametag__person">Max Mustermann</div>
                  <div class="logo-nametag__team">Team Beispiel</div>
                </div>
                <div class="logo-nametag__logos">
                  <img
                      :src="nameTagProgramLogoSrc"
                      alt="Programm"
                      class="logo-nametag__logo"
                  />
                  <img
                      :src="nameTagSeasonLogoSrc"
                      alt="Saison"
                      class="logo-nametag__logo"
                  />
                  <img
                      v-if="firstAssignedLogo"
                      :src="firstAssignedLogo.url"
                      :alt="firstAssignedLogo.title || 'Veranstalter'"
                      class="logo-nametag__logo"
                  />
                </div>
              </div>
            </section>
          </div>
            </div>
          </section>
        </div>
      </div>
    </template>

    <div
        v-if="selectedLogoForPreview"
        class="glass-scrim fixed inset-0 flex items-center justify-center z-50 p-4"
        @click="closeLogoPreview"
    >
      <div class="glass-modal glass-modal-lg max-w-4xl w-full max-h-[90vh] overflow-auto" @click.stop>
        <div class="glass-modal-header flex items-center justify-between gap-3">
          <h3 class="text-lg font-semibold">
            {{ selectedLogoForPreview.title || 'Logo-Vorschau' }}
          </h3>
          <button
              type="button"
              class="text-[var(--color-on-accent)]/80 hover:text-[var(--color-on-accent)] text-2xl leading-none"
              aria-label="Schließen"
              @click="closeLogoPreview"
          >
            ×
          </button>
        </div>

        <div class="flex justify-center p-4">
          <img
              :src="selectedLogoForPreview.url"
              :alt="selectedLogoForPreview.title || 'Logo'"
              class="max-w-full max-h-[70vh] object-contain"
          />
        </div>

        <div v-if="selectedLogoForPreview.link" class="px-4 pb-4 text-center">
          <a
              :href="selectedLogoForPreview.link"
              target="_blank"
              rel="noopener noreferrer"
              class="text-[var(--color-accent)] hover:text-[var(--color-accent-hover)] underline break-all"
          >
            {{ selectedLogoForPreview.link }}
          </a>
        </div>
      </div>
    </div>

    <ConfirmationModal
        :show="!!logoToDelete"
        title="Logo löschen"
        :message="deleteLogoMessage"
        type="danger"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        @confirm="deleteLogo"
        @cancel="cancelDeleteLogo"
    />
  </div>
</template>

<style scoped>
.logos-workspace {
  min-height: 0;
  min-width: 0;
}

.logos-workspace__split {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: 0;
  min-width: 0;
}

@media (min-width: 1024px) {
  .logos-workspace__split {
    flex-direction: row;
    gap: 0.55rem;
    align-items: stretch;
  }

  .logos-workspace__left {
    min-width: 0;
  }

  .logos-workspace__right {
    flex: 1 1 auto;
    min-width: 0;
  }

  .logos-workspace__card {
    height: 100%;
    overflow: auto;
  }
}

@media (max-width: 1023px) {
  .logos-workspace__left {
    flex: 1 1 auto !important;
  }
}

.logos-workspace__pane {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
}

.logo-composer {
  cursor: pointer;
}

.logo-composer__title {
  display: block;
  width: 100%;
  font-weight: 600;
  cursor: pointer;
}

.logo-manage-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.logo-card__body {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.logo-card__art {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  min-height: 6.5rem;
  height: 6.5rem;
  border: none;
  border-radius: calc(var(--radius) - 2px);
  background: var(--color-bg-muted);
  overflow: hidden;
  padding: 0.5rem;
  cursor: pointer;
}

.logo-card__art:hover {
  opacity: 0.9;
}

.logo-card__img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.logo-preview-stack {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.logo-preview-panel {
  padding: 0.85rem 1rem 1rem;
  border-radius: var(--radius-lg);
}

.logo-preview-panel__title {
  margin: 0 0 0.65rem;
  font-size: 0.8125rem;
  font-weight: 650;
  letter-spacing: 0.02em;
  color: var(--color-text);
}

.logo-preview-panel__hint {
  margin: -0.35rem 0 0.65rem;
  font-size: 0.75rem;
  font-style: italic;
  color: var(--color-text-muted);
  line-height: 1.35;
}

/* Public plan: orbit canvas + pe-logos chip footer (mirrors PublicEvent) */
.logo-public-stage {
  border-radius: var(--radius-lg);
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
}

.logo-public-stage__content {
  max-width: 72rem;
  margin: 0 auto;
  padding: 1rem 0.85rem 1.15rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.logo-public-stage :deep(.pe-logos) {
  margin-top: 0;
}

.logo-public-stage :deep(.pe-logos__grid) {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0.85rem 1.25rem;
}

.logo-public-stage :deep(.pe-logos__item) {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.65rem 0.85rem;
  border-radius: calc(var(--radius-lg, 1rem) - 2px);
  background: color-mix(in srgb, #ffffff 88%, transparent);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, transparent);
}

.logo-public-stage :deep(.pe-logos__item img) {
  height: 2.5rem;
  max-width: 7rem;
  object-fit: contain;
}

@media (min-width: 768px) {
  .logo-public-stage :deep(.pe-logos__item img) {
    height: 3rem;
    max-width: 8.5rem;
  }
}

/* White paper surfaces for PDF-like previews */
.logo-paper {
  background: #fff;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.08),
    0 2px 6px rgba(15, 23, 42, 0.04);
}

.logo-paper--footer {
  border-radius: 2px;
  padding: 0.35rem 0.5rem 0.45rem;
}

.logo-paper--a4-landscape {
  width: 100%;
  aspect-ratio: 297 / 210;
  border-radius: 2px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.logo-nametag {
  width: 15rem;
  height: 9.375rem;
  margin: 0 auto;
  padding: 0.7rem 0.75rem 0.55rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, transparent);
  border-radius: var(--radius);
  background: #fff;
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
}

.logo-nametag__person {
  font-size: 1.05rem;
  font-weight: 700;
  line-height: 1.2;
  color: #111;
}

.logo-nametag__team {
  margin-top: 0.2rem;
  font-size: 0.8rem;
  line-height: 1.25;
  color: #333;
}

.logo-nametag__logos {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 0.35rem;
  min-height: 2.1rem;
}

.logo-nametag__logo {
  max-width: 3.1rem;
  max-height: 2.1rem;
  width: auto;
  height: auto;
  object-fit: contain;
}

.logo-footer-strip {
  display: flex;
  width: 100%;
  align-items: stretch;
  background: #fff;
}

.logo-footer-strip__cell {
  flex: 1 1 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 8px 12px;
  min-height: 80px;
  height: 80px;
}

.logo-footer-strip__img {
  max-width: 80px;
  max-height: 80px;
  width: auto;
  height: auto;
  object-fit: contain;
}

.logo-aushang-stage {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 0.65rem 0.75rem 0.5rem;
  overflow: hidden;
}

.logo-aushang-qr-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0 0.15rem;
  color: #666;
  font-size: 0.75rem;
}

.logo-aushang-qr-placeholder__box {
  width: 4.75rem;
  height: 4.75rem;
  border-radius: 4px;
  border: 1px solid #ccc;
  background:
    repeating-linear-gradient(
      -45deg,
      #fff,
      #fff 4px,
      #f3f4f6 4px,
      #f3f4f6 8px
    );
}

.logo-aushang-logos {
  margin-top: auto;
  padding: 6px 8px 2px;
  border-top: 1px solid #eee;
}

.logo-aushang-row {
  display: flex;
  width: 100%;
  align-items: center;
}

.logo-aushang-row--spaced {
  margin-top: 6px;
}

.logo-aushang-cell {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 4px 8px;
  flex-shrink: 0;
  box-sizing: border-box;
}

.logo-aushang-img {
  width: auto;
  height: auto;
  object-fit: contain;
  display: inline-block;
}
</style>
