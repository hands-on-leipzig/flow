<script setup>
import {computed} from 'vue';
import ImageSlideContentRenderer from './ImageSlideContentRenderer.vue';
import RobotGameSlideContentRenderer from './RobotGameSlideContentRenderer.vue';
import {ImageSlideContent} from "../../model/imageSlideContent.js";
import {Slide} from "../../model/slide.js";
import {RobotGameSlideContent} from "../../model/robotGameSlideContent.js";
import {UrlSlideContent} from "../../model/urlSlideContent.js";
import UrlSlideContentRenderer from "./UrlSlideContentRenderer.vue";
import {PhotoSlideContent} from "../../model/photoSlideContent.js";
import PhotoSlideContentRenderer from "./PhotoSlideContentRenderer.vue";

const props = defineProps({
  slide: Slide
});

const componentName = computed(() => {
  if (props.slide.content instanceof ImageSlideContent) {
    return ImageSlideContentRenderer;
  } else if (props.slide.content instanceof RobotGameSlideContent) {
    return RobotGameSlideContentRenderer;
  } else if (props.slide.content instanceof UrlSlideContent) {
    return UrlSlideContentRenderer;
  } else if (props.slide.content instanceof PhotoSlideContent) {
    return PhotoSlideContentRenderer
  }
  // TODO: Add renderers for other subtypes (RobotGameScore, FlowView, etc)
  return null;
})
</script>

<template>
  <div class="slide-content">
    <component :is="componentName" :content="slide.content"></component>
  </div>
</template>

<style scoped>
.slide-content {
  height: 100%;
  overflow: hidden;
}
</style>
