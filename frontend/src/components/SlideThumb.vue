<script setup>
import {Slide as Slide} from "../model/slide.ts";
import SlideContentRenderer from "./slides/SlideContentRenderer.vue";

const props = defineProps({
  slide: Slide
});

const emit = defineEmits(['deleteSlide']);
const componentSlide = Slide.fromObject(props.slide)
</script>

<template>
  <div class="slide-thumb">
    <span class="button-row"><fa :icon="['fas', 'trash-can']" @click="emit('deleteSlide');"></fa></span>
    <div class="thumb">
      <SlideContentRenderer :slide="componentSlide"></SlideContentRenderer>
    </div>
    <span>{{ slide.content.type}}</span>
    <span>{{ slide.title }}</span>
  </div>
</template>

<style scoped>
.slide-thumb {
  display: flex;
  flex-direction: column;
  position: relative;
  background-color: cornflowerblue;
  width: 18rem;
  height: 11rem;
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
