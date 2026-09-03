<script setup lang="ts">

import draggable from "vuedraggable";
import SlideThumb from "@/components/SlideThumb.vue";
import {useEventStore} from "@/stores/event";
import {computed, nextTick, onMounted, ref, watch} from "vue";
import {Slideshow} from "@/models/slideshow";
import axios from "axios";
import {Slide} from "@/models/slide";
import SavingToast from "@/components/atoms/SavingToast.vue";
import ItemCard from "@/components/molecules/ItemCard.vue";
import ItemComposer from "@/components/molecules/ItemComposer.vue";
import ConfirmationModal from "@/components/molecules/ConfirmationModal.vue";
import IconDangerButton from "@/components/atoms/IconDangerButton.vue";
import PanelSplitter from "@/components/atoms/PanelSplitter.vue";
import {showGlassToast} from "@/composables/useGlassToast";

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const loading = ref(true);
const planId = ref<number | null>(null);
const slideshows = ref<Slideshow[]>([]);
const savingToast = ref(null);
const selectedSlideshowId = ref<number | null>(null);
const leftWidth = ref(28);

const selectedSlideshow = computed(() =>
    slideshows.value.find((s) => s.id === selectedSlideshowId.value) ?? null
);

const newSlideshowName = ref('');
const isCreating = ref(false);
const slideshowToDelete = ref<Slideshow | null>(null);
const composerRef = ref<{focusTitle?: () => void} | null>(null);

const canAddSlideshow = computed(() =>
    !loading.value
    && !isCreating.value
    && !!planId.value
    && !!event.value?.id
    && slideshows.value.length < 1
);

const deleteSlideshowMessage = computed(() => {
  if (!slideshowToDelete.value) return '';
  const name = (slideshowToDelete.value.name || '').trim() || 'Unbenannt';
  return `„${name}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`;
});

function selectSlideshow(slideshow: Slideshow) {
  selectedSlideshowId.value = slideshow.id;
}

function getSlideshowLink(slideshow: Slideshow) {
  // For now, use event-based link. In the future, this will be per-slideshow
  // e.g., `${window.location.origin}/carousel/${event.value?.id}/${slideshow.id}`
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
}

const previewIframeKey = ref(0);
const previewUrl = computed(() =>
  selectedSlideshow.value ? getSlideshowLink(selectedSlideshow.value) : ''
);
const previewHasSlides = computed(() => (selectedSlideshow.value?.slides?.length ?? 0) > 0);

watch(
  () => {
    const s = selectedSlideshow.value;
    if (!s) return null;
    return {
      id: s.id,
      transition: s.transition_time,
      slideIds: (s.slides ?? []).map((slide) => slide.id).join(','),
    };
  },
  () => {
    previewIframeKey.value += 1;
  },
);

function openSlideshowInNewWindow(slideshow: Slideshow) {
  const link = getSlideshowLink(slideshow);
  if (link) {
    window.open(link, '_blank', 'noopener,noreferrer');
  }
}

async function copySlideshowLink(slideshow: Slideshow) {
  const link = getSlideshowLink(slideshow);
  if (!link) return;
  try {
    await navigator.clipboard.writeText(link);
    showGlassToast('Link kopiert', 'success');
  } catch {
    showGlassToast('Link konnte nicht kopiert werden', 'error');
  }
}

function reloadLivePreview() {
  previewIframeKey.value += 1;
}

const slidesKey = ref(1);

const slideType = ref("");
const showSlideTypeModal = ref(false);
const currentSlideshow = ref<Slideshow | null>(null);
const creatingSlideType = ref<string | null>(null);
const isDragging = ref(false);
const draggedSlideId = ref<number | null>(null);
const publicPlanChoices = [
  {slide: 'PublicPlanSlideContent', label: 'Jetzt laufende Programmpunkte', icon: 'bi-clock'},
  {slide: 'PublicPlanNextSlideContent', label: 'Kommende Programmpunkte', icon: 'bi-calendar-event'},
  {slide: 'PublicPlanNextEventSlideContent', label: 'Nächster Programmpunkt (groß)', icon: 'bi-alphabet-uppercase'},
];
const teamsChoices = [
  {slide: 'TeamsMapSlideContent', label: 'Karte aller Teams', icon: 'bi-geo-alt'},
  {slide: 'TeamsTableSlideContent', label: 'Tabelle aller Teams', icon: 'bi-table'},
];

