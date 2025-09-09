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

async function loadSlideshows() {
  const response = await axios.get(`/carousel/${event.value?.id}/slideshows`);
  if (response && response.data) {
    slideshows.value = response.data;
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

async function addSlide(slideshow: Slideshow) {
  if (slideType.value) {
    let newSlide = Slide.createNewSlide(slideType.value);

    // TODO
    if (slideType.value === 'PublicPlanSlideContent') {
      newSlide.name = 'Öffentlicher Zeitplan';
      console.log(event.value);
      newSlide.content.planId = 8457; // TODO
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
    <h2 class="text-lg font-semibold mb-2">Präsentation</h2>
    <div class="mb-4">
      <div class="d-flex align-items-center gap-2">
            <span class="text-break">Link zur öffentlichen Ansicht:
              <a :href="carouselLink" target="_blank" rel="noopener noreferrer">{{ carouselLink }}</a>
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
        <select v-model="slideType">
          <option v-for="type of slideTypes" :id="type.value" v-text="type.label" :value="type.value"></option>
        </select>
        <button
            class="my-2 bg-green-500 hover:bg-green-600 text-white text-xs font-medium px-3 py-1.5 rounded-md shadow-sm"
            @click="addSlide(slideshow)">
          + Folie hinzufügen
        </button>
        <draggable v-model="slideshow.slides" :key="slidesKey"
                   class="draggable-list flex items-center flex-wrap flex-row gap-2" ghost-class="ghost" group="slides"
                   item-key="id"
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
.draggable-list {
  padding: 20px;
  gap: 10px;
  background: #2e2e2e;
  border-radius: 12px;
}
</style>