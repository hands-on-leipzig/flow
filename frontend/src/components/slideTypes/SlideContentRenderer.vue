<script setup lang="ts">
import {computed, ref} from 'vue';
import ImageSlideContentRenderer from './ImageSlideContentRenderer.vue';
import RobotGameSlideContentRenderer from './RobotGameSlideContentRenderer.vue';
import {ImageSlideContent} from "../../models/imageSlideContent.js";
import {Slide} from "../../models/slide.js";
import {RobotGameSlideContent} from "../../models/robotGameSlideContent.js";
import {UrlSlideContent} from "../../models/urlSlideContent.js";
import UrlSlideContentRenderer from "./UrlSlideContentRenderer.vue";
import {PhotoSlideContent} from "../../models/photoSlideContent.js";
import PhotoSlideContentRenderer from "./PhotoSlideContentRenderer.vue";
import {FabricSlideContent} from "../../models/fabricSlideContent.js";
import FabricSlideContentRenderer from "./FabricSlideContentRenderer.vue";

const test = "{\"version\":\"6.7.1\",\"objects\":[{\"fontSize\":24,\"fontWeight\":\"normal\",\"fontFamily\":\"Poppins-Regular\",\"fontStyle\":\"normal\",\"lineHeight\":1.16,\"text\":\"FLOW 1234\\ntest\",\"charSpacing\":0,\"textAlign\":\"left\",\"styles\":[],\"pathStartOffset\":0,\"pathSide\":\"left\",\"pathAlign\":\"baseline\",\"underline\":false,\"overline\":false,\"linethrough\":false,\"textBackgroundColor\":\"\",\"direction\":\"ltr\",\"textDecorationThickness\":66.667,\"minWidth\":20,\"splitByGrapheme\":false,\"type\":\"Textbox\",\"version\":\"6.7.1\",\"originX\":\"left\",\"originY\":\"top\",\"left\":275.1933,\"top\":68.335,\"width\":200,\"height\":58.5792,\"fill\":\"#000000\",\"stroke\":null,\"strokeWidth\":1,\"strokeDashArray\":null,\"strokeLineCap\":\"butt\",\"strokeDashOffset\":0,\"strokeLineJoin\":\"miter\",\"strokeUniform\":false,\"strokeMiterLimit\":4,\"scaleX\":1,\"scaleY\":1,\"angle\":22.6807,\"flipX\":false,\"flipY\":false,\"opacity\":1,\"shadow\":null,\"visible\":true,\"backgroundColor\":\"\",\"fillRule\":\"nonzero\",\"paintFirst\":\"fill\",\"globalCompositeOperation\":\"source-over\",\"skewX\":0,\"skewY\":0},{\"fontSize\":24,\"fontWeight\":\"normal\",\"fontFamily\":\"Poppins-Regular\",\"fontStyle\":\"normal\",\"lineHeight\":1.16,\"text\":\"FLOW\",\"charSpacing\":0,\"textAlign\":\"left\",\"styles\":[],\"pathStartOffset\":0,\"pathSide\":\"left\",\"pathAlign\":\"baseline\",\"underline\":false,\"overline\":false,\"linethrough\":false,\"textBackgroundColor\":\"\",\"direction\":\"ltr\",\"textDecorationThickness\":66.667,\"minWidth\":20,\"splitByGrapheme\":false,\"type\":\"Textbox\",\"version\":\"6.7.1\",\"originX\":\"left\",\"originY\":\"top\",\"left\":104,\"top\":175,\"width\":200,\"height\":27.12,\"fill\":\"#000000\",\"stroke\":null,\"strokeWidth\":1,\"strokeDashArray\":null,\"strokeLineCap\":\"butt\",\"strokeDashOffset\":0,\"strokeLineJoin\":\"miter\",\"strokeUniform\":false,\"strokeMiterLimit\":4,\"scaleX\":1,\"scaleY\":1,\"angle\":0,\"flipX\":false,\"flipY\":false,\"opacity\":1,\"shadow\":null,\"visible\":true,\"backgroundColor\":\"\",\"fillRule\":\"nonzero\",\"paintFirst\":\"fill\",\"globalCompositeOperation\":\"source-over\",\"skewX\":0,\"skewY\":0},{\"rx\":0,\"ry\":0,\"type\":\"Rect\",\"version\":\"6.7.1\",\"originX\":\"left\",\"originY\":\"top\",\"left\":45,\"top\":273,\"width\":100,\"height\":100,\"fill\":\"lightblue\",\"stroke\":null,\"strokeWidth\":1,\"strokeDashArray\":null,\"strokeLineCap\":\"butt\",\"strokeDashOffset\":0,\"strokeLineJoin\":\"miter\",\"strokeUniform\":false,\"strokeMiterLimit\":4,\"scaleX\":1,\"scaleY\":1,\"angle\":0,\"flipX\":false,\"flipY\":false,\"opacity\":1,\"shadow\":null,\"visible\":true,\"backgroundColor\":\"\",\"fillRule\":\"nonzero\",\"paintFirst\":\"fill\",\"globalCompositeOperation\":\"source-over\",\"skewX\":0,\"skewY\":0}],\"background\":\"#ffffff\"}";

const slide = ref<Slide>(
    new Slide(0, "test",
        new FabricSlideContent(test)
    )
);

const componentName = computed(() => {
  const content = slide.value.content;
  if (content instanceof ImageSlideContent) {
    return ImageSlideContentRenderer;
  } else if (content instanceof RobotGameSlideContent) {
    return RobotGameSlideContentRenderer;
  } else if (content instanceof UrlSlideContent) {
    return UrlSlideContentRenderer;
  } else if (content instanceof PhotoSlideContent) {
    return PhotoSlideContentRenderer;
  } else if (content instanceof FabricSlideContent) {
    return FabricSlideContentRenderer;
  }
  // TODO: Add renderers for other subtypes (RobotGameScore, FlowView, etc)
  return null;
})
</script>

<template>
  <div class="slide-content">
    <component :is="componentName" :content="slide.content"></component>
  </div>
</template>

<style scoped>
.slide-content {
  height: 100%;
  overflow: hidden;
}
</style>
