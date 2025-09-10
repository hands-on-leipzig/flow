<script setup lang="ts">
import {shallowRef, onMounted} from 'vue';
import {StaticCanvas} from 'fabric';
import {SlideContent} from "@/models/slideContent";

const props = withDefaults(defineProps<{
  content: SlideContent,
  preview: Boolean
}>(), {
  preview: false
});

const canvas = shallowRef(null);

onMounted(() => {

  const width = props.preview ? 238 : 1920;
  const height = props.preview ? 134 : 1080;
  const zoom = props.preview ? 0.25 : 2;
  const c = new StaticCanvas(canvas.value, {
    width,
    height,
    backgroundColor: '#ffffff'
  });
  c.setZoom(zoom);
  c.loadFromJSON(props.content.background).then(() => {
    c.requestRenderAll();
  });
});

onMounted(loadFont);

function loadFont() {
  const font = new FontFace('Uniform', 'url(/fonts/Uniform-Regular.otf)');
  font.load().catch((e) => {
    console.error('Font loading failed', e);
  });
}

</script>

<template>
    <canvas ref="canvas" class="content"></canvas>
</template>

<style scoped>
.content {
  width: 100%;
  height: auto;
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