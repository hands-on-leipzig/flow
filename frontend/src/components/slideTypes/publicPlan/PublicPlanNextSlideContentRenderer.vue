<script setup lang="ts">
import {PublicPlanNextSlideContent} from '@/models/publicPlanNextSlideContent';
import FabricSlideContentRenderer from '@/components/slideTypes/FabricSlideContentRenderer.vue';
import PublicPlanTable from '@/components/slideTypes/publicPlan/PublicPlanTable.vue';
import {usePlanActionWithPolling} from './usePlanAction';

const props = withDefaults(
    defineProps<{
      content: PublicPlanNextSlideContent;
      preview: boolean;
    }>(),
    {preview: false}
);

const {result} = usePlanActionWithPolling(
    {
      planId: props.content.planId,
      role: props.content.role,
      interval: props.content.interval,
    },
    'next',
    5 * 60 * 1000
);
</script>

<template>
  <div class="relative w-full h-full overflow-hidden">
    <FabricSlideContentRenderer
        v-if="props.content.background"
        class="absolute inset-0 z-0"
        :content="props.content"
        :preview="props.preview"
    />
    <div
        class="z-10 relative w-full h-full min-h-0 overflow-hidden"
        :class="{ preview: props.preview }"
    >
      <div v-if="result" class="result">
        <div
            class="flex flex-row items-center justify-center w-full h-full min-h-0"
            :class="{ 'min-h-screen': !props.preview, 'min-h-100': props.preview }"
        >
          <PublicPlanTable :result="result"/>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

.result {
  width: 100%;
  height: 100%;
  margin: 0;
  overflow: hidden;
  background: transparent;
}

.preview {
  zoom: 0.15;
  height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.min-h-100 {
  min-height: 100%;
}
</style>
