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

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const loading = ref(true);
const planId = ref<number | null>(null);
const slideshows = ref<Slideshow[]>([]);

const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
});
const slidesKey = ref(1);

const slideType = ref("");
const slideTypes = [
  {value: 'RobotGameSlideContent', label: 'Robot-Game-Ergebnisse'},
  {value: 'PublicPlanSlideContent', label: 'Öffentlicher Zeitplan'},
  {value: 'UrlSlideContent', label: 'Externer Inhalt (URL)'},
  {value: 'FabricSlideContent', label: 'Eigener Inhalt'},
];

onMounted(loadSlideshows);
onMounted(fetchPlanId);

async function loadSlideshows() {
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
  try {
    await axios.put(`/slideshow/${slideshow.id}`, {
      transition_time: slideshow.transition_time
    });
  } catch (e) {
    console.error(e);
  }
}

async function addSlide(slideshow: Slideshow) {
  if (slideType.value) {
    let newSlide = Slide.createNewSlide(slideType.value);

    // TODO
    if (slideType.value === 'PublicPlanSlideContent') {
      newSlide.name = 'Öffentlicher Zeitplan';
      newSlide.content.planId = planId.value;
    } else if (slideType.value === 'RobotGameSlideContent') {
      newSlide.name = 'Robot-Game-Ergebnisse';
    } else if (slideType.value === 'UrlSlideContent') {
      newSlide.name = 'Externer Inhalt';
    } else if (slideType.value === 'FabricSlideContent') {
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
}

function copyUrl(url) {
  navigator.clipboard.writeText(url);
}
</script>

<template>
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
    <div class="mb-4">
      <div class="d-flex align-items-center gap-2">
        <span class="text-break">Link zur öffentlichen Ansicht:
          <a :href="carouselLink" target="_blank" rel="noopener noreferrer" class="text-blue-600 underline font-medium text-base">{{ carouselLink }}</a>
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

          <select v-model="slideType" class="ml-10 mr-1">
            <option v-for="type of slideTypes" :id="type.value" v-text="type.label" :value="type.value"></option>
          </select>
          <button
              class="my-2 bg-green-500 hover:bg-green-600 text-white text-xs font-medium px-3 py-1.5 rounded-md shadow-sm"
              @click="addSlide(slideshow)">
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
