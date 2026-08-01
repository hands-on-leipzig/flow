<script setup lang="ts">
import OnlineAccessBox from '@/components/molecules/OnlineAccessBox.vue'
import DuringEventBox from '@/components/molecules/DuringEventBox.vue'

defineOptions({name: 'PublishControl'})
</script>

<template>
  <div class="space-y-8">
    <OnlineAccessBox/>
    <DuringEventBox/>
  </div>
</template>