const slideTypes = [
  {slide: 'RobotGameSlideContent', label: 'Robot-Game-Ergebnisse', icon: 'bi-trophy'},
  {subModal: publicPlanChoices, label: 'Öffentlicher Zeitplan', icon: 'bi-calendar'},
  {slide: 'UrlSlideContent', label: 'Externer Inhalt (URL)', icon: 'bi-link-45deg'},
  {slide: 'FabricSlideContent', label: 'Eigener Inhalt', icon: 'bi-pencil-square'},
  {subModal: teamsChoices, label: 'Inhalte zu den Teams', icon: 'bi-people'}
];

const addSliceChoices = ref(null);

onMounted(loadSlideshows);
onMounted(fetchPlanId);

async function loadSlideshows() {
  if (!event.value?.id) return;
  const response = await axios.get(`/slideshow/${event.value?.id}`);
  if (response && response.data) {
    slideshows.value = (response.data as Slideshow[]).map((s) => ({
      ...s,
      slides: s.slides ?? [],
    }));
    if (
        selectedSlideshowId.value == null
        || !slideshows.value.some((s) => s.id === selectedSlideshowId.value)
    ) {
      selectedSlideshowId.value = slideshows.value[0]?.id ?? null;
    }
  }
  loading.value = false;
}

async function fetchPlanId() {
  if (!event.value?.id) return;
  try {
    const response = await axios.get(`/plans/event/${event.value.id}`)
    planId.value = response.data.id
  } catch (error) {
    console.error('Error fetching plan ID:', error)
  }
}

function onDragStart(event: any) {
  isDragging.value = true;
  // Get the dragged element's ID
  if (event.item) {
    const slideElement = event.item.querySelector('[data-slide-id]');
    if (slideElement) {
      draggedSlideId.value = parseInt(slideElement.getAttribute('data-slide-id') || '0');
    }
  }
}

async function onDragEnd(slideshow: Slideshow) {
  // Wait for animation to complete
  await new Promise(resolve => setTimeout(resolve, 250));

  isDragging.value = false;
  draggedSlideId.value = null;

  // Wait for DOM to settle
  await nextTick();

  // Get the current order from the slideshow (v-model should have updated it)
  const slideIds = slideshow.slides.map(slide => slide.id);

  console.log('Updating order:', slideIds);

  savingToast?.value?.show();

  try {
    const response = await axios.put(`/slideshow/${slideshow.id}/updateOrder`, {
      slide_ids: slideIds
    });
    console.log('Order updated successfully:', response.data);
  } catch (e) {
    console.error('Error updating order:', e);
    // Revert on error - reload slideshows
    await loadSlideshows();
  }
}

function deleteSlide(slideshow: Slideshow, slideId: number) {
  const index = slideshow.slides.findIndex(s => s.id === slideId);
  if (index !== -1) {
    slideshow.slides.splice(index, 1);
  }
}

async function addSlideshow() {
  const name = newSlideshowName.value.trim();
  if (!name || !canAddSlideshow.value) return;
  isCreating.value = true;
  try {
    const response = await axios.post(`/slideshow/${event.value?.id}`, {
      planId: planId.value,
      name,
    });

    const slideshow = response.data.slideshow;
    slideshow.slides = slideshow.slides ?? [];
    slideshows.value.push(slideshow);
    selectedSlideshowId.value = slideshow.id;
    newSlideshowName.value = '';
  } catch (e) {
    console.error(e);
  } finally {
    isCreating.value = false;
  }
}

function askDeleteSlideshow(slideshow: Slideshow) {
  slideshowToDelete.value = slideshow;
}

function cancelDeleteSlideshow() {
  slideshowToDelete.value = null;
}

