<script setup lang="ts">
import {computed} from 'vue'
import {sanitizeOfficialHtml} from '@/utils/eventPrograms'

const props = withDefaults(
  defineProps<{
    html?: string | null
  }>(),
  {
    html: '',
  }
)

const sanitized = computed(() => sanitizeOfficialHtml(props.html || ''))
</script>

<template>
  <span v-if="sanitized" class="program-official-name" v-html="sanitized"/>
</template>
