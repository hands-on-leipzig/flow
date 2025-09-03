<script setup lang="ts">

import draggable from "vuedraggable";
import SlideThumb from "@/components/SlideThumb.vue";
import {useEventStore} from "@/stores/event";
import {computed, ref} from "vue";
import SvgIcon from '@jamescoyle/vue-icon';
import {mdiContentCopy} from '@mdi/js';
import {Slideshow} from "@/models/slideshow";
import axios from "axios";

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
});
const slidesKey = ref(1);

async function updateOrder(slideshow: Slideshow) {
  const slideIds = slideshow.slides.map(slide => slide.id);

  try {
    await axios.put(`/events/${event.value.id}/slideshow/${slideshow.id}/updateOrder`, {
      slide_ids: slideIds
    });
  } catch (e) {
    console.error(e);
  }
  console.log('update order');
}

function copyUrl(url) {
  navigator.clipboard.writeText(url);
}
</script>

<template>
  <h2 class="text-lg font-semibold mb-2">Präsentation</h2> <!-- TODO was ist hier eine passende Überschrift? -->
  <span class="text-sm mt-2 text-gray-500 mb-4">
          Halt die Teams am Wettbewerb immer auf dem Laufenden.
          Hier kannst du Folien konfigurieren, die während des Wettbewerbs angezeigt werden.
        </span>
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
    <details v-for="(slideshow, index) in event?.slideshows" :open="index === 0">
      <summary class="font-bold">{{ slideshow.name }}</summary>
      <draggable v-model="slideshow.slides" :key="slidesKey"
                 class="draggable-list flex items-center flex-wrap flex-row gap-2" ghost-class="ghost" group="slides"
                 item-key="id"
                 @end="updateOrder(slideshow)">
        <template #item="{ element }">
          <router-link v-if="element.type === 'FabricSlideContent'" :to="`/editSlide/${slideshow.id}/${element.id}`">
            <SlideThumb :slide="element" class="w-24 h-16 border rounded" @click=""/>
          </router-link>
          <SlideThumb v-else :slide="element" class="w-24 h-16 border rounded" @click=""/>
        </template>
      </draggable>
    </details>
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