async function confirmDeleteSlideshow() {
  const slideshow = slideshowToDelete.value;
  if (!slideshow) return;
  try {
    await axios.delete(`/slideshow/${slideshow.id}`);
    slideshows.value = slideshows.value.filter((s) => s.id !== slideshow.id);
    if (selectedSlideshowId.value === slideshow.id) {
      selectedSlideshowId.value = slideshows.value[0]?.id ?? null;
    }
    slideshowToDelete.value = null;
    await nextTick();
    composerRef.value?.focusTitle?.();
  } catch (e) {
    console.error(e);
  }
}

async function updateTransitionTime(slideshow: Slideshow) {
  savingToast?.value?.show();
  try {
    await axios.put(`/slideshow/${slideshow.id}`, {
      transition_time: slideshow.transition_time
    });
  } catch (e) {
    console.error(e);
  }
}

async function persistSlideshowName(slideshow: Slideshow) {
  const name = slideshow.name?.trim();
  if (!name) return;
  slideshow.name = name;
  savingToast?.value?.show();
  try {
    await axios.put(`/slideshow/${slideshow.id}`, {name});
  } catch (e) {
    console.error(e);
  }
}

function openSlideTypeModal(slideshow: Slideshow) {
  currentSlideshow.value = slideshow;
  addSliceChoices.value = slideTypes;
  showSlideTypeModal.value = true;
}

function closeSlideTypeModal() {
  if (creatingSlideType.value) return; // Prevent closing while creating
  showSlideTypeModal.value = false;
  currentSlideshow.value = null;
  slideType.value = "";
  addSliceChoices.value = null;
  creatingSlideType.value = null;
}

function changeSlideChoices(value) {
  addSliceChoices.value = value;
}

async function addSlide(selectedType: string) {
  if (!currentSlideshow.value || !selectedType || creatingSlideType.value) return;

  creatingSlideType.value = selectedType;
  const slideshow = currentSlideshow.value;
  slideType.value = selectedType;

  let newSlide = Slide.createNewSlide(selectedType);

  if (selectedType === 'PublicPlanSlideContent' || selectedType === 'PublicPlanNextSlideContent' || selectedType === 'PublicPlanNextEventSlideContent') {
    newSlide.content.planId = planId.value;
  }

  const content = JSON.stringify(newSlide.content.toJSON());
  newSlide = {...newSlide, content, order: slideshow.slides.length + 1};

  try {
    const response = await axios.put(`slideshow/${slideshow.id}/add`, newSlide);
    console.log(response.data.slide);
    slideshow.slides.push(response.data.slide);
    creatingSlideType.value = null;
    closeSlideTypeModal();
  } catch (e) {
    console.error(e);
    creatingSlideType.value = null;
  }
}
</script>

