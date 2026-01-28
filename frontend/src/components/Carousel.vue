<script setup lang="ts">
import {inject, onMounted, reactive, ref} from "vue";
import SlideContentRenderer from "./slideTypes/SlideContentRenderer.vue";
import {Slide} from "../models/slide.js";
import axios from "axios";

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

let loaded = ref(false)

let slideshow = ref(null)
let showSlide = ref(false)
let slideKey = ref(0)

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

function startFetchingSlides() {
  /*setInterval(function () {
    fetchSlides()
  }, 300000)*/
}

onMounted(startFetchingSlides)
onMounted(fetchSlides)

</script>

<template>
  <div v-if="showSlide" v-for="(slide, index) in slideshow?.slides" class="h-screen w-full" v-show="index === slideKey">
    <SlideContentRenderer :slide="slide" :preview="false" :eventId="+props.eventId"
                          :defaultTransitionTime="slideshow?.transition_time" @next="nextSlide"/>
  </div>
</template>

<style scoped>
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
