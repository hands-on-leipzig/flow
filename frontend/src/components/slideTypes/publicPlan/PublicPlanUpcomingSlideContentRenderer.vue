<script setup lang="ts">
import {ref} from 'vue';
import {PublicPlanUpcomingSlideContent} from '@/models/publicPlanUpcomingSlideContent';
import FabricSlideContentRenderer from '@/components/slideTypes/FabricSlideContentRenderer.vue';
import PublicPlanBoard from '@/components/slideTypes/publicPlan/PublicPlanBoard.vue';
import {usePlanActionWithPolling} from './usePlanAction';

const props = withDefaults(
    defineProps<{
      content: PublicPlanUpcomingSlideContent;
      preview: boolean;
      eventId: number;
      visible?: boolean;
    }>(),
    {preview: false, visible: false}
);

const {result, event} = usePlanActionWithPolling(
    {
      planId: props.content.planId,
      joint: props.content.joint,
      programs: props.content.programs,
      legacyRole: props.content.legacyRole,
      room: props.content.room,
      interval: props.content.interval,
      eventId: props.eventId,
    },
    'upcoming',
    5 * 60 * 1000
);

const board = ref<InstanceType<typeof PublicPlanBoard> | null>(null);

defineExpose({
  handleArrow: (direction: 'left' | 'right') => board.value?.handleArrow(direction) ?? false,
});
</script>

<template>
  <div class="relative w-full h-full overflow-hidden">
    <FabricSlideContentRenderer
        v-if="props.content.background"
        class="absolute inset-0 z-0"
        :content="props.content"
        :preview="props.preview"
    />
    <div class="z-10 relative w-full h-full overflow-hidden" :class="{ preview: props.preview }">
      <PublicPlanBoard
          ref="board"
          :result="result"
          :programs="event?.programs ?? []"
          :active="props.visible && !props.preview"
          :reserve-top="props.content.showSeasonLogo"
          empty-text="Gerade und demnächst keine Programmpunkte"
      />
    </div>
  </div>
</template>

<style scoped>
.preview {
  zoom: 0.15;
}
</style>
