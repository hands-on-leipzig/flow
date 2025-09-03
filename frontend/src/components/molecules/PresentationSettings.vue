<script setup lang="ts">

import SlideThumb from "@/components/SlideThumb.vue";
import {useEventStore} from "@/stores/event";
import {computed} from "vue";
import SvgIcon from '@jamescoyle/vue-icon';
import { mdiContentCopy } from '@mdi/js';

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
});

function copyUrl(url) {
  navigator.clipboard.writeText(url);
}
</script>

<template>
  <h2 class="text-lg font-semibold mb-2">Präsentation</h2> <!-- TODO was ist hier eine passende Überschrift? -->
  <span class="text-sm mt-2 text-gray-500 mb-4">
          Halt die Teams am Wettbewerb immer auf dem laufenden.
          Hier kannst du Folien konfigurieren, die während des Wettbewerbs angezeigt werden.
        </span>
  <div class="mb-4">
    <div class="d-flex align-items-center gap-2">
            <span class="text-break">Link zur öffentlichen Ansicht:
              <a :href="carouselLink" target="_blank" rel="noopener noreferrer">{{carouselLink}}</a>
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
      <div class="flex items-center gap-2">
        <div v-for="slide in slideshow.slides" key="slideshow.id">
          <router-link :to="`/editSlide/${slideshow.id}/${slide.id}`">
            <SlideThumb :slide="slide" class="w-24 h-16 border rounded" @click=""/>
          </router-link>
        </div>
      </div>
    </details>
  </div>
</template>

<style scoped>

</style>