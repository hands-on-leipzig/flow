<script setup>
import {ImageSlideContent} from '../../model/imageSlideContent.ts';
import {computed} from 'vue';

const props = defineProps({
  content: ImageSlideContent
});

// Dynamically build the image path using require or import
const imageUrl = computed(() => new URL(`../../assets/imgSlides/${props.content.imageUrl}`, import.meta.url).href);

</script>

<template>
  <img :src="imageUrl" alt="Slide Image" class="slide-image">
</template>

<style scoped>
.slide-image {
  width: 100%;
  height: 100%;
  margin: 0;
  position: relative;
  overflow: hidden;
}

.slide-container {
  display: flex;
  justify-content: center;
  align-items: center;
  width: 100%;
  height: 100%;
  position: relative;
}
</style>


<!--<script setup>
import {ImageSlideContent} from '../../model/imageSlideContent.ts';

const props = defineProps({
  content: ImageSlideContent
});

import { ref, defineAsyncComponent } from 'vue';

let componentName = ref(props.content.imageUrl);
const componentPath = `../../assets/imgSlides/${componentName}`;
const DynamicComponent = defineAsyncComponent(() => import(componentPath));

</script>

<template>
  <div class="slide-container">
    <img class="slide-image" :src="DynamicComponent">
  </div>
</template>

<style scoped>
.slide-image {
  object-fit: contain;
  max-width: 100%;
  max-height: 100%;
}

.slide-container {
  display: flex;
  justify-content: center;
  align-items: center;
  width: 100%;
  height: 100%;
  position: relative;
}
</style>
-->
