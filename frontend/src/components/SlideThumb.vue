<script setup lang="ts">
import {Slide as Slide} from "../models/slide.js";
import SlideContentRenderer from "./slideTypes/SlideContentRenderer.vue";
import {mdiTrashCanOutline, mdiPencil} from '@mdi/js';
import SvgIcon from '@jamescoyle/vue-icon';
import axios from "axios";

const props = defineProps<{
  slide: Slide
}>();

async function deleteSlide() {
  try {
    const response = await axios.delete(`/slides/${props.slide.id}`);
    if (response.status === 200) {
      emit('deleteSlide')
    }
  } catch (error) {
    console.error("Error deleting slide:", error);
  }
}

const emit = defineEmits(['deleteSlide', 'editSlide']);
const componentSlide = Slide.fromObject(props.slide)
</script>

<template>
  <div class="slide-thumb">
    <span class="button-row">
      <router-link :to="'/editSlide/' + slide.id">
        <svg-icon type="mdi" :path="mdiPencil" @click="emit('editSlide')"/>
      </router-link>
      <svg-icon type="mdi" :path="mdiTrashCanOutline" @click="deleteSlide"></svg-icon>
    </span>
    <div class="thumb">
      <SlideContentRenderer :slide="componentSlide" :preview="true"></SlideContentRenderer>
    </div>
    <span>{{ slide.name }}</span>
  </div>
</template>

<style scoped>
.slide-thumb {
  display: flex;
  flex-direction: column;
  position: relative;
  background-color: cornflowerblue;
  width: 16rem;
  height: 13rem;
  margin: .5rem;
  cursor: grab;
}

.thumb {
  width: 16rem;
  height: 9rem;
  background-color: lightblue;
}

.slide-thumb > * {
  width: 100%;
}

.button-row {
  display: flex;
  justify-content: flex-end;
}

.button-row > * {
  margin: .2rem;
  cursor: pointer;
}
</style>
