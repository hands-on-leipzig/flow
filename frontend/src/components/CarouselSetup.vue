<script setup>
import {inject, onMounted, reactive, ref} from "vue";
import SlideThumb from "./SlideThumb.vue";
import draggable from "vuedraggable";
import {Slide} from "../model/slide.ts";
import api from '../services/api';
import {UrlSlideContent} from "../model/urlSlideContent.js";
import ChooseSlideType from "./ChooseSlideType.vue";

let newSlide = ref(false)
//const socket = inject('websocket');
//const slides = socket.slides;

function addSlide() {
  slides.push(new Slide(slides.length + 1, "randomName", new UrlSlideContent("url")));
}

const slidesKey = ref(1)

function deleteSlide(slide) {
  console.log("Deleting slide", slide.title);
  slides.value = slides.value.filter(s => s.id !== slide.id);
}

const showRound = reactive({
  vr1: false,
  vr2: false,
  vr3: false,
  af: false,
  vf: false,
  hf: false,
});

async function saveRoundDisplaySetting() {
  let d = new FormData
  Object.keys(showRound).forEach((key) => {
    d.set(key, showRound[key])
  })
  await api.post("/api/events/1/scores/show-rounds", d)
}

async function fetchRounds() {
  try {
    const response = await api.get("/api/events/1/scores/show-rounds")
    if (response && response.data) {
      Object.keys(showRound).forEach((key) => {
        showRound[key] = response.data[key] || false
      })
    }
  } catch (error) {
    console.error("Error fetching rounds:", error.message);
  }
}

let settings = reactive({
  transitionTime: 15,
  transitionEffect: "fade",
})

async function saveSettings() {
  let d = new FormData
  Object.keys(settings).forEach((key) => {
    d.set(key, settings[key])
  })
  await api.post("/api/events/1/settings", d)
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

let slides = reactive([])

async function fetchSlides() {
  const response = await api.get("/api/events/1/slides")
  if (response && response.data) {
    slides = []
    for (let slide of response.data) {
      slides.push({
        id: slide.id,
        title: slide.title,
        content: JSON.parse(slide.content)
      })
    }
  }
}

function chooseNewSlide() {
  newSlide.value = true
}

const socket = inject('websocket');
const sendMessage = inject('sendMessage');

function sendUpdateMessage() {
  if (sendMessage) {
    sendMessage({ type: 'saveSlides', slides: slides });
    console.log("Message sent to Carousel");
  } else {
    console.error("Send function not available");
  }
}

onMounted(fetchSlides)
onMounted(fetchRounds)
onMounted(fetchSettings)
</script>

<template>
  <h1>Kiosk Carousel</h1>
  <button @click="sendUpdateMessage">Notify Carousel</button>
  <div class="controls">
    <div class="show-round">
      <form @submit.prevent="saveRoundDisplaySetting">
        <label>VR I
          <input v-model="showRound.vr1" name="vr1" type="checkbox">
        </label>
        <br>
        <label>VR II
          <input v-model="showRound.vr2" name="vr2" type="checkbox">
        </label>
        <br>
        <label>VR III
          <input v-model="showRound.vr3" name="vr3" type="checkbox">
        </label>
        <br>
        <label>AF
          <input v-model="showRound.af" name="af" type="checkbox">
        </label>
        <br>
        <label>VF
          <input v-model="showRound.vf" name="vf" type="checkbox">
        </label>
        <br>
        <label>HF
          <input v-model="showRound.hf" name="hf" type="checkbox">
        </label>
        <br>
        <button type="submit">Save</button>
      </form>
    </div>

    <div>
      <form @change="saveSettings">
        <label>Transition Time:
          <input v-model="settings.transitionTime" max="60" min="2" type="range">
          <span>{{ settings.transitionTime }} s</span>
        </label>

        <label>Transition Effect:
          <select v-model="settings.transitionEffect">
            <option selected value="fade">Fade</option>
            <option value="slide">Slide</option>
            <option value="loop">Loop</option>
          </select>
        </label>
      </form>
    </div>
  </div>


  <div class="slides-container">
    <div class="add-slide" @click="chooseNewSlide">
      <fa :icon="['fas', 'plus-circle']"></fa>
    </div>
    <draggable v-model="slides" :key="slidesKey" class="draggable-list" ghost-class="ghost" group="slides" item-key="id"
               @end="updateOrder">
      <template #item="{ element }">
        <SlideThumb :slide="element" @deleteSlide="deleteSlide(element)"></SlideThumb>
      </template>
    </draggable>
  </div>
  <ChooseSlideType v-if="newSlide"/>
</template>

<style scoped>

body {
  font-family: 'Arial', sans-serif;
  background-color: #121212;
  color: #f1f1f1;
}

h1 {
  text-align: center;
  margin: 20px 0;
  font-size: 2.5rem;
  color: #4CAF50;
}

.controls {
  margin: 20px auto;
  padding: 20px;
  background: #1e1e1e;
  border-radius: 12px;
  display: flex;
  gap: 40px;
  justify-content: center;
  align-items: center;
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.4);
}

