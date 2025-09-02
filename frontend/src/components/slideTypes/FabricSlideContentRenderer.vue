<script setup lang="ts">
import {shallowRef, onMounted} from 'vue';
import {StaticCanvas} from 'fabric';
import {FabricSlideContent} from "../../models/fabricSlideContent.js";

// const props = defineProps({
//  content
// });

const props = defineProps( {
  content: FabricSlideContent
});

const canvas = shallowRef(null);

onMounted(() => {

  const c = new StaticCanvas(canvas.value, {
    width: 1920,
    height: 1080,
    backgroundColor: '#ffffff'
  });
  c.loadFromJSON(props.content.json).then(() => {
    c.requestRenderAll();
  });
});

</script>

<template>
  <div class="border-grey">
    <canvas ref="canvas"></canvas>
  </div>
</template>

<style scoped>

</style>