<template>
  <div class="digital-workspace">
    <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>
    <div class="digital-workspace__split">
      <section class="digital-workspace__left" :style="{ flex: `0 0 ${leftWidth}%` }">
        <div class="digital-workspace__pane">
          <div class="space-y-2">
            <ItemComposer
                ref="composerRef"
                v-model:title="newSlideshowName"
                :disabled="!canAddSlideshow"
                title-placeholder="Neue Slideshow"
                empty-hint="Name eintragen. Es werden Standardfolien angelegt."
                @commit="addSlideshow"
            />

            <ItemCard
                v-for="slideshow in slideshows"
                :key="slideshow.id"
                interactive
                :selected="selectedSlideshowId === slideshow.id"
                @click="selectSlideshow(slideshow)"
            >
              <template #title>
                <input
                    v-model="slideshow.name"
                    type="text"
                    class="item-card__title glass-input glass-input--sm liquid-surface-control"
                    @click.stop
                    @blur="persistSlideshowName(slideshow)"
                    @keydown.enter.prevent="persistSlideshowName(slideshow)"
                />
              </template>
              <template #trailing>
                <IconDangerButton label="Slideshow löschen" @click.stop="askDeleteSlideshow(slideshow)"/>
              </template>
              <p class="item-card__hint">
                {{ slideshow.slides?.length ?? 0 }}
                Folie{{ (slideshow.slides?.length ?? 0) !== 1 ? 'n' : '' }}
              </p>
            </ItemCard>
          </div>
        </div>

        <div class="digital-workspace__pane digital-workspace__pane--medien">
          <h2 class="glass-card__heading">Medien</h2>
          <p class="glass-settings-hint !mb-0">
            Hier kommt die Verwaltung für zusätzliche Fotos und Videos hin ... demnächst.
          </p>
        </div>
      </section>

      <PanelSplitter
          v-model="leftWidth"
          class="hidden md:flex digital-workspace__splitter"
          :min="22"
          :max="46"
          storage-key="flow-digital-split"
      />

      <section class="digital-workspace__right">
        <div class="digital-workspace__pane digital-workspace__pane--editor">
          <div v-if="loading" class="digital-workspace__empty">
            <svg class="animate-spin h-10 w-10 text-[var(--color-accent)] mx-auto mb-4" xmlns="http://www.w3.org/2000/svg" fill="none"
                 viewBox="0 0 24 24" aria-hidden="true">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            <p class="text-[var(--color-text-muted)] font-medium">Lädt...</p>
          </div>

          <template v-else-if="selectedSlideshow">
            <div class="digital-workspace__editor-bar">
              <h2 class="digital-workspace__editor-title">{{ selectedSlideshow.name }}</h2>
              <div class="digital-workspace__link-row">
                <a
                    v-if="previewUrl"
                    :href="previewUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="digital-workspace__link-anchor liquid-surface-inner"
                    :title="previewUrl"
                >
                  <span class="digital-workspace__link-text">{{ previewUrl }}</span>
                  <i class="bi bi-chevron-right text-[var(--color-text-subtle)] shrink-0" aria-hidden="true"/>
                </a>
                <div
                    v-else
                    class="digital-workspace__link-anchor liquid-surface-inner digital-workspace__link-anchor--empty"
                >
                  <span class="text-sm text-[var(--color-text-subtle)]">Kein Link verfügbar</span>
                </div>
                <div class="digital-workspace__link-actions shrink-0">
                  <button
                      type="button"
                      class="glass-btn-secondary digital-workspace__link-icon-btn"
                      aria-label="Link kopieren"
                      title="Link kopieren"
                      :disabled="!previewUrl"
                      @click="copySlideshowLink(selectedSlideshow)"
                  >
                    <i class="bi bi-clipboard" aria-hidden="true"/>
                  </button>
                </div>
              </div>
            </div>

            <div class="digital-workspace__editor-meta">
              <div class="digital-workspace__timing-box">
                <label class="digital-workspace__timing-label" for="slideshow-transition-time">
                  <i class="bi bi-clock" aria-hidden="true"/>
                  Anzeigezeit pro Folie
                </label>
                <div class="digital-workspace__timing-row">
                  <input
                      id="slideshow-transition-time"
                      type="number"
                      :min="1"
                      :max="60"
                      v-model.number="selectedSlideshow.transition_time"
                      @change="updateTransitionTime(selectedSlideshow)"
                      @blur="updateTransitionTime(selectedSlideshow)"
                      class="digital-workspace__timing-input"
                      aria-label="Anzeigezeit in Sekunden"
                  />
                  <span class="digital-workspace__timing-unit">Sekunden</span>
                </div>
                <div class="digital-workspace__timing-presets">
                  <button
                      v-for="preset in [5, 10, 15, 30, 60]"
                      :key="preset"
                      type="button"
                      class="digital-workspace__timing-preset"
                      :class="{'digital-workspace__timing-preset--active': selectedSlideshow.transition_time === preset}"
                      @click="selectedSlideshow.transition_time = preset; updateTransitionTime(selectedSlideshow)"
                  >
                    {{ preset }}s
                  </button>
                </div>
              </div>

              <div
                  class="digital-workspace__live-tile"
                  :class="{'digital-workspace__live-tile--empty': !previewHasSlides || !previewUrl}"
                  aria-label="Live-Vorschau der Slideshow"
              >
                <div class="digital-workspace__live-tile-bar">
                  <span class="digital-workspace__live-dot" aria-hidden="true"/>
                  <span class="digital-workspace__live-label">Live</span>
                  <button
                      v-if="previewUrl && previewHasSlides"
                      type="button"
                      class="digital-workspace__live-reload"
                      title="Vorschau neu laden"
                      @click="reloadLivePreview"
                  >
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"/>
                  </button>
                </div>
                <button
                    v-if="previewUrl && previewHasSlides"
                    type="button"
                    class="digital-workspace__live-stage"
                    title="Slideshow öffnen"
                    @click="openSlideshowInNewWindow(selectedSlideshow)"
                >
                  <div class="digital-workspace__live-scaler">
                    <iframe
                        :key="previewIframeKey"
                        class="digital-workspace__live-frame"
                        :src="previewUrl"
                        title="Live-Vorschau"
                        tabindex="-1"
                    />
                  </div>
                </button>
                <div v-else class="digital-workspace__live-empty">
                  Keine Folien
                </div>
              </div>
            </div>

            <div class="digital-workspace__editor-body">
            <!-- Slides Grid -->
            <div class="bg-gray-800 rounded-xl p-4 min-h-[200px]">
              <div class="flex flex-wrap gap-3" :class="{ 'dragging': isDragging }">
                <!-- New Slide Button -->
                <button
                    class="flex flex-col items-center justify-center w-56 h-52 m-2 border-2 border-dashed border-gray-500 rounded-xl hover:border-green-500 hover:bg-gray-700 transition-all cursor-pointer group flex-shrink-0"
                    @click="openSlideTypeModal(selectedSlideshow)">
                  <i class="bi bi-plus-circle text-4xl text-[var(--color-text-subtle)] group-hover:text-green-500 mb-2 transition-colors"></i>
                  <span
                      class="text-sm font-medium text-[var(--color-text-subtle)] group-hover:text-green-500 text-center">Neue Folie</span>
                </button>

                <!-- Empty State (only shown when no slides) -->
                <div v-if="!selectedSlideshow.slides?.length"
                     class="flex flex-col items-center justify-center py-12 text-[var(--color-text-subtle)] flex-1 min-w-[200px]">
                  <i class="bi bi-inbox text-4xl mb-3"></i>
                  <p class="text-sm">Noch keine Folien vorhanden</p>
                </div>

                <!-- Slides (draggable) - using contents to make items direct children of flex container -->
                <template v-if="selectedSlideshow.slides?.length">
                  <draggable
                      v-model="selectedSlideshow.slides"
                      :key="slidesKey"
                      class="contents"
                      group="slides"
                      item-key="id"
                      handle=".drag-handle"
                      ghost-class="drag-ghost"
                      chosen-class="drag-chosen"
                      drag-class="drag-dragging"
                      animation="200"
                      @start="onDragStart"
                      @end="onDragEnd(selectedSlideshow)">
                    <template #item="{ element }">
                      <SlideThumb
                          :slide="element"
                          :class="{ 'opacity-0': draggedSlideId === element.id && isDragging }"
                          @deleteSlide="deleteSlide(selectedSlideshow, element.id)"/>
                    </template>
                  </draggable>
                </template>
              </div>
            </div>
            </div>
          </template>

          <div v-else class="digital-workspace__empty">
            <i class="bi bi-slides text-4xl text-[var(--color-text-subtle)] mb-3" aria-hidden="true"></i>
            <p class="text-[var(--color-text-muted)] font-medium">Noch keine Slideshow vorhanden</p>
            <p class="text-sm text-[var(--color-text-subtle)] mt-1">
              Erstelle links eine Slideshow, um Folien zu bearbeiten.
            </p>
          </div>
        </div>
      </section>
    </div>

    <ConfirmationModal
        :show="!!slideshowToDelete"
        title="Slideshow löschen"
        :message="deleteSlideshowMessage"
        type="danger"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        @confirm="confirmDeleteSlideshow"
        @cancel="cancelDeleteSlideshow"
    />

    <!-- Slide Type Selection Modal -->
    <div
        v-if="showSlideTypeModal"
        class="glass-scrim fixed inset-0 flex items-center justify-center z-[100]"
        @click="closeSlideTypeModal"
    >
      <div class="bg-white rounded-lg p-6 max-w-2xl mx-4 w-full" @click.stop>
        <div class="flex items-center justify-between mb-6">
          <h3 class="text-lg font-semibold text-[var(--color-text)]">Folientyp wählen</h3>
          <button
              @click="closeSlideTypeModal"
              class="text-[var(--color-text-subtle)] hover:text-[var(--color-text-muted)] text-2xl leading-none"
          >
            ×
          </button>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <template
              v-for="type of addSliceChoices"
              :key="type.slide">
            <button
                @click="type.slide ? addSlide(type.slide) : changeSlideChoices(type.subModal)"
                :disabled="!!creatingSlideType"
                :class="[
                    'p-6 flex flex-col items-center justify-center border-2 rounded-lg transition-all relative',
                creatingSlideType === type.slide
                  ? 'border-blue-500 bg-blue-50 cursor-wait'
                  : creatingSlideType
                  ? 'border-[var(--color-border)] opacity-50 cursor-not-allowed'
                  : 'border-[var(--color-border)] hover:border-blue-500 hover:bg-blue-50 cursor-pointer group'
              ]"
            >
              <div v-if="creatingSlideType === type.slide"
                   class="absolute inset-0 flex items-center justify-center bg-blue-50 bg-opacity-75 rounded-lg">
                <svg class="animate-spin h-8 w-8 text-blue-600" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                </svg>
              </div>
              <i :class="[
              `bi ${type.icon} text-4xl mb-3 transition-colors`,
              creatingSlideType === type.slide
                ? 'text-blue-600'
                : 'text-[var(--color-text-muted)] group-hover:text-blue-600'
            ]"></i>
              <span :class="[
              'text-sm font-medium text-center',
              creatingSlideType === type.slide
                ? 'text-blue-700'
                : 'text-[var(--color-text-muted)] group-hover:text-blue-700'
            ]">{{ type.label }}</span>
            </button>
          </template>
        </div>

        <div class="mt-6 flex justify-end">
          <button
              @click="closeSlideTypeModal"
              :disabled="!!creatingSlideType"
              class="px-4 py-2 text-sm font-medium text-[var(--color-text-muted)] bg-white border border-[var(--color-border)] rounded-md hover:bg-[var(--color-bg-hover)] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Abbrechen
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.digital-workspace {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  min-width: 0;
  overflow: hidden;
}

