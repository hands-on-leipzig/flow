<script setup lang="ts">
import OnlineAccessBox from '@/components/molecules/OnlineAccessBox.vue'
import DuringEventBox from '@/components/molecules/DuringEventBox.vue'

defineOptions({name: 'PublishControl'})
</script>

<template>
  <div class="space-y-6 sm:space-y-8 pb-[max(1rem,env(safe-area-inset-bottom))]">
    <OnlineAccessBox/>
    <DuringEventBox/>
  </div>
</template>
