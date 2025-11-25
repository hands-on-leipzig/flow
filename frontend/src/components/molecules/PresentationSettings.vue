<script setup lang="ts">

import draggable from "vuedraggable";
import SlideThumb from "@/components/SlideThumb.vue";
import {useEventStore} from "@/stores/event";
import {computed, onMounted, ref} from "vue";
import SvgIcon from '@jamescoyle/vue-icon';
import {mdiContentCopy} from '@mdi/js';
import {Slideshow} from "@/models/slideshow";
import axios from "axios";
import {Slide} from "@/models/slide";
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import SavingToast from "@/components/atoms/SavingToast.vue";

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

const showingNewSlideModal = ref(false);
const modalSlideShowId = ref<number | null>(null);

const robotGameRounds = ref<RobotGamePublicRounds | null>(null);

const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
});
const slidesKey = ref(1);

// TODO: Previews auf dem Server (?) erstellen und laden
// TODO: Mehr Ideen als Presets
const commonBackground = `{"version":"6.7.1","backgroundImage":{"type":"Image","version":"6.7.1","left":0,"top":-3.3333,"width":1920,"height":1096,"scaleX":0.4167,"scaleY":0.4167,"src":"\/background.png"}}`;
const slideTypes = [
  {
    value: 'RobotGameSlideContent',
    label: 'Robot-Game Ergebnisse',
    previewSlide: () => createPreviewSlide('RobotGameSlideContent')
  },
  {
    value: 'PublicPlanSlideContent',
    label: 'Öffentlicher Zeitplan',
    previewSlide: () => {
      const slide = createPreviewSlide('PublicPlanSlideContent');
      slide.content.planId = planId.value;
      return slide;
    }
  },
  {value: 'UrlSlideContent', label: 'Externer Inhalt (URL)', previewSlide: () => createPreviewSlide('UrlSlideContent')},
  {value: 'FabricSlideContent', label: 'Eigener Inhalt', previewSlide: () => createPreviewSlide('FabricSlideContent')},
];

function createPreviewSlide(type: string) {
  const slide = Slide.createNewSlide(type);
  slide.content.background = commonBackground;
  return slide;
}

onMounted(loadSlideshows);
onMounted(getPublicRobotGameRounds);
onMounted(fetchPlanId);

