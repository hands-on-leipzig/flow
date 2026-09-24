<script setup lang="ts">
import {shallowRef, onMounted, onUnmounted, ref} from 'vue';
import {FabricImage, StaticCanvas} from 'fabric';
import {SlideContent} from "@/models/slideContent";
import {rewriteImageSrcs} from "@/utils/sameOriginSrc";

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
const backgroundSrc = ref('');
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

  loadSlideBackground();
});

function loadSlideBackground() {
  const background = props.content.background;
  if (!background || !fabricCanvas) return;

  const source = typeof background === 'string' ? parseBackground(background) : background;
  fabricCanvas.loadFromJSON(rewriteImageSrcs(source)).then(() => {
    liftBackgroundToScreen();
  });
}

function liftBackgroundToScreen() {
  const image = fabricCanvas?.backgroundImage;
  if (!(image instanceof FabricImage) || !fabricCanvas) {
    return;
  }
  const {width, height} = image.getOriginalSize();
  const src = image.getSrc();
  if (!(width > 0) || !(height > 0) || !src) {
    return;
  }
  backgroundSrc.value = src;
  fabricCanvas.backgroundImage = undefined;
  fabricCanvas.backgroundColor = '';
  const canvasEl = fabricCanvas.getElement();
  canvasEl.style.background = 'transparent';
  if (fabricCanvas.getObjects().length === 0) {
    canvasEl.style.display = 'none';
  }
  fabricCanvas.requestRenderAll();
}

function parseBackground(background: string) {
  try {
    return JSON.parse(background);
  } catch {
    return background;
  }
}

onMounted(loadFont);

onUnmounted(() => {
  window.removeEventListener('resize', handleResize);
  if (io && root.value) {
    io.unobserve(root.value);
    io.disconnect();
    io = null;
  }
});

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
       class="flex items-center justify-center overflow-hidden">
    <div class="slide-frame">
      <img v-if="backgroundSrc" :src="backgroundSrc" alt="" class="slide-background"/>
      <div class="flex items-center justify-center w-full h-full">
        <canvas ref="canvas"></canvas>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slide-frame {
  position: relative;
  width: 100%;
  height: 100%;
}

.slide-background {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
  pointer-events: none;
}

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