.digital-workspace__split {
  display: flex;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  flex-direction: column;
  gap: 0.75rem;
}

@media (min-width: 768px) {
  .digital-workspace__split {
    flex-direction: row;
    gap: 0.55rem;
    align-items: stretch;
  }
}

.digital-workspace__left {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-width: 0;
  min-height: 0;
  height: 100%;
  overflow: hidden;
}

.digital-workspace__right {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 16rem;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.digital-workspace__pane {
  flex: 1 1 auto;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
  padding: 1.1rem 1.15rem 1.25rem;
  background: var(--glass-tab-surface, #ffffff);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 65%, transparent);
  border-radius: var(--radius-lg, 16px);
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.07),
    0 2px 6px rgba(15, 23, 42, 0.04);
}

.digital-workspace__pane--medien {
  flex: 0 0 auto;
}

.digital-workspace__pane--editor {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.digital-workspace__editor-bar {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.45rem;
  flex-shrink: 0;
  margin-bottom: 0.65rem;
}

.digital-workspace__editor-title {
  margin: 0;
  min-width: 0;
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text);
}

.digital-workspace__link-row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
}

.digital-workspace__link-anchor {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  flex: 1 1 auto;
  padding: 0.375rem 0.75rem;
  border-radius: 0.5rem;
  text-decoration: none;
  color: inherit;
  transition: background-color 0.15s ease;
}