async function loadSlideshows() {
  if (!event.value?.id) return;
  const response = await axios.get(`/slideshow/${event.value?.id}`);
  if (response && response.data) {
    slideshows.value = response.data;
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

async function updateOrder(slideshow: Slideshow) {
  const slideIds = slideshow.slides.map(slide => slide.id);

  savingToast?.value?.show();

  try {
    await axios.put(`/slideshow/${slideshow.id}/updateOrder`, {
      slide_ids: slideIds
    });
  } catch (e) {
    console.error(e);
  }
  console.log('update order');
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

function openNewSlideModal(slideshowId: number) {
  modalSlideShowId.value = slideshowId;
  showingNewSlideModal.value = true;
}

function closeNewSlideModal() {
  showingNewSlideModal.value = false;
  modalSlideShowId.value = null;
}

async function addSlide(type: string) {
  const slideshow = slideshows.value.find(s => s.id === modalSlideShowId.value);
  closeNewSlideModal();
  if (!slideshow) return;

  let newSlide = Slide.createNewSlide(type);

  if (type === 'PublicPlanSlideContent') {
    newSlide.name = 'Öffentlicher Zeitplan';
    newSlide.content.planId = planId.value;
  } else if (type === 'RobotGameSlideContent') {
    newSlide.name = 'Robot-Game Ergebnisse';
  } else if (type === 'UrlSlideContent') {
    newSlide.name = 'Externer Inhalt';
  } else if (type === 'FabricSlideContent') {
    newSlide.name = 'Eigener Inhalt';
  }

  const content = JSON.stringify(newSlide.content.toJSON());
  newSlide = {...newSlide, content, order: slideshow.slides.length + 1};

  try {
    const response = await axios.put(`slideshow/${slideshow.id}/add`, newSlide);
    console.log(response.data.slide);
    slideshow.slides.push(response.data.slide);
  } catch (e) {
    console.error(e);
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

  <div class="rounded-xl shadow bg-white p-4 flex flex-col">
    <div class="flex items-center justify-between">
      <h2 class="text-lg font-semibold mb-2">Präsentation</h2>
      <button
          class="bg-green-500 hover:bg-green-600 text-white text-xs font-medium px-3 py-1.5 rounded-md shadow-sm disabled:bg-gray-400 disabled:cursor-not-allowed"
          :disabled="loading || !planId || !event?.id || slideshows.length >= 1"
          @click="addSlideshow">
        + Slideshow erstellen
      </button>
    </div>

    <div class="grid-cols-2 grid">
      <div class="d-flex align-items-center gap-2">
        <span class="text-break">Link zur öffentlichen Ansicht:
          <a :href="carouselLink" target="_blank" rel="noopener noreferrer"
             class="text-blue-600 underline font-medium text-base">{{ carouselLink }}</a>
        </span>
        <button
            type="button"
            class="btn btn-outline-secondary btn-sm"
            @click="copyUrl(carouselLink)"
            title="Link kopieren"
        >
          <svg-icon type="mdi" :path="mdiContentCopy" size="16" class="ml-1 mt-1"></svg-icon>
        </button>
      </div>

      <div class="" v-if="robotGameRounds">
        <span class="font-bold">Robot Game: Öffentliche Ergebnisse</span>
        <InfoPopover
            text="Wähle aus, welche Ergebnisse öffentlich sichtbar sein sollen. Falls eine Wettbewerbsphase noch läuft oder später (z.B. auf der Bühne) veröffentlicht werden soll, sollte diese hier nicht ausgewählt werden."/>
        <div class="grid grid-cols-5 gap-2 mt-2">
          <label class="flex items-center gap-2 px-2">
            <input
                type="checkbox"
                :checked="robotGameRounds.vr1"
                @change="updateRobotGameRounds('vr1', ($event.target as HTMLInputElement).checked)"
            />
            <span>VR1</span>
          </label>
          <label class="flex items-center gap-2 px-2">
            <input
                type="checkbox"
                :checked="robotGameRounds.vr2"
                @change="updateRobotGameRounds('vr2', ($event.target as HTMLInputElement).checked)"
            />
            <span>VR2</span>
          </label>
          <label class="flex items-center gap-2 px-2">
            <input
                type="checkbox"
                :checked="robotGameRounds.vr3"
                @change="updateRobotGameRounds('vr3', ($event.target as HTMLInputElement).checked)"
            />
            <span>VR3</span>
          </label>
          <label class="flex items-center gap-2 px-2">
            <input
                type="checkbox"
                :checked="robotGameRounds.vf"
                @change="updateRobotGameRounds('vf', ($event.target as HTMLInputElement).checked)"
            />
            <span>VF</span>
          </label>
          <label class="flex items-center gap-2 px-2">
            <input
                type="checkbox"
                :checked="robotGameRounds.hf"
                @change="updateRobotGameRounds('hf', ($event.target as HTMLInputElement).checked)"
            />
            <span>HF</span>
          </label>
        </div>
      </div>
    </div>

    <div class="mb-4">
      <details v-for="(slideshow, index) in slideshows" :open="index === 0">
        <summary class="font-bold">{{ slideshow.name }}</summary>

        <div class="flex items-center gap-2 mt-2 mb-3">
          <label class="block text-sm font-medium text-gray-700 mb-1">Anzeigezeit pro Folie</label>
          <div class="flex items-center gap-3 w-60">
            <input
                type="range"
                :min="1"
                :max="60"
                :step="1"
                v-model.number="slideshow.transition_time"
                @change="updateTransitionTime(slideshow)"
                class="flex-1"
                aria-label="Transition time slider"
            />
            <span class="text-sm text-gray-600">{{ slideshow.transition_time }}s</span>
          </div>

          <!-- Add Slide -->
          <button
              class="my-2 bg-green-500 hover:bg-green-600 text-white text-xs font-medium px-3 py-1.5 rounded-md shadow-sm"
              @click="openNewSlideModal(slideshow.id)">
            + Folie hinzufügen
          </button>
        </div>

        <draggable v-model="slideshow.slides" :key="slidesKey"
                   class="flex flex-wrap gap-2 ü-5 bg-gray-800 rounded-xl" group="slides"
                   item-key="id"
                   handle=".drag-handle"
                   ghost-class="drag-ghost"
                   chosen-class="drag-chosen"
                   drag-class="drag-dragging"
                   animation="150"
                   @end="updateOrder(slideshow)">
          <template #item="{ element }">
            <SlideThumb :slide="element" class="border rounded" @deleteSlide="deleteSlide(slideshow, element.id)"/>
          </template>
        </draggable>
      </details>
    </div>
  </div>

  <!-- New Slide Modal -->
  <div v-if="showingNewSlideModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg w-1/2 p-4">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold">Folie hinzufügen</h3>
        <button class="text-gray-600" @click="closeNewSlideModal">Schließen</button>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div v-for="type in slideTypes" :key="type.value"
             class="border rounded p-3 flex flex-col justify-between cursor-pointer" @click="addSlide(type.value)">
          <div>
            <div class="font-semibold mb-1">{{ type.label }}</div>
            <div class="text-sm text-gray-600 mb-2">
              <SlideThumb :slide="type.previewSlide()" :show-controls="false"></SlideThumb>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

.drag-ghost {
  opacity: 0.4;
  transform: scale(0.98);
}

.drag-chosen {
  background-color: #fde68a; /* yellow-200 */
  box-shadow: 0 0 0 2px #facc15; /* yellow-400 */
}

.drag-dragging {
  cursor: grabbing;
}

</style>
