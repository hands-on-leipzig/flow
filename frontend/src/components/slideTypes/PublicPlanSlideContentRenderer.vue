<script setup lang="ts">
import {shallowRef, onMounted, computed} from 'vue';
import {PublicPlanSlideContent} from "@/models/publicPlanSlideContent";

const props = withDefaults(defineProps<{
  content: PublicPlanSlideContent,
  preview: Boolean
}>(), {
  preview: false
});

function getFormattedDateTime() {
  const now = new Date();

  // Get date components
  const year = now.getFullYear();
  const month = String(now.getMonth() + 1).padStart(2, '0'); // Months are 0-based
  const day = String(now.getDate()).padStart(2, '0');

  // Get time components
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');

  return `${year}-${month}-${day}+${hours}:${minutes}`;
}

const planUrl = computed(() => {
  const baseUrl = 'https://dev.flow.hands-on-technology.org/output/zeitplan.cgi';
  const now = getFormattedDateTime();
  const url = baseUrl + `?output=slide&plan=${props.content.planId}&hours=${props.content.hours}&brief=no&role=14`
      + '&now=2026-02-27+12:00'; // <-- testing
  console.log(url);
  return url;
});

</script>

<template>
  <object :data="planUrl"></object>
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