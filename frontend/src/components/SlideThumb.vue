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
  <div class="flex flex-col relative bg-blue-400 w-56 h-52 m-2 cursor-grab rounded-xl shadow">
    <span class="flex justify-end gap-1 pt-2 pr-2">
      <router-link :to="'/editSlide/' + slide.id">
        <svg-icon type="mdi" :path="mdiPencil" @click="emit('editSlide')"/>
      </router-link>
      <svg-icon type="mdi" :path="mdiTrashCanOutline" @click="deleteSlide"></svg-icon>
    </span>
    <div class="w-56 h-32 mx-auto bg-blue-300 m-2 flex items-center justify-center overflow-hidden">
      <SlideContentRenderer :slide="componentSlide" :preview="true"></SlideContentRenderer>
    </div>
    <span class="p-2 text-center font-medium truncate">{{ slide.name }}</span>
  </div>
</template>

<style scoped>

</style>
