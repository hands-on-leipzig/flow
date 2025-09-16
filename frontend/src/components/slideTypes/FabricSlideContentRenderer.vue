<script setup lang="ts">
import {shallowRef, onMounted, onUnmounted} from 'vue';
import {StaticCanvas} from 'fabric';
import {SlideContent} from "@/models/slideContent";

const DEFAULT_WIDTH = 800;
const DEFAULT_HEIGHT = 450;

const props = withDefaults(defineProps<{
  content: SlideContent,
  preview: boolean
}>(), {
  preview: false
});

const root = shallowRef<HTMLElement | null>(null);
let io: IntersectionObserver | null = null;

const canvas = shallowRef(null);
let fabricCanvas: StaticCanvas | null = null;

onMounted(() => {

  fabricCanvas = new StaticCanvas(canvas.value, {
    width: DEFAULT_WIDTH,
    height: DEFAULT_HEIGHT,
    backgroundColor: '#ffffff'
  });

  handleResize();
  window.addEventListener('resize', handleResize);

  // Intersection Observer um Resize bei Sichtbarkeit auszulösen (Carousel)
  io = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting && entry.target === root.value) { // true -> Element aktuell in Viewport sichtbar (Folie aktiv)
        requestAnimationFrame(() => handleResize());
      }
    }
  }, {threshold: 0.01});
  io.observe(root.value);

  if (props.content.background) {
    fabricCanvas.loadFromJSON(props.content.background).then(() => {
      fabricCanvas.requestRenderAll();
    });
  }
});

onMounted(loadFont);

onUnmounted(() => {
  window.removeEventListener('resize', handleResize);
  if (io && root.value) {
    io.unobserve(root.value);
    io.disconnect();
    io = null;
  }
})

function handleResize() {
  if (!canvas.value || !fabricCanvas) return;

  const container = canvas.value.parentElement;
  const rect = container.getBoundingClientRect();

  let width = props.preview ? 238 : rect.width;
  let height = props.preview ? 134 : rect.height;

  const zoom = Math.min(width / DEFAULT_WIDTH, height / DEFAULT_HEIGHT);

  const displayW = Math.round(DEFAULT_WIDTH * zoom);
  const displayH = Math.round(DEFAULT_HEIGHT * zoom);

  if (width > 0 && height > 0) {
    fabricCanvas.setDimensions({width: displayW, height: displayH});
    fabricCanvas.setZoom(zoom);

    fabricCanvas.requestRenderAll();
  }
}

function loadFont() {
  const font = new FontFace('Uniform', 'url(/fonts/Uniform-Regular.otf)');
  font.load().catch((e) => {
    console.error('Font loading failed', e);
  });
}

</script>

<template>
  <div ref="root" :class="{ 'w-screen h-screen': !preview }"
       class="flex items-center justify-center bg-gray-100 overflow-hidden">
    <div class="flex items-center justify-center w-full h-full">
      <canvas ref="canvas" class=""></canvas>
    </div>
  </div>
</template>

<style scoped>

@font-face {
  font-family: 'Uniform';
  src: url('/fonts/Uniform-Regular.otf') format('otf');
  font-weight: normal;
  font-style: normal;
}

@font-face {
  font-family: 'Uniform';
  src: url('/fonts/Uniform-Bold.otf') format('otf');
  font-weight: bold;
  font-style: normal;
}

</style>