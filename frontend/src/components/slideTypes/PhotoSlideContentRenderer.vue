<script setup>
import {onMounted, ref} from 'vue';

const imageUrl = ref('');

async function getRandomImage() {
  try {
    const response = await fetch('/api/events/1/randomphoto');
    //const randomImage = imagePaths[Math.floor(Math.random() * imagePaths.length)];
    imageUrl.value = await response.json();
  } catch (error) {
    console.error('Error fetching images:', error);
  }
}

onMounted(getRandomImage);
</script>

<template>
  <img :src="'/public' + imageUrl" alt="Slide Image" class="slide-image">
</template>

<style scoped>
.slide-image {
  min-width: 100vw;
  min-height: 100vh;
  margin: auto auto;
  position: relative;
  overflow: hidden;
}

.slide-container {
  display: flex;
  justify-content: center;
  align-items: center;
  width: 100%;
  height: 100%;
  position: relative;
}
</style>