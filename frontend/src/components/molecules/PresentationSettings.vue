<script setup lang="ts">

import draggable from "vuedraggable";
import SlideThumb from "@/components/SlideThumb.vue";
import {useEventStore} from "@/stores/event";
import {computed, nextTick, onMounted, ref} from "vue";
import SvgIcon from '@jamescoyle/vue-icon';
import {mdiContentCopy} from '@mdi/js';
import {Slideshow} from "@/models/slideshow";
import axios from "axios";
import {Slide} from "@/models/slide";
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import SavingToast from "@/components/atoms/SavingToast.vue";
import AccordionArrow from "@/components/icons/IconAccordionArrow.vue";

type RobotGamePublicRounds = {
  vr1: boolean;
  vr2: boolean;
  vr3: boolean;
  vf: boolean;
  hf: boolean;
};

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const loading = ref(true);
const planId = ref<number | null>(null);
const slideshows = ref<Slideshow[]>([]);
const savingToast = ref(null);

const robotGameRounds = ref<RobotGamePublicRounds | null>(null);

const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
});

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
const editingSlideshowId = ref<number | null>(null);
const editingSlideshowName = ref<string>("");
const slideshowNameInput = ref<HTMLInputElement | null>(null);
const creatingSlideType = ref<string | null>(null);
const expandedSlideshows = ref<Set<number>>(new Set());
const copiedSlideshowId = ref<number | null>(null);
const isDragging = ref(false);
const draggedSlideId = ref<number | null>(null);
const slideTypes = [
  {value: 'RobotGameSlideContent', label: 'Robot-Game-Ergebnisse', icon: 'bi-trophy'},
  {value: 'PublicPlanSlideContent', label: 'Öffentlicher Zeitplan', icon: 'bi-calendar'},
  {value: 'UrlSlideContent', label: 'Externer Inhalt (URL)', icon: 'bi-link-45deg'},
  {value: 'FabricSlideContent', label: 'Eigener Inhalt', icon: 'bi-pencil-square'},
];

onMounted(loadSlideshows);
onMounted(getPublicRobotGameRounds);
onMounted(fetchPlanId);

async function loadSlideshows() {
  if (!event.value?.id) return;
  const response = await axios.get(`/slideshow/${event.value?.id}`);
  if (response && response.data) {
    slideshows.value = response.data;
    // Expand all slideshows by default
    expandedSlideshows.value = new Set(slideshows.value.map(s => s.id));
  }
  loading.value = false;
}

