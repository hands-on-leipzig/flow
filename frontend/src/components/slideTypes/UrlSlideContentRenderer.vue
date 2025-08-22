<script setup>
import {UrlSlideContent} from "../../model/urlSlideContent.js";

const props = defineProps({
  content: UrlSlideContent,
});

</script>

<template>
    <object :data="props.content.url"></object>
</template>

<style scoped>
object {
  width: 100%;
  height: 100%;
  margin: 0;
  position: relative;
  overflow: hidden;
}
</style>
