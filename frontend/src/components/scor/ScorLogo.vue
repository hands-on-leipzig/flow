<script setup lang="ts">
import {imageUrl} from '@/utils/images'

const word = imageUrl('/flow/scor.png')
const mark = imageUrl('/flow/wise-mark.png')
</script>

<template>
  <img class="scor-logo scor-logo--word glass-sidebar__brand-logo" :src="word" alt="SCOR">
  <img class="scor-logo scor-logo--mark glass-sidebar__brand-logo" :src="mark" alt="">
</template>
