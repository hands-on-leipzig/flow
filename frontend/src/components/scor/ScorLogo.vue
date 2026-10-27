<script setup lang="ts">
import {imageUrl} from '@/utils/images'

const src = imageUrl('/flow/scor.png')
</script>

<template>
  <img class="scor-logo glass-sidebar__brand-logo" :src="src" alt="SCOR">
</template>
