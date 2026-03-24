<script setup lang="ts">
import {onMounted, onUnmounted, ref} from "vue";
import SlideContentRenderer from "./slideTypes/SlideContentRenderer.vue";
import {Slide} from "../models/slide.js";
import axios from "axios";
import {useAutoHideCursor} from "../composables/useAutoHideCursor";

// TODO Socket injector
/*
const socket = inject('websocket');
socket.registerClient();
socket.addListener((msg) => {
  if (msg.type === 'pushSlide') {
    slide.value = Slide.fromObject(msg.slide);
    showSlide.value = true
  }
}); */

const props = defineProps<{
  eventId: number
}>();

let slideshow = ref(null)
let showSlide = ref(false)
let slideKey = ref(0)

const container = ref<HTMLElement>(null);
const renderers = ref([]);

useAutoHideCursor(container, 3000);

async function fetchSlides() {
  const response = await axios.get(`/carousel/${props.eventId}/slideshows`);
  if (response && response.data) {
    for (const resShow of response.data) {
      const slides = [];
      for (let slide of resShow.slides) {
        slides.push(Slide.fromObject(slide));
      }
      resShow.slides = slides;
      slideshow.value = resShow;
      showSlide.value = !!slides[0];
    }
  }
}

function nextSlide() {
  if (!slideshow.value?.slides?.length) {
    return;
  }
  let i = slideKey.value + 1;
  if (i >= slideshow.value.slides.length) {
    i = 0;
  }
  slideKey.value = i;
}

function prevSlide() {
  if (!slideshow.value?.slides?.length) {
    return;
  }
  let i = slideKey.value - 1;
  if (i < 0) {
    i = slideshow.value.slides.length - 1;
  }
  slideKey.value = i;
}

function handleKeyDown(event) {
  const wrapper = renderers.value?.[slideKey.value];
  if (!wrapper) return;

  const direction = event.key === 'ArrowRight' ? 'right' : event.key === 'ArrowLeft' ? 'left' : null;
  if (!direction) return;

  let handled = false;
  if (typeof wrapper.handleArrow === 'function') {
    handled = wrapper.handleArrow(direction);
  }

  if (!handled) {
    if (direction === 'right') {
      nextSlide();
    } else if (direction === 'left') {
      prevSlide();
    }
  }
}

function startFetchingSlides() {
  /*setInterval(function () {
    fetchSlides()
  }, 300000)*/
}

onMounted(startFetchingSlides)
onMounted(fetchSlides)

onMounted(() => window.addEventListener('keydown', handleKeyDown));
onUnmounted(() => window.removeEventListener('keydown', handleKeyDown));

</script>

<template>
  <div v-if="showSlide" ref="container" id="container" class="h-screen w-full">
    <SlideContentRenderer
        v-for="(slide, index) in slideshow?.slides" :key="index" v-show="index === slideKey"
        ref="renderers"
        :slide="slide" :preview="false" :eventId="+props.eventId" :visible="index === slideKey"
        :defaultTransitionTime="slideshow?.transition_time" @next="nextSlide"/>
  </div>
</template>

<style scoped>
.hide-cursor {
  cursor: none;
}

footer {
  background-color: white;
  width: 100%;
  height: 10vh;
  position: fixed;
  z-index: 10000;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: space-evenly;
}

footer div {
  padding-left: 2rem;
  padding-right: 2rem;
}

footer img {
  max-height: 9vh;
}
</style>
