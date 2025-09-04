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

  const width = props.preview ? 256 : 1920;
  const height = props.preview ? 144 : 1080;
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

</script>

<template>
    <canvas ref="canvas" class="content"></canvas>
</template>

<style scoped>
.content {
  width: 100%;
  height: auto;
}
</style>