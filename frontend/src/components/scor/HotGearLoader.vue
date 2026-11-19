<script setup lang="ts">
import {imageUrl} from '@/utils/images'

const base = imageUrl('/flow/hot_loader_base.png')
const gear = imageUrl('/flow/hot_loader_gear.png')
</script>

<template>
  <Teleport to="body">
    <div class="hot-loader" role="status" aria-label="Laden">
      <div class="hot-loader__mark">
        <img class="hot-loader__base" :src="base" alt="">
        <img class="hot-loader__gear" :src="gear" alt="">
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.hot-loader {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: center;
  justify-content: center;
  background: color-mix(in srgb, #e8eef5 42%, transparent);
}

.hot-loader__mark {
  position: relative;
  width: 8.5rem;
  height: 8.5rem;
  filter: drop-shadow(0 12px 22px color-mix(in srgb, #f15a24 30%, transparent));
}

.hot-loader__base,
.hot-loader__gear {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.hot-loader__gear {
  animation: hot-gear 2.4s linear infinite;
}

@keyframes hot-gear {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .hot-loader__gear {
    animation: none;
  }
}
</style>
