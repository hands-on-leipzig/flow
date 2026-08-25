<script setup lang="ts">
import {useSlots} from 'vue'

withDefaults(defineProps<{
  dashed?: boolean
  interactive?: boolean
  inactive?: boolean
  selected?: boolean
}>(), {
  dashed: false,
  interactive: false,
  inactive: false,
  selected: false,
})

const slots = useSlots()
</script>

<template>
  <div
      class="item-card glass-stack-card"
      :class="{
        'glass-stack-card--dashed item-card--dashed': dashed,
        'glass-stack-card--interactive': interactive,
        'item-card--inactive': inactive,
        'item-card--selected': selected,
      }"
  >
    <div class="item-card__chrome">
      <div v-if="slots.leading" class="item-card__leading">
        <slot name="leading"/>
      </div>
      <div class="item-card__title-wrap">
        <slot name="title"/>
      </div>
      <div v-if="slots.trailing" class="item-card__trailing">
        <slot name="trailing"/>
      </div>
    </div>
    <slot/>
  </div>
</template>