function toggleSlideshow(slideshowId: number) {
  if (expandedSlideshows.value.has(slideshowId)) {
    expandedSlideshows.value.delete(slideshowId);
  } else {
    expandedSlideshows.value.add(slideshowId);
  }
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
  loading.value = true;
  const response = await axios.post(`/slideshow/${event.value?.id}`, {
    planId: planId.value
  });

  const slideshow = response.data.slideshow;
  slideshows.value.push(slideshow);
  expandedSlideshows.value.add(slideshow.id)
  loading.value = false;
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

async function startEditingSlideshowName(slideshow: Slideshow) {
  editingSlideshowId.value = slideshow.id;
  editingSlideshowName.value = slideshow.name;
  // Focus the input after Vue updates the DOM
  await nextTick();
  slideshowNameInput.value?.focus();
  slideshowNameInput.value?.select();
}

function cancelEditingSlideshowName() {
  editingSlideshowId.value = null;
  editingSlideshowName.value = "";
}

async function saveSlideshowName(slideshow: Slideshow) {
  if (editingSlideshowName.value.trim() === "") {
    cancelEditingSlideshowName();
    return;
  }

  const originalName = slideshow.name;
  slideshow.name = editingSlideshowName.value.trim();

  savingToast?.value?.show();
  try {
    await axios.put(`/slideshow/${slideshow.id}`, {
      name: slideshow.name
    });
    cancelEditingSlideshowName();
  } catch (e) {
    console.error(e);
    slideshow.name = originalName;
    cancelEditingSlideshowName();
  }
}

function handleSlideshowNameKeydown(event: KeyboardEvent, slideshow: Slideshow) {
  if (event.key === 'Enter') {
    event.preventDefault();
    saveSlideshowName(slideshow);
  } else if (event.key === 'Escape') {
    event.preventDefault();
    cancelEditingSlideshowName();
  }
}

function openSlideTypeModal(slideshow: Slideshow) {
  currentSlideshow.value = slideshow;
  showSlideTypeModal.value = true;
}

function closeSlideTypeModal() {
  if (creatingSlideType.value) return; // Prevent closing while creating
  showSlideTypeModal.value = false;
  currentSlideshow.value = null;
  slideType.value = "";
  creatingSlideType.value = null;
}

async function addSlide(selectedType: string) {
  if (!currentSlideshow.value || !selectedType || creatingSlideType.value) return;

  creatingSlideType.value = selectedType;
  const slideshow = currentSlideshow.value;
  slideType.value = selectedType;

  let newSlide = Slide.createNewSlide(selectedType);

  // TODO
  if (selectedType === 'PublicPlanSlideContent') {
    newSlide.name = 'Öffentlicher Zeitplan';
    newSlide.content.planId = planId.value;
  } else if (selectedType === 'RobotGameSlideContent') {
    newSlide.name = 'Robot-Game-Ergebnisse';
  } else if (selectedType === 'UrlSlideContent') {
    newSlide.name = 'Externer Inhalt';
  } else if (selectedType === 'FabricSlideContent') {
    newSlide.name = 'Eigener Inhalt';
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

async function getPublicRobotGameRounds() {
  try {
    const response = await axios.get('/contao/rounds/' + event.value?.id);
    robotGameRounds.value = response.data;
  } catch (error) {
    console.error('Error fetching rounds:', error);
  }
}

async function updateRobotGameRounds(round, value) {
  robotGameRounds.value[round] = value;
  savingToast?.value?.show();
  await pushPublicRobotGameRoundsUpdate();
}

async function pushPublicRobotGameRoundsUpdate() {
  try {
    await axios.put('/contao/rounds/' + event.value?.id, robotGameRounds.value);
  } catch (e) {
    console.error('Error updating rounds:', e);
  }
}

function copyUrl(url) {
  navigator.clipboard.writeText(url);
}
</script>

<template>
  <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>

  <div class="rounded-xl shadow-lg bg-white p-6 flex flex-col gap-6">
    <!-- Header -->
    <div class="flex items-center justify-between border-b pb-4">
      <div class="flex items-center gap-3">
        <i class="bi bi-slides text-2xl text-blue-600"></i>
        <h2 class="text-xl font-bold text-gray-800">Slideshow-Editor</h2>
      </div>
      <button
          class="flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed"
          :disabled="loading || !planId || !event?.id || slideshows.length >= 1"
          @click="addSlideshow">
        <i class="bi bi-plus-circle"></i>
        <span>Slideshow erstellen</span>
      </button>
    </div>

    <!-- Slideshows -->
    <div class="space-y-4" v-if="slideshows.length > 0">
      <div v-for="(slideshow, index) in slideshows" :key="slideshow.id"
           class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl border border-gray-200 shadow-sm overflow-hidden">

        <!-- Slideshow Header -->
        <button
            @click="toggleSlideshow(slideshow.id)"
            class="w-full flex items-center justify-between p-5 hover:bg-gray-50 transition-colors text-left"
        >
          <div class="flex items-center gap-3 flex-1">
            <i class="bi bi-collection-play text-xl text-gray-600"></i>

            <div v-if="editingSlideshowId === slideshow.id" class="flex items-center gap-2" @click.stop>
              <input
                  type="text"
                  v-model="editingSlideshowName"
                  @blur="saveSlideshowName(slideshow)"
                  @keydown="handleSlideshowNameKeydown($event, slideshow)"
                  class="text-lg font-bold text-gray-800 px-2 py-1 border border-blue-500 rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                  ref="slideshowNameInput"
                  autofocus
              />
            </div>
            <div v-else class="flex items-center gap-2" @click.stop>
              <h3 class="text-lg font-bold text-gray-800">{{ slideshow.name }}</h3>
              <button
                  @click.stop="startEditingSlideshowName(slideshow)"
                  class="text-gray-400 hover:text-gray-600 transition-colors"
                  title="Slideshow umbenennen"
              >
                <i class="bi bi-pencil text-sm"></i>
              </button>
            </div>

            <span class="text-sm text-gray-500">({{
                slideshow.slides.length
              }} Folie{{ slideshow.slides.length !== 1 ? 'n' : '' }})</span>

            <!-- Slideshow Actions -->
            <div class="flex items-center gap-2 ml-2" @click.stop>
              <button
                  @click.stop="openSlideshowInNewWindow(slideshow)"
                  class="flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition-colors"
                  title="Slideshow in neuem Fenster öffnen"
              >
                <i class="bi bi-box-arrow-up-right text-xs"></i>
                <span>Öffnen</span>
              </button>
              <button
                  @click.stop="copySlideshowLink(slideshow)"
                  :class="[
                    'flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-md transition-colors',
                    copiedSlideshowId === slideshow.id
                      ? 'bg-green-100 border border-green-300 text-green-700'
                      : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50'
                  ]"
                  :title="copiedSlideshowId === slideshow.id ? 'Link kopiert!' : 'Link kopieren'"
              >
                <i :class="copiedSlideshowId === slideshow.id ? 'bi bi-check' : 'bi bi-clipboard'" class="text-xs"></i>
                <span>{{ copiedSlideshowId === slideshow.id ? 'Kopiert!' : 'Link kopieren' }}</span>
              </button>
            </div>
          </div>

          <!-- Arrow Icon on the right -->
          <i
              :class="[
                'bi text-gray-500 transition-transform flex-shrink-0',
                expandedSlideshows.has(slideshow.id) ? 'bi-chevron-up' : 'bi-chevron-down'
              ]"
          ></i>
        </button>

        <!-- Collapsible Content -->
        <transition name="fade">
          <div v-if="expandedSlideshows.has(slideshow.id)" class="px-5 pb-5">
            <!-- Settings Row -->
            <div class="bg-white rounded-lg p-4 mb-4 border border-gray-200">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
                <!-- Transition Time -->
                <div class="flex-1">
                  <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="bi bi-clock"></i> Anzeigezeit pro Folie
                  </label>
                  <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-2">
                      <input
                          type="number"
                          :min="1"
                          :max="60"
                          v-model.number="slideshow.transition_time"
                          @change="updateTransitionTime(slideshow)"
                          @blur="updateTransitionTime(slideshow)"
                          class="w-20 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                          aria-label="Transition time in seconds"
                      />
                      <span class="text-sm font-medium text-gray-700">Sekunden</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <button
                          v-for="preset in [5, 10, 15, 30, 60]"
                          :key="preset"
                          @click="slideshow.transition_time = preset; updateTransitionTime(slideshow)"
                          :class="[
                        'px-3 py-1 text-xs font-medium rounded-md transition-colors',
                        slideshow.transition_time === preset
                          ? 'bg-blue-600 text-white'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                      ]"
                      >
                        {{ preset }}s
                      </button>
                    </div>
                  </div>
                </div>

                <!-- Robot Game Rounds -->
                <div class="flex-1" v-if="robotGameRounds">
                  <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="bi bi-trophy"></i> Robot Game: Öffentliche Ergebnisse
                    <InfoPopover
                        text="Wähle aus, welche Ergebnisse öffentlich sichtbar sein sollen. Falls eine Wettbewerbsphase noch läuft oder später (z.B. auf der Bühne) veröffentlicht werden soll, sollte diese hier nicht ausgewählt werden."/>
                  </label>
                  <div class="grid grid-cols-5 gap-2">
                    <label
                        class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
                      <input
                          type="checkbox"
                          :checked="robotGameRounds.vr1"
                          @change="updateRobotGameRounds('vr1', ($event.target as HTMLInputElement).checked)"
                          class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm font-medium">VR1</span>
                    </label>
                    <label
                        class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
                      <input
                          type="checkbox"
                          :checked="robotGameRounds.vr2"
                          @change="updateRobotGameRounds('vr2', ($event.target as HTMLInputElement).checked)"
                          class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm font-medium">VR2</span>
                    </label>
                    <label
                        class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
                      <input
                          type="checkbox"
                          :checked="robotGameRounds.vr3"
                          @change="updateRobotGameRounds('vr3', ($event.target as HTMLInputElement).checked)"
                          class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm font-medium">VR3</span>
                    </label>
                    <label
                        class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
                      <input
                          type="checkbox"
                          :checked="robotGameRounds.vf"
                          @change="updateRobotGameRounds('vf', ($event.target as HTMLInputElement).checked)"
                          class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm font-medium">VF</span>
                    </label>
                    <label
                        class="flex items-center gap-2 px-3 py-2 bg-white rounded-lg border border-orange-200 hover:bg-orange-100 cursor-pointer transition-colors">
                      <input
                          type="checkbox"
                          :checked="robotGameRounds.hf"
                          @change="updateRobotGameRounds('hf', ($event.target as HTMLInputElement).checked)"
                          class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                      />
                      <span class="text-sm font-medium">HF</span>
                    </label>
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
                    @click="openSlideTypeModal(slideshow)">
                  <i class="bi bi-plus-circle text-4xl text-gray-400 group-hover:text-green-500 mb-2 transition-colors"></i>
                  <span
                      class="text-sm font-medium text-gray-400 group-hover:text-green-500 text-center">Neue Folie</span>
                </button>

                <!-- Empty State (only shown when no slides) -->
                <div v-if="slideshow.slides.length === 0"
                     class="flex flex-col items-center justify-center py-12 text-gray-400 flex-1 min-w-[200px]">
                  <i class="bi bi-inbox text-4xl mb-3"></i>
                  <p class="text-sm">Noch keine Folien vorhanden</p>
                </div>

                <!-- Slides (draggable) - using contents to make items direct children of flex container -->
                <template v-if="slideshow.slides.length > 0">
                  <draggable
                      v-model="slideshow.slides"
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
                      @end="onDragEnd(slideshow)">
                    <template #item="{ element }">
                      <SlideThumb 
                          :slide="element" 
                          :class="{ 'opacity-0': draggedSlideId === element.id && isDragging }"
                          @deleteSlide="deleteSlide(slideshow, element.id)"/>
                    </template>
                  </draggable>
                </template>
              </div>
            </div>
          </div>
        </transition>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="text-center py-12 bg-gray-50 rounded-xl border-2 border-dashed border-gray-300">
      <i class="bi bi-slides text-5xl text-gray-400 mb-4"></i>
      <p class="text-gray-600 font-medium mb-2">Noch keine Slideshow vorhanden</p>
      <p class="text-sm text-gray-500 mb-4">Erstellen Sie eine Slideshow, um Präsentationsfolien hinzuzufügen</p>
      <button
          class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg shadow-sm transition-colors disabled:bg-gray-400 disabled:cursor-not-allowed"
          :disabled="loading || !planId || !event?.id"
          @click="addSlideshow">
        <i class="bi bi-plus-circle"></i>
        <span>Slideshow erstellen</span>
      </button>
    </div>

    <!-- Slide Type Selection Modal -->
    <div
        v-if="showSlideTypeModal"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[100]"
        @click="closeSlideTypeModal"
    >
      <div class="bg-white rounded-lg p-6 max-w-2xl mx-4 w-full" @click.stop>
        <div class="flex items-center justify-between mb-6">
          <h3 class="text-lg font-semibold text-gray-900">Folientyp wählen</h3>
          <button
              @click="closeSlideTypeModal"
              class="text-gray-500 hover:text-gray-700 text-2xl leading-none"
          >
            ×
          </button>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <button
              v-for="type of slideTypes"
              :key="type.value"
              @click="addSlide(type.value)"
              :disabled="!!creatingSlideType"
              :class="[
                'flex flex-col items-center justify-center p-6 border-2 rounded-lg transition-all relative',
                creatingSlideType === type.value
                  ? 'border-blue-500 bg-blue-50 cursor-wait'
                  : creatingSlideType
                  ? 'border-gray-200 opacity-50 cursor-not-allowed'
                  : 'border-gray-200 hover:border-blue-500 hover:bg-blue-50 cursor-pointer group'
              ]"
          >
            <div v-if="creatingSlideType === type.value"
                 class="absolute inset-0 flex items-center justify-center bg-blue-50 bg-opacity-75 rounded-lg">
              <svg class="animate-spin h-8 w-8 text-blue-600" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
              </svg>
            </div>
            <i :class="[
              `bi ${type.icon} text-4xl mb-3 transition-colors`,
              creatingSlideType === type.value
                ? 'text-blue-600'
                : 'text-gray-600 group-hover:text-blue-600'
            ]"></i>
            <span :class="[
              'text-sm font-medium text-center',
              creatingSlideType === type.value
                ? 'text-blue-700'
                : 'text-gray-700 group-hover:text-blue-700'
            ]">{{ type.label }}</span>
          </button>
        </div>

        <div class="mt-6 flex justify-end">
          <button
              @click="closeSlideTypeModal"
              :disabled="!!creatingSlideType"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Abbrechen
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

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

.fade-enter-active, .fade-leave-active {
  transition: all 0.2s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}

</style>
