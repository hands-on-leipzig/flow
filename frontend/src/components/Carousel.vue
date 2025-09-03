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

function getFormattedDateTime() {
  const now = new Date();

  now.setDate(29)

  // Get date components
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0'); // Months are 0-based
  const day = String(now.getDate()).padStart(2, '0');

  // Get time components
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');

  return `${year}-${month}-${day}+${hours}:${minutes}`;
}

const props = defineProps<{
  eventId: Number
}>();

let loaded = ref(false)

let slideshow = ref(null)
let slide = ref(null)
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
      slide.value = slides[0];
      showSlide.value = !!slides[0];
    }

    console.log(slideshow.value.transition_time);

    setInterval(() => {
      if (!slideshow.value?.slides?.length) {
        return;
      }
      let i = slideKey.value + 1;
      if (i >= slideshow.value.slides.length) {
        i = 0;
      }
      slideKey.value = i;
      slide.value = slideshow.value.slides[i];
      console.log(slide.value);
    }, (slideshow.value.transition_time ?? 15) * 1000);
  }
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
  <SlideContentRenderer v-if="showSlide === true" :slide="slide" class="" :preview="false"/>
  <!-- <footer>
    <div>
      <img :src="logo1_cut" alt="logo">
    </div>
    <div>
      <img :src="logo2_cut" alt="logo">
    </div>
    <div>
      <img :src="logo3_cut" alt="logo">
    </div>
    <div>
      <img :src="logo4" alt="logo">
    </div>
  </footer> -->
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

.slide {
  /*display: flex;*/

  overflow: hidden;
  cursor: none;
}
</style>