.digital-workspace__link-anchor:hover {
  background: var(--color-bg-hover);
}

.digital-workspace__link-anchor--empty {
  cursor: default;
}

.digital-workspace__link-anchor--empty:hover {
  background: transparent;
}

.digital-workspace__link-text {
  min-width: 0;
  flex: 1 1 auto;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
}

.digital-workspace__link-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.digital-workspace__link-icon-btn {
  padding: 0.4rem 0.5rem !important;
  line-height: 1;
}

.digital-workspace__link-icon-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.digital-workspace__editor-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: stretch;
  gap: 0.75rem;
  flex-shrink: 0;
  margin-bottom: 0.75rem;
}

.digital-workspace__timing-box {
  flex: 1 1 12rem;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 0.75rem 0.85rem;
  border-radius: 0.5rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, transparent);
  background: var(--color-bg, #fff);
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
}

.digital-workspace__timing-label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.digital-workspace__timing-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.digital-workspace__timing-input {
  width: 4.5rem;
  padding: 0.4rem 0.55rem;
  border: 1px solid var(--color-border);
  border-radius: 0.375rem;
  font-size: 0.875rem;
  background: var(--color-bg);
  color: var(--color-text);
}

.digital-workspace__timing-input:focus {
  outline: none;
  border-color: var(--color-accent, #2563eb);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-accent, #2563eb) 25%, transparent);
}

.digital-workspace__timing-unit {
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--color-text-muted);
}

