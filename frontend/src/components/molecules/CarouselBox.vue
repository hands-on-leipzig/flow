<script setup lang="ts">
import {imageUrl} from '@/utils/images'
import {RouterLink} from 'vue-router'

</script>

<template>
  <div class="glass-surface-lg flex flex-col items-center">
    <h3 class="text-lg font-semibold mb-4">Präsentation über Bildschirme</h3>
    <img :src="imageUrl('/flow/karussell.png')" alt="Karussell Vorschau" class="h-28 w-auto border"/>
    <div class="flex gap-3 mt-4">
      <RouterLink to="/presentation">
        <button class="glass-btn-secondary !px-3 !py-1 !text-sm">zum Slideshow-Editor</button>
      </RouterLink>
    </div>
  </div>
</template>