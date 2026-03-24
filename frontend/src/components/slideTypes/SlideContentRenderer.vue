<script setup lang="ts">
import {computed, onMounted, onUnmounted, watch, ref} from 'vue';
import ImageSlideContentRenderer from './ImageSlideContentRenderer.vue';
import RobotGameSlideContentRenderer from './robotGame/RobotGameSlideContentRenderer.vue';
import {ImageSlideContent} from "../../models/imageSlideContent.js";
import {Slide} from "../../models/slide.js";
import {RobotGameSlideContent} from "../../models/robotGameSlideContent.js";
import {UrlSlideContent} from "../../models/urlSlideContent.js";
import UrlSlideContentRenderer from "./UrlSlideContentRenderer.vue";
import {FabricSlideContent} from "../../models/fabricSlideContent.js";
import FabricSlideContentRenderer from "./FabricSlideContentRenderer.vue";
import {PublicPlanSlideContent} from '@/models/publicPlanSlideContent';
import {PublicPlanNextSlideContent} from '@/models/publicPlanNextSlideContent';
import PublicPlanSlideContentRenderer from '@/components/slideTypes/publicPlan/PublicPlanSlideContentRenderer.vue';
import PublicPlanNextSlideContentRenderer
  from '@/components/slideTypes/publicPlan/PublicPlanNextSlideContentRenderer.vue';
import {TeamsMapSlideContent} from "../../models/teamsMapSlideContent";
import TeamsMapSlideContentRenderer from "./teams/TeamsMapSlideContentRenderer.vue";
import {TeamsTableSlideContent} from "../../models/teamsTableSlideContent";
import TeamsTableSlideContentRenderer from "./teams/TeamsTableSlideContentRenderer.vue";

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

const renderer = ref(null);

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
  } else if (content instanceof PublicPlanNextSlideContent) {
    return PublicPlanNextSlideContentRenderer;
  } else if (content instanceof TeamsMapSlideContent) {
    return TeamsMapSlideContentRenderer;
  } else if (content instanceof TeamsTableSlideContent) {
    return TeamsTableSlideContentRenderer;
  }
  console.warn("Missing renderer for slide content type:", content);
  return null;
});

const useDefaultAdvance = computed(() => {
  return !(props.slide.content instanceof RobotGameSlideContent)
      && !(props.slide.content instanceof TeamsTableSlideContent);
});

watch(() => props.visible, (vis) => {
  if (vis && useDefaultAdvance.value) {
    startAdvanceTimeout();
  } else {
    clearAdvanceTimeout();
  }
});

function handleArrow(direction: 'left' | 'right'): boolean {
  if (renderer.value?.handleArrow) {
    return renderer.value.handleArrow(direction);
  }
  return false;
}

defineExpose({handleArrow});

let advanceTimeout: any = null;

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
  <component ref="renderer" :is="componentName" :content="props.slide.content" :preview="props.preview"
             :eventId="props.eventId" :visible="props.visible"
             @next="emit('next')"></component>
</template>
