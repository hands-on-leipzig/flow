<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PublicEventFrame, publicEventPathFromUrl } from '@hands-on/glass/venues'

const route = useRoute()
const router = useRouter()

const src = computed(() => {
  const fromQuery = typeof route.query.src === 'string' ? route.query.src.trim() : ''
  if (fromQuery) return fromQuery
  const path = String(route.params.publicPath || '').replace(/^\/+/, '')
  return path ? `${window.location.origin}/${path}` : ''
})

const title = computed(() => {
  const fromQuery = typeof route.query.title === 'string' ? route.query.title.trim() : ''
  if (fromQuery) return fromQuery
  return publicEventPathFromUrl(src.value)
})

function goBack() {
  if (window.history.length > 1) router.back()
  else router.push({ name: 'plan-venues' })
}
</script>

<template>
  <PublicEventFrame :src="src" :title="title" @back="goBack" />
</template>
