<script setup lang="ts">
import {onMounted, onUnmounted, ref, watch} from "vue";
import SlideContentRenderer from "./slideTypes/SlideContentRenderer.vue";
import {Slide} from "../models/slide.js";
import axios from "axios";
import {useAutoHideCursor} from "../composables/useAutoHideCursor";
import {beatDisplay, isPlannerPreview} from "@/utils/usageCapture";
import {setPreviewClock} from "@/components/slideTypes/publicPlan/usePlanAction";

// TODO Socket injector
/*
const socket = inject('websocket');
socket.registerClient();
socket.addListener((msg) => {
  if (msg.type === 'pushSlide') {
    slide.value = Slide.fromObject(msg.slide);
    showSlide.value = true
  }
}); */

const props = defineProps<{
  eventId: number
}>();

const plannerPreview = isPlannerPreview();
const clockMode = ref<'current' | 'override'>('current');
const previewTime = ref('');

function onPreviewTime(event: Event) {
  previewTime.value = (event.target as HTMLInputElement).value;
  if (clockMode.value === 'override') {
    setPreviewClock(previewTime.value || null);
  }
}

watch(clockMode, (mode) => {
  setPreviewClock(mode === 'override' ? (previewTime.value || null) : null);
});

let slideshow = ref(null)
let showSlide = ref(false)
let slideKey = ref(0)

const container = ref<HTMLElement>(null);
const renderers = ref([]);

useAutoHideCursor(container, 3000);

let heartbeatTimer: ReturnType<typeof setInterval> | null = null

function beat() {
  beatDisplay(+props.eventId)
}

function onVisibility() {
  if (document.visibilityState === 'visible') beat()
}

function startHeartbeat() {
  if (isPlannerPreview()) return
  beat()
  heartbeatTimer = setInterval(beat, 300000)
  document.addEventListener('visibilitychange', onVisibility)
}

function stopHeartbeat() {
  if (heartbeatTimer) {
    clearInterval(heartbeatTimer)
    heartbeatTimer = null
  }
  document.removeEventListener('visibilitychange', onVisibility)
  if (!isPlannerPreview() && document.visibilityState === 'visible') beat()
}

async function fetchSlides() {
  const response = await axios.get(`/carousel/${props.eventId}/slideshows`);
  if (response && response.data) {
    for (const resShow of response.data) {
      const slides = [];
      for (let slide of resShow.slides) {
        slides.push(Slide.fromObject(slide));
      }
      resShow.slides = slides;
      slideshow.value = resShow;
      showSlide.value = !!slides[0];
    }
  }
}

function nextSlide() {
  if (!slideshow.value?.slides?.length) {
    return;
  }
  let i = slideKey.value + 1;
  if (i >= slideshow.value.slides.length) {
    i = 0;
  }
  slideKey.value = i;
}

function prevSlide() {
  if (!slideshow.value?.slides?.length) {
    return;
  }
  let i = slideKey.value - 1;
  if (i < 0) {
    i = slideshow.value.slides.length - 1;
  }
  slideKey.value = i;
}

function handleKeyDown(event) {
  const wrapper = renderers.value?.[slideKey.value];
  if (!wrapper) return;

  const direction = event.key === 'ArrowRight' ? 'right' : event.key === 'ArrowLeft' ? 'left' : null;
  if (!direction) return;

  let handled = false;
  if (typeof wrapper.handleArrow === 'function') {
    handled = wrapper.handleArrow(direction);
  }

  if (!handled) {
    if (direction === 'right') {
      nextSlide();
    } else if (direction === 'left') {
      prevSlide();
    }
  }
}

function startFetchingSlides() {
  /*setInterval(function () {
    fetchSlides()
  }, 300000)*/
}

onMounted(startFetchingSlides)
onMounted(fetchSlides)
onMounted(startHeartbeat)

onMounted(() => window.addEventListener('keydown', handleKeyDown));
onUnmounted(() => window.removeEventListener('keydown', handleKeyDown));
onUnmounted(stopHeartbeat);

</script>

<template>
  <div v-if="showSlide" ref="container" id="container" class="h-screen w-full">
    <SlideContentRenderer
        v-for="(slide, index) in slideshow?.slides" :key="index" v-show="index === slideKey"
        ref="renderers"
        :slide="slide" :preview="false" :eventId="+props.eventId" :visible="index === slideKey"
        :defaultTransitionTime="slideshow?.transition_time" @next="nextSlide"/>
    <fieldset v-if="plannerPreview" class="test-clock">
      <legend>Test-Uhrzeit</legend>
      <label>
        <input type="radio" name="test-clock" value="current" v-model="clockMode"/>
        Aktuelle Zeit
      </label>
      <label>
        <input type="radio" name="test-clock" value="override" v-model="clockMode"/>
        Übersteuern
        <input
            type="time"
            :value="previewTime"
            :disabled="clockMode !== 'override'"
            @change="onPreviewTime"
        />
      </label>
    </fieldset>
  </div>
</template>

<style scoped>
.hide-cursor {
  cursor: none;
}

footer {
  background-color: white;
  width: 100%;
  height: 10vh;
  position: fixed;
  z-index: 10000;
  bottom: 0;
  display: flex;
  align-items: center;
  justify-content: space-evenly;
}

footer div {
  padding-left: 2rem;
  padding-right: 2rem;
}

footer img {
  max-height: 9vh;
}

.test-clock {
  position: fixed;
  right: 1rem;
  bottom: 1rem;
  z-index: 10001;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin: 0;
  padding: 0.5rem 0.75rem;
  border: 0;
  background: white;
  border-radius: 0.5rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.test-clock legend {
  padding: 0 0.25rem;
}

.test-clock label {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.test-clock input[type="time"]:disabled {
  opacity: 0.45;
}
</style>
