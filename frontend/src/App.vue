<script setup>
import Navigation from "@/components/Navigation.vue";
import {onMounted} from "vue";
import {useRouter} from "vue-router";

const router = useRouter()

onMounted(() => {
  if (window.location.pathname === "/") {
    router.push("/event")
  }
})
</script>

<template>
  <div class="h-screen flex flex-col w-full px-10 font-sans">
    <Navigation/>

    <router-view class="flex-1 overflow-hidden shadow-lg"/>
  </div>
</template>

<style scoped>

</style>
