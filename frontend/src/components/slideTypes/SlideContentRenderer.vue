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
import {PublicPlanNextEventSlideContent} from "../../models/publicPlanNextEventSlideContent";
import PublicPlanNextEventSlideContentRenderer from "./publicPlan/PublicPlanNextEventSlideContentRenderer.vue";
import {seasonLogoPlacement, SEASON_LOGO_HEIGHT, SEASON_LOGO_WIDTH} from "@/models/seasonLogo";

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
const root = ref<HTMLElement | null>(null);
const frame = ref({width: 0, height: 0});
const DESIGN_WIDTH = 800;
const DESIGN_HEIGHT = 450;
let resizeObserver: ResizeObserver | null = null;

function measureFrame() {
  const rect = root.value?.getBoundingClientRect();
  frame.value = {
    width: rect?.width ?? 0,
    height: rect?.height ?? 0,
  };
}

const seasonLogoStyle = computed(() => {
  if (!props.slide.content?.showSeasonLogo) {
    return null;
  }
  if (frame.value.width <= 0 || frame.value.height <= 0) {
    return null;
  }
  const width = props.preview ? 238 : frame.value.width;
  const height = props.preview ? 134 : frame.value.height;
  const zoom = Math.min(width / DESIGN_WIDTH, height / DESIGN_HEIGHT);
  if (zoom === 0) {
    return null;
  }
  const place = seasonLogoPlacement(props.slide.content.background);
  const boxWidth = DESIGN_WIDTH * zoom;
  const boxHeight = DESIGN_HEIGHT * zoom;
  const canvasLeft = (frame.value.width - boxWidth) / 2;
  const canvasTop = (frame.value.height - boxHeight) / 2;
  return {
    left: `${canvasLeft + place.centerX * zoom}px`,
    top: `${canvasTop + place.top * zoom}px`,
    width: `${SEASON_LOGO_WIDTH * place.scaleX * zoom}px`,
    height: `${SEASON_LOGO_HEIGHT * place.scaleY * zoom}px`,
  };
});

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
  } else if (content instanceof PublicPlanNextEventSlideContent) {
    return PublicPlanNextEventSlideContentRenderer;
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
  measureFrame();
  if (root.value && window.ResizeObserver) {
    resizeObserver = new ResizeObserver(() => measureFrame());
    resizeObserver.observe(root.value);
  } else {
    window.addEventListener('resize', measureFrame);
  }
  if (props.visible && !props.preview && useDefaultAdvance.value) {
    startAdvanceTimeout();
  }
});

onUnmounted(() => {
  resizeObserver?.disconnect();
  resizeObserver = null;
  window.removeEventListener('resize', measureFrame);
  clearAdvanceTimeout();
});
</script>

<template>
  <div ref="root" class="relative w-full h-full">
    <component ref="renderer" :is="componentName" :content="props.slide.content" :preview="props.preview"
               :eventId="props.eventId" :visible="props.visible"
               @next="emit('next')"></component>
    <img
        v-if="seasonLogoStyle"
        class="season-logo"
        src="/logo.png"
        alt=""
        :style="seasonLogoStyle"
    />
  </div>
</template>

<style scoped>
.season-logo {
  position: absolute;
  z-index: 50;
  transform: translateX(-50%);
  pointer-events: none;
}
</style>