.controls label {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 5px 0;
  color: #f1f1f1;
}

input[type="range"] {
  width: 100%;
  margin: 5px 0;
  cursor: pointer;
}

input[type="checkbox"] {
  margin-right: 5px;
  cursor: pointer;
}

button {
  padding: 8px 16px;
  border: none;
  border-radius: 8px;
  background-color: #4CAF50;
  color: white;
  cursor: pointer;
  font-size: 1rem;
  transition: background-color 0.3s ease;
}

button:hover {
  background-color: #45a049;
}

select {
  padding: 8px;
  border-radius: 8px;
  background-color: #333;
  color: #f1f1f1;
  border: none;
  margin: 5px 0;
  transition: background-color 0.3s ease;
}

select:hover {
  background-color: #444;
}

.slides-container {
  margin: 20px auto;
  padding: 20px;
  background: #1e1e1e;
  border-radius: 12px;
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 20px;
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.4);
}

.add-slide {
  width: 6rem;
  height: 10rem;
  background-color: #4CAF50;
  border-radius: 12px;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  font-size: 2.5rem;
  color: white;
  margin: .5rem;
  transition: transform 0.2s ease, background-color 0.3s ease;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.4);
}

.add-slide:hover {
  background-color: #45a049;
  transform: scale(1.1);
}

.slide-thumb {
  padding: 10px;
  background: #333;
  border-radius: 8px;
  border: 1px solid #555;
  color: white;
  text-align: center;
  transition: transform 0.2s ease;
  cursor: grab;
  margin: 5px;
}

.slide-thumb:hover {
  transform: scale(1.05);
}

.ghost {
  opacity: 0.6;
}

.chosen {
  background: #4CAF50;
}

input[type="text"] {
  padding: 8px;
  margin: 5px 0;
  border-radius: 8px;
  border: 1px solid #555;
  background-color: #333;
  color: #f1f1f1;
  width: 100%;
  max-width: 300px;
  transition: border-color 0.3s ease;
}

input[type="text"]:focus {
  outline: none;
  border-color: #4CAF50;
}



/*.controls {
  margin-bottom: 10px;
  padding: 10px;
  background: #f9f9f9;
  border-radius: 5px;
  display: flex;
  gap: 20px;
}

.draggable-list {
  display: flex;
  flex-wrap: wrap;
  padding: 10px;
  background: #f4f4f4;
  border-radius: 8px;
}

.add-slide {
  width: 5rem;
  height: 9rem;
  background-color: lightgreen;
  display: flex;
  justify-content: center;
  align-items: center;
  cursor: pointer;
  font-size: 2rem;
  color: white;
  margin: .5rem;
}

/*.slide-thumb {
  padding: 10px;
  background: white;
  border-radius: 5px;
  border: 1px solid #ddd;
  cursor: grab;
  text-align: center;
}

.ghost {
  opacity: 0.5;
}

.chosen {
  background: lightblue;
}
*/

</style>
