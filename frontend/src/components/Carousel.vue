<script setup>
import '@splidejs/vue-splide/css';
import {inject, onMounted, reactive, ref} from "vue";
import SlideContentRenderer from "./slides/SlideContentRenderer.vue";
import api from "../services/api.js";
import {Slide} from "../model/slide.js";
import {ImageSlideContent} from "../model/imageSlideContent.js";
import {RobotGameSlideContent} from "../model/robotGameSlideContent.js";
import {UrlSlideContent} from "../model/urlSlideContent.js";
import {PhotoSlideContent} from "../model/photoSlideContent.js";

const socket = inject('websocket');
socket.registerClient();
socket.addListener((msg) => {
  if (msg.type === 'pushSlide') {
    slide.value = Slide.fromObject(msg.slide);
    showSlide.value = true
  }
});

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

let settings = reactive({
  transitionTime: 15,
  transitionEffect: "fade",
})

let loaded = ref(false)

function getSlide(slide) {
  let types = {
    image: new ImageSlideContent("").toJSON().type,
    rg: new RobotGameSlideContent().toJSON().type,
    url: new UrlSlideContent("").toJSON().type,
    photo: new PhotoSlideContent("").toJSON().type
  }

  let content = JSON.parse(slide.content)
  switch (content.type) {
    case types.image:
      return new Slide(slide.id, slide.title, new ImageSlideContent(content.url))
    case types.rg:
      return new Slide(slide.id, slide.title, new RobotGameSlideContent())
    case types.url:
      return new Slide(slide.id, slide.title, new UrlSlideContent(content.url))
    case types.photo:
      return new Slide(slide.id, slide.title, new PhotoSlideContent())
  }
}

let slide = ref()
let showSlide = ref(false)
let slideKey = ref(1)

async function fetchSlides() {
  /*const response = await api.get("/api/events/1/slides")
  if (response && response.data) {
    slides = []
    for (let slide of response.data) {
      slides.push(getSlide(slide))
    }
    loaded.value = false
    setTimeout(function () {
      loaded.value = true
    }, 1000)
    slideKey.value++
  }*/
  loaded.value = true;
  showSlide.value = true;
  const content = new RobotGameSlideContent();
  content.highlightColor = '#F78B1F';
  slide.value = new Slide(1, "Test-Scores", content);
}

async function fetchSettings() {
  try {
    const response = await api.get("/api/events/1/settings")
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
  setInterval(function () {
    fetchSlides()
  }, 300000)
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
