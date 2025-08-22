<script setup>

import {ImageSlideContent} from "../model/imageSlideContent.js";
import {RobotGameSlideContent} from "../model/robotGameSlideContent.js";
import {UrlSlideContent} from "../model/urlSlideContent.js";
import {ref} from "vue";
import api from "../services/api.js";
import {Slide} from "../model/slide.js";
import {PhotoSlideContent} from "../model/photoSlideContent.js";

let types = {
  image: new ImageSlideContent("").toJSON().type,
  rg: new RobotGameSlideContent().toJSON().type,
  url: new UrlSlideContent("").toJSON().type,
  photo: new PhotoSlideContent().toJSON().type
}

let slideType = ref("")
let url = ref("")

async function pushSlide() {
  let s
  switch (slideType.value) {
    case types.image:
      s = new Slide(1, "title", new ImageSlideContent(url.value))
      break;
    case types.rg:
      s = new Slide(1, "title", new RobotGameSlideContent())
      break;
    case types.url:
      s = new Slide(1, "title", new UrlSlideContent(url.value))
      break;
    case types.photo:
      s = new Slide(1, "title", new PhotoSlideContent())
      break;
  }

  let d = new FormData
  d.set("title", s.title)
  d.set("content", JSON.stringify(s.content.toJSON()))
  await api.post("/api/events/1/slides", d)
  document.location.reload()
}

</script>

<template>
  <div>
    <h2>Neue Slide erstellen</h2>
    <form @submit.prevent="pushSlide">
      <select v-model="slideType">
        <option v-for="type of types" :id="type" v-text="type"></option>
      </select>
      <input v-model="url" placeholder="Url bzw. Bildname" type="text">
      <input type="submit" value="Erstellen">
    </form>
  </div>
</template>

<style scoped>
div {
  position: fixed;
  width: 50vw;
  height: 50vh;
  background-color: #333;
  margin: auto auto;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  border-radius: 16px;
  padding: 20px;
  box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
}

form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

select, input[type="text"], input[type="submit"] {
  padding: 12px;
  border-radius: 8px;
  border: none;
  font-size: 16px;
  margin: 5px 0;
  width: 100%;
  max-width: 300px;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
  transition: all 0.3s ease;
  background-color: #555;
  color: white;
}

select:hover, input[type="text"]:hover, input[type="submit"]:hover {
  box-shadow: 0 6px 12px rgba(0, 0, 0, 0.4);
  background-color: #444;
}

select:focus, input[type="text"]:focus {
  outline: none;
  background-color: #666;
  box-shadow: 0 6px 12px rgba(0, 0, 0, 0.5);
}

input[type="submit"] {
  background-color: #4CAF50;
  color: white;
  font-weight: bold;
  cursor: pointer;
}

input[type="submit"]:hover {
  background-color: #45a049;
}

h2 {
  color: white;
  margin-bottom: 20px;
  font-size: 24px;
  text-align: center;
}

</style>