.digital-workspace__timing-presets {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.digital-workspace__timing-preset {
  padding: 0.25rem 0.55rem;
  font-size: 0.75rem;
  font-weight: 600;
  border-radius: 0.375rem;
  border: 1px solid transparent;
  background: var(--color-bg-muted);
  color: var(--color-text-muted);
  cursor: pointer;
}

.digital-workspace__timing-preset:hover {
  background: var(--color-bg-hover);
}

.digital-workspace__timing-preset--active {
  background: var(--color-accent, #2563eb);
  color: var(--color-on-accent, #fff);
}

.digital-workspace__live-tile {
  display: flex;
  flex-direction: column;
  flex: 0 0 13.75rem;
  width: 13.75rem;
  overflow: hidden;
  border-radius: 0.5rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, transparent);
  background: #0f172a;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
}

.digital-workspace__live-tile-bar {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.45rem;
  background: color-mix(in srgb, #0f172a 88%, #fff);
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  flex-shrink: 0;
}

.digital-workspace__live-dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: #22c55e;
  box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.25);
}

.digital-workspace__live-label {
  font-size: 0.6875rem;
  font-weight: 650;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.82);
}

.digital-workspace__live-reload {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.35rem;
  height: 1.35rem;
  border: none;
  border-radius: 0.3rem;
  background: transparent;
  color: rgba(255, 255, 255, 0.7);
  cursor: pointer;
}

.digital-workspace__live-reload:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #fff;
}

.digital-workspace__live-stage {
  position: relative;
  display: block;
  width: 100%;
  aspect-ratio: 16 / 9;
  padding: 0;
  border: none;
  overflow: hidden;
  cursor: pointer;
  background: #000;
  container-type: inline-size;
}

.digital-workspace__live-scaler {
  --live-frame-w: 1280;
  --live-frame-h: 720;
  position: absolute;
  top: 0;
  left: 0;
  width: calc(var(--live-frame-w) * 1px);
  height: calc(var(--live-frame-h) * 1px);
  transform: scale(calc(100cqw / var(--live-frame-w)));
  transform-origin: top left;
  pointer-events: none;
}

.digital-workspace__live-frame {
  width: 100%;
  height: 100%;
  border: none;
  display: block;
  background: #000;
}

.digital-workspace__live-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  aspect-ratio: 16 / 9;
  font-size: 0.75rem;
  color: rgba(255, 255, 255, 0.55);
  background: #111827;
}

.digital-workspace__live-tile--empty .digital-workspace__live-dot {
  background: #64748b;
  box-shadow: none;
}

.digital-workspace__editor-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
}

.digital-workspace__empty {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 2rem 1rem;
}

@media (min-width: 768px) {
  .digital-workspace__right {
    min-height: 0;
  }
}

@media (max-width: 767px) {
  .digital-workspace__left {
    flex: 1 1 auto !important;
    max-height: 42vh;
  }
}

.drag-ghost {
  opacity: 0.8 !important;
  transform: scale(0.95) !important;
  cursor: grabbing !important;
  border: 3px dashed #3b82f6 !important;
  background-color: rgba(59, 130, 246, 0.15) !important;
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2) !important;
  z-index: 1000 !important;
  pointer-events: none !important;
}

.drag-chosen {
  opacity: 0.5 !important;
  cursor: grabbing !important;
}

.drag-dragging {
  cursor: grabbing !important;
  opacity: 0.5 !important;
}

/* Prevent transitions on the dragged element itself */
.drag-dragging,
.drag-chosen {
  transition: none !important;
}
</style>
