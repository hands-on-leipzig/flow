<script setup lang="ts">
import {onMounted, ref} from "vue";
import SlideContentRenderer from "./slideTypes/SlideContentRenderer.vue";
import {Slide} from "@/models/slide";
import axios from "axios";

const props = defineProps<{
  eventId: number,
  slideId: number
}>();

const slide = ref(null)
const showSlide = ref(false)

async function fetchSlide() {
  const response = await axios.get(`/carousel/${props.eventId}/slide/${props.slideId}`);
  if (response && response.data) {
    slide.value = Slide.fromObject(response.data);
    showSlide.value = true;
  }
}

function autoUpdate() {
  /*setInterval(function () {
    fetchSlide()
  }, 300000)*/
}

// onMounted(autoUpdate)
onMounted(fetchSlide)

</script>

<template>
  <div v-if="showSlide" class="h-screen w-full">
    <SlideContentRenderer :slide="slide" :preview="false" :eventId="+props.eventId" :visible="true"/>
  </div>
</template>

<style scoped>
</style>
