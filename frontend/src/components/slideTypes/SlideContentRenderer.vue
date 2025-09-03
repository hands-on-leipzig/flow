<script setup lang="ts">
import {computed, onMounted, ref} from 'vue';
import ImageSlideContentRenderer from './ImageSlideContentRenderer.vue';
import RobotGameSlideContentRenderer from './RobotGameSlideContentRenderer.vue';
import {ImageSlideContent} from "../../models/imageSlideContent.js";
import {Slide} from "../../models/slide.js";
import {RobotGameSlideContent} from "../../models/robotGameSlideContent.js";
import {UrlSlideContent} from "../../models/urlSlideContent.js";
import UrlSlideContentRenderer from "./UrlSlideContentRenderer.vue";
import {PhotoSlideContent} from "../../models/photoSlideContent.js";
import PhotoSlideContentRenderer from "./PhotoSlideContentRenderer.vue";
import {FabricSlideContent} from "../../models/fabricSlideContent.js";
import FabricSlideContentRenderer from "./FabricSlideContentRenderer.vue";
import {PublicPlanSlideContent} from "@/models/publicPlanSlideContent";
import PublicPlanSlideContentRenderer from "@/components/slideTypes/PublicPlanSlideContentRenderer.vue";

const props = withDefaults(defineProps<{
  slide: Slide,
  preview: Boolean
}>(), {
  preview: false
});

const componentName = computed(() => {
  const content = props.slide.content;
  if (content instanceof ImageSlideContent) {
    return ImageSlideContentRenderer;
  } else if (content instanceof RobotGameSlideContent) {
    return RobotGameSlideContentRenderer;
  } else if (content instanceof UrlSlideContent) {
    return UrlSlideContentRenderer;
  } else if (content instanceof PhotoSlideContent) {
    return PhotoSlideContentRenderer;
  } else if (content instanceof FabricSlideContent) {
    return FabricSlideContentRenderer;
  } else if (content instanceof PublicPlanSlideContent) {
    return PublicPlanSlideContentRenderer;
  }
  console.warn("Missing renderer for slide content type:", content);
  return null;
})
</script>

<template>
  <component :is="componentName" :content="props.slide.content" :preview="props.preview"></component>
</template>
