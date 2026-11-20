<script setup lang="ts">
import {computed} from 'vue'
import {sheetHtml} from '@/utils/sheetText'

const props = withDefaults(defineProps<{text?: string | null, tag?: string}>(), {
  text: '',
  tag: 'span',
})

const html = computed(() => sheetHtml(props.text))
</script>

<template>
  <component :is="tag" class="scor-text" v-html="html"/>
</template>

<style scoped>
.scor-text :deep(p) {
  margin: 0 0 0.45em;
}

.scor-text :deep(p:last-child) {
  margin-bottom: 0;
}

.scor-text :deep(ul),
.scor-text :deep(ol) {
  margin: 0.2em 0 0.45em;
  padding-left: 1.2em;
}

.scor-text :deep(ul) {
  list-style: disc;
}

.scor-text :deep(ol) {
  list-style: decimal;
}

.scor-text :deep(ul:last-child),
.scor-text :deep(ol:last-child) {
  margin-bottom: 0;
}

.scor-text :deep(a) {
  text-decoration: underline;
}
</style>
