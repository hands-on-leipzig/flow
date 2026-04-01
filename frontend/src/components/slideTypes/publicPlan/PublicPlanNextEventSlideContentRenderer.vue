<script setup lang="ts">
import {usePlanActionWithPolling} from "./usePlanAction";
import {PublicPlanNextEventSlideContent} from "../../../models/publicPlanNextEventSlideContent";
import FabricSlideContentRenderer from "../FabricSlideContentRenderer.vue";
import {computed} from "vue";
import {formatTimeOnly} from "@/utils/dateTimeFormat";

const props = withDefaults(
    defineProps<{
      content: PublicPlanNextEventSlideContent;
      preview: boolean;
      eventId: number;
    }>(),
    {preview: false}
);

const {result} = usePlanActionWithPolling(
    {
      planId: props.content.planId,
      role: props.content.role,
      room: props.content.room,
      interval: props.content.interval,
      eventId: props.eventId,
    },
    'next',
    5 * 60 * 1000
);

const nextEvent = computed(() => {
  if (!result.value?.groups?.[0]) {
    return null;
  }

  const firstGroup = result.value.groups[0];
  const activities = firstGroup.activities;
  if (!activities || Object.keys(activities).length === 0) {
    return null;
  }

  const firstActivityKey = Object.keys(activities)[0];
  const activity = activities[firstActivityKey];

  return {
    startTime: activity.start_time,
    name: firstGroup.group_meta.name || activity.activity_name || 'Unbekannt'
  };
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
    <div class="relative z-10 w-full h-full flex flex-col items-center justify-center" :class="props.preview ? 'text-size-sm' : 'text-4xl md:text-6xl'">
      <div v-if="nextEvent" class="text-center">
        <div class="font-bold mb-6 md:mb-10">
          Als nächstes folgt
        </div>
        <div class="font-semibold">
          {{ formatTimeOnly(nextEvent.startTime) }} {{ nextEvent.name }}
        </div>
      </div>
      <div v-else class="text-center opacity-70">
        Keine weiteren Programmpunkte verfügbar
      </div>
    </div>
  </div>
</template>


<style scoped>
div {
  box-sizing: border-box;
}

</style>