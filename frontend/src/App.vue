<script setup>
import {computed, defineAsyncComponent, onMounted, ref, watch} from "vue";
import {useRoute, useRouter} from "vue-router";
import axios from "axios";

const Navigation = defineAsyncComponent(() => import('@/components/Navigation.vue'));
const NewsModal = defineAsyncComponent(() => import('@/components/atoms/NewsModal.vue'));
const EventDayBanner = defineAsyncComponent(() => import('@/components/atoms/EventDayBanner.vue'));
const GlassToast = defineAsyncComponent(() => import('@/components/atoms/GlassToast.vue'));

// Check if current route is public (no navigation needed)
const isPublicRoute = computed(() => {
  return route.meta?.public === true
})

/** Slim plan pop-out / public surfaces: no app chrome. */
const isChromeLess = computed(() => isPublicRoute.value || route.meta?.popout === true)

/** Blank canvas (no orbit/pe-page styling) when a route opts in via meta.plain. */
const isPlainSurface = computed(() => route.meta?.plain === true)

/** Panel fills viewport height so inner panes scroll (admin tools, Ablauf split, Ausgabe splits). */
const isPanelFillRoute = computed(() => {
  const path = route.path.replace(/\/$/, '')
  return path.startsWith('/plan/admin')
    || path.startsWith('/plan/schedule')
    || path === '/plan/publish'
    || path === '/plan/publish/logos'
    || path.startsWith('/plan/volunteers/roster')
})

const router = useRouter();
const route = useRoute();

// News Modal State
const currentNews = ref(null)
const showNewsModal = ref(false)

// Check for unread news
const checkForUnreadNews = async () => {
  // Only check for authenticated, non-public routes
  if (isPublicRoute.value || route.meta?.popout === true) {
    return
  }

  try {
    const response = await axios.get('/news/unread')
    // Check if response.data exists and has an id (not null)
    if (response.data && response.data.id) {
      console.log('Unread news received:', response.data)
      currentNews.value = response.data
      showNewsModal.value = true
    } else {
      console.log('No unread news')
    }
  } catch (error) {
    // Silently fail - news check should not disrupt user experience
    console.error('Failed to check for unread news:', error)
  }
}

// Mark news as read and close modal
const markNewsAsRead = async (newsId) => {
  if (!newsId) {
    console.error('markNewsAsRead called without newsId', { newsId, currentNews: currentNews.value })
    return
  }

  try {
    await axios.post(`/news/${newsId}/mark-read`)
    showNewsModal.value = false
    currentNews.value = null
  } catch (error) {
    console.error('Failed to mark news as read:', error)
    // Still close modal even if marking failed
    showNewsModal.value = false
    currentNews.value = null
  }
}

// Watch for route changes and check for unread news
watch(() => route.path, async () => {
  if (!isPublicRoute.value && route.meta?.popout !== true) {
    await checkForUnreadNews()
  }
})

onMounted(() => {
  if (window.location.pathname === "/") {
    router.push("/overview")
  }
})
</script>

<template>
  <div
    v-if="isChromeLess"
    class="min-h-dvh w-full font-sans"
    :class="{ 'liquid-surface-scope pe-page': !isPlainSurface }"
  >
    <router-view/>
  </div>

  <Navigation v-else class="font-sans">
    <EventDayBanner/>
    <div
      class="glass-app__panel liquid-surface"
      :class="{ 'glass-app__panel--fill': isPanelFillRoute }"
    >
      <router-view/>
    </div>

    <NewsModal
        v-if="showNewsModal && currentNews"
        :news="currentNews"
        @markRead="markNewsAsRead"
    />
  </Navigation>

  <GlassToast/>
</template>

<style scoped>
.glass-app__panel--fill {
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.glass-app__panel--fill > :deep(*) {
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
}
</style>
