<script setup>
import {computed, defineAsyncComponent, onMounted} from "vue";
import {useRoute, useRouter} from "vue-router";

const Navigation = defineAsyncComponent(() => import('@/components/Navigation.vue'));

const PATHS_HIDE_NAVIGATION = [
  "/carousel"
]

const router = useRouter();
const route = useRoute();

const showNavigation = computed(() => {
  return !PATHS_HIDE_NAVIGATION.some(path => route.path.startsWith(path));
})

onMounted(() => {
  if (window.location.pathname === "/") {
    router.push("/event")
  }
})
</script>

<template>
  <div class="h-screen flex flex-col w-full font-sans" :class="{ 'px-10': showNavigation }">
    <Navigation v-if="showNavigation"/>

    <router-view class="flex-1 shadow-lg"/>
  </div>
</template>

<style scoped>

</style>
