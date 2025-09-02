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

let settings = reactive({
  transitionTime: 15,
  transitionEffect: "fade",
})

let loaded = ref(false)

let slide = ref()
let showSlide = ref(false)
let slideKey = ref(1)

async function fetchSlides() {
  const response = await axios.get("/carousel/1/slideshows");
  if (response && response.data) {
    const slides = [];
    for (let slide of response.data.slides) {
      slides.push(Slide.fromObject(slide));
    }

    console.log(slides);
    if (slides.length > 0) {
      slide.value = slides[0];
      showSlide.value = true;
    }
  }
}

async function fetchSettings() {
  // TODO
  try {
    const response = await axios.get(`/carousel/${props.eventId}/settings`)
    if (response && response.data) {
      Object.keys(settings).forEach((key) => {
        settings[key] = response.data[key]
      })
    }
  } catch (error) {
    console.log("Error fetching settings: ", error.message)
  }
}

function startFetchingSlides() {
  /*setInterval(function () {
    fetchSlides()
  }, 300000)*/
}

onMounted(fetchSettings)
onMounted(startFetchingSlides)
onMounted(fetchSlides)
/*onMounted(setInterval(function() {
  location.reload()
}, 300000))
*/

</script>

<template>
  <SlideContentRenderer v-if="showSlide === true" :slide="slide" class="slide"/>
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
  width: 100%;
  height: 100vh;
  position: relative;
  margin: 0;
  padding: 0;
  overflow: hidden;
  cursor: none;
}
</style>
