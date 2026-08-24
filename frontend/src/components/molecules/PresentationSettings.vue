<script setup lang="ts">

import draggable from "vuedraggable";
import SlideThumb from "@/components/SlideThumb.vue";
import {useEventStore} from "@/stores/event";
import {computed, nextTick, onMounted, ref} from "vue";
import {Slideshow} from "@/models/slideshow";
import axios from "axios";
import {Slide} from "@/models/slide";
import SavingToast from "@/components/atoms/SavingToast.vue";
import ItemCard from "@/components/molecules/ItemCard.vue";
import PanelSplitter from "@/components/atoms/PanelSplitter.vue";

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

const canAddSlideshow = computed(() =>
    !loading.value && !!planId.value && !!event.value?.id && slideshows.value.length < 1
);

function selectSlideshow(slideshow: Slideshow) {
  selectedSlideshowId.value = slideshow.id;
}

function getSlideshowLink(slideshow: Slideshow) {
  // For now, use event-based link. In the future, this will be per-slideshow
  // e.g., `${window.location.origin}/carousel/${event.value?.id}/${slideshow.id}`
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
}

function openSlideshowInNewWindow(slideshow: Slideshow) {
  const link = getSlideshowLink(slideshow);
  if (link) {
    window.open(link, '_blank', 'noopener,noreferrer');
  }
}

function copySlideshowLink(slideshow: Slideshow) {
  const link = getSlideshowLink(slideshow);
  if (link) {
    copyUrl(link);
    copiedSlideshowId.value = slideshow.id;
    setTimeout(() => {
      copiedSlideshowId.value = null;
    }, 2000);
  }
}

const slidesKey = ref(1);

const slideType = ref("");
const showSlideTypeModal = ref(false);
const currentSlideshow = ref<Slideshow | null>(null);
const creatingSlideType = ref<string | null>(null);
const copiedSlideshowId = ref<number | null>(null);
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
  if (!canAddSlideshow.value) return;
  loading.value = true;
  try {
    const response = await axios.post(`/slideshow/${event.value?.id}`, {
      planId: planId.value
    });

    const slideshow = response.data.slideshow;
    slideshow.slides = slideshow.slides ?? [];
    slideshows.value.push(slideshow);
    selectedSlideshowId.value = slideshow.id;
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
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

function copyUrl(url) {
  navigator.clipboard.writeText(url);
}
</script>

<template>
  <div class="digital-workspace">
    <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>
    <div class="digital-workspace__split">
      <section class="digital-workspace__left" :style="{ flex: `0 0 ${leftWidth}%` }">
        <div class="digital-workspace__pane">
          <header class="digital-workspace__head">
            <h1 class="digital-workspace__title">Digital</h1>
            <p class="digital-workspace__lede">Slideshows fürs Karussell</p>
          </header>

          <div v-if="canAddSlideshow" class="space-y-2">
            <div class="slideshow-composer" @click="addSlideshow">
              <ItemCard dashed>
                <template #title>
                  <span class="slideshow-composer__title">Neue Slideshow</span>
                </template>
                <p class="item-card__hint">
                  Erstellt eine Slideshow mit Standardfolien.
                </p>
              </ItemCard>
            </div>
          </div>

          <div class="space-y-2 mt-2">
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
                <button
                    type="button"
                    class="digital-icon-btn"
                    title="Slideshow in neuem Fenster öffnen"
                    @click.stop="openSlideshowInNewWindow(slideshow)"
                >
                  <i class="bi bi-box-arrow-up-right" aria-hidden="true"/>
                </button>
                <button
                    type="button"
                    class="digital-icon-btn"
                    :title="copiedSlideshowId === slideshow.id ? 'Link kopiert!' : 'Link kopieren'"
                    @click.stop="copySlideshowLink(slideshow)"
                >
                  <i
                      :class="copiedSlideshowId === slideshow.id ? 'bi bi-check' : 'bi bi-clipboard'"
                      aria-hidden="true"
                  />
                </button>
              </template>
              <p class="item-card__hint">
                {{ slideshow.slides?.length ?? 0 }}
                Folie{{ (slideshow.slides?.length ?? 0) !== 1 ? 'n' : '' }}
              </p>
            </ItemCard>
          </div>
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
            <!-- Settings Row -->
            <div class="bg-white rounded-lg p-4 mb-4 border border-[var(--color-border)]">
              <div class="grid grid-cols-1 gap-4 items-end">
                <!-- Transition Time -->
                <div class="flex-1">
                  <label class="block text-sm font-medium text-[var(--color-text-muted)] mb-2">
                    <i class="bi bi-clock"></i> Anzeigezeit pro Folie
                  </label>
                  <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                      <input
                          type="number"
                          :min="1"
                          :max="60"
                          v-model.number="selectedSlideshow.transition_time"
                          @change="updateTransitionTime(selectedSlideshow)"
                          @blur="updateTransitionTime(selectedSlideshow)"
                          class="w-20 px-3 py-2 border border-[var(--color-border)] rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                          aria-label="Transition time in seconds"
                      />
                      <span class="text-sm font-medium text-[var(--color-text-muted)]">Sekunden</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <button
                          v-for="preset in [5, 10, 15, 30, 60]"
                          :key="preset"
                          @click="selectedSlideshow.transition_time = preset; updateTransitionTime(selectedSlideshow)"
                          :class="[
                        'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                        selectedSlideshow.transition_time === preset
                          ? 'bg-blue-600 text-white'
                          : 'bg-[var(--color-bg-muted)] text-[var(--color-text-muted)] hover:bg-[var(--color-bg-hover)]'
                      ]"
                      >
                        {{ preset }}s
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

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
  min-height: min(72vh, 48rem);
  height: 100%;
  min-width: 0;
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
  min-width: 0;
  min-height: 0;
}

.digital-workspace__right {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 16rem;
  display: flex;
  flex-direction: column;
}

.digital-workspace__pane {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  padding: 1.1rem 1.15rem 1.25rem;
  background: var(--glass-tab-surface, #ffffff);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 65%, transparent);
  border-radius: var(--radius-lg, 16px);
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.07),
    0 2px 6px rgba(15, 23, 42, 0.04);
}

.digital-workspace__pane--editor {
  display: flex;
  flex-direction: column;
}

.digital-workspace__head {
  margin-bottom: 0.85rem;
}

.digital-workspace__title {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 750;
  letter-spacing: -0.025em;
}

.digital-workspace__lede {
  margin: 0.2rem 0 0;
  font-size: 0.84rem;
  color: var(--color-text-muted);
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

.slideshow-composer {
  cursor: pointer;
}

.slideshow-composer__title {
  display: block;
  width: 100%;
  font-weight: 600;
  cursor: pointer;
}

.digital-icon-btn {
  width: 2.25rem;
  height: 2.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-muted);
}

.digital-icon-btn:hover {
  color: var(--color-accent);
  background: var(--color-bg-hover);
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
