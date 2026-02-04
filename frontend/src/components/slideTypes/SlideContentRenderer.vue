<script setup lang="ts">
import {computed, onMounted, onUnmounted, watch} from 'vue';
import ImageSlideContentRenderer from './ImageSlideContentRenderer.vue';
import RobotGameSlideContentRenderer from './RobotGameSlideContentRenderer.vue';
import {ImageSlideContent} from "../../models/imageSlideContent.js";
import {Slide} from "../../models/slide.js";
import {RobotGameSlideContent} from "../../models/robotGameSlideContent.js";
import {UrlSlideContent} from "../../models/urlSlideContent.js";
import UrlSlideContentRenderer from "./UrlSlideContentRenderer.vue";
import {FabricSlideContent} from "../../models/fabricSlideContent.js";
import FabricSlideContentRenderer from "./FabricSlideContentRenderer.vue";
import {PublicPlanSlideContent} from "@/models/publicPlanSlideContent";
import PublicPlanSlideContentRenderer from "@/components/slideTypes/PublicPlanSlideContentRenderer.vue";

const props = withDefaults(defineProps<{
  slide: Slide,
  preview: boolean,
  eventId: number,
  defaultTransitionTime?: number
  visible?: boolean
}>(), {
  preview: false,
  visible: false,
});

// Emit: go to the next slide
const emit = defineEmits<{ (e: 'next'): void }>();

const componentName = computed(() => {
  const content = props.slide.content;
  if (content instanceof ImageSlideContent) {
    return ImageSlideContentRenderer;
  } else if (content instanceof RobotGameSlideContent) {
    return RobotGameSlideContentRenderer;
  } else if (content instanceof UrlSlideContent) {
    return UrlSlideContentRenderer;
  } else if (content instanceof FabricSlideContent) {
    return FabricSlideContentRenderer;
  } else if (content instanceof PublicPlanSlideContent) {
    return PublicPlanSlideContentRenderer;
  }
  console.warn("Missing renderer for slide content type:", content);
  return null;
});

const useDefaultAdvance = computed(() => {
  return !(props.slide.content instanceof RobotGameSlideContent);
});

watch(() => props.visible, (vis) => {
  if (vis && useDefaultAdvance.value) {
    startAdvanceTimeout();
  } else {
    clearAdvanceTimeout();
  }
});

let advanceTimeout = null;

function clearAdvanceTimeout() {
  if (advanceTimeout) {
    clearTimeout(advanceTimeout);
    advanceTimeout = null;
  }
}

function startAdvanceTimeout() {
  clearAdvanceTimeout();
  if (props.preview || !useDefaultAdvance.value) {
    return;
  }

  const seconds = props.slide.transition_time || props.defaultTransitionTime || 15;
  advanceTimeout = setTimeout(() => {
    advanceTimeout = null;
    emit('next');
  }, seconds * 1000);
}

onMounted(() => {
  if (props.visible && !props.preview && useDefaultAdvance.value) {
    startAdvanceTimeout();
  }
});

onUnmounted(() => {
  clearAdvanceTimeout();
});
</script>

<template>
  <component :is="componentName" :content="props.slide.content" :preview="props.preview" :eventId="props.eventId"
             @next="emit('next')"></component>
</template>
