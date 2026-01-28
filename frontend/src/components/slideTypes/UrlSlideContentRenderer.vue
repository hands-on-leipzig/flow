<script setup lang="ts">
import {UrlSlideContent} from "@/models/urlSlideContent.js";
import FabricSlideContentRenderer from "@/components/slideTypes/FabricSlideContentRenderer.vue";

const props = withDefaults(defineProps<{
  content: UrlSlideContent,
  preview: boolean
}>(), {
  preview: false
});

</script>

<template>
  <div class="relative w-full h-full overflow-hidden">

    <FabricSlideContentRenderer v-if="props.content.background"
                                class="absolute inset-0 z-0"
                                :content="props.content" :preview="props.preview"></FabricSlideContentRenderer>

    <object :data="props.content.url" :class="{'preview': props.preview}" class="relative z-0"></object>
  </div>
</template>

<style scoped>
object {
  width: 100%;
  height: 100%;
  margin: 0;
  position: relative;
  overflow: hidden;
  z-index: 0;
  pointer-events: auto;
}

.preview {
  zoom: 0.15;
  height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
</style>
