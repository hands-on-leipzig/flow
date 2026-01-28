<script setup lang="ts">
import {computed, onMounted, shallowRef} from 'vue';
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
  transitionTime?: number
}>(), {
  preview: false
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

const root = shallowRef<HTMLElement | null>(null);
let io: IntersectionObserver | null = null;
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
  // TODO: Hier slide.transitionTime verwenden
  const seconds = props.transitionTime ?? 15;
  console.log(props.transitionTime, seconds);
  if (!seconds || seconds <= 0) {
    return;
  }
  advanceTimeout = setTimeout(() => {
    console.log('next slide');
    advanceTimeout = null;
    emit('next');
  }, seconds * 1000);
}

onMounted(() => {
  io = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.target === root.value) {
        if (entry.isIntersecting) {
          // visible -> start timer
          startAdvanceTimeout();
        } else {
          // not visible -> stop timer
          clearAdvanceTimeout();
        }
      }
    }
  }, { threshold: 0.01 });

  if (root.value && io) {
    io.observe(root.value);
  }
});
</script>

<template>
  <div ref="root">
    <component :is="componentName" :content="props.slide.content" :preview="props.preview" :eventId="props.eventId" @next="emit('next')"></component>
  </div>
</template>
