<script setup>
import {computed, defineAsyncComponent, onMounted, ref, watch} from "vue";
import {useRoute, useRouter} from "vue-router";
import axios from "axios";

const Navigation = defineAsyncComponent(() => import('@/components/Navigation.vue'));
const NewsModal = defineAsyncComponent(() => import('@/components/atoms/NewsModal.vue'));

const PATHS_HIDE_NAVIGATION = [
  "/carousel"
]

// Check if current route is public (no navigation needed)
const isPublicRoute = computed(() => {
  return route.meta?.public === true
})

const router = useRouter();
const route = useRoute();

const showNavigation = computed(() => {
  return !PATHS_HIDE_NAVIGATION.some(path => route.path.startsWith(path)) && !isPublicRoute.value
})

// News Modal State
const currentNews = ref(null)
const showNewsModal = ref(false)

// Check for unread news
const checkForUnreadNews = async () => {
  // Only check for authenticated, non-public routes
  if (isPublicRoute.value) {
    return
  }

  try {
    const response = await axios.get('/news/unread')
    if (response.data) {
      currentNews.value = response.data
      showNewsModal.value = true
    }
  } catch (error) {
    // Silently fail - news check should not disrupt user experience
    console.error('Failed to check for unread news:', error)
  }
}

// Mark news as read and close modal
const markNewsAsRead = async (newsId) => {
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
  if (!isPublicRoute.value) {
    await checkForUnreadNews()
  }
})

onMounted(() => {
  if (window.location.pathname === "/") {
    router.push("/event")
  }
  
  // Check for unread news on initial load
  checkForUnreadNews()
})
</script>

<template>
  <div class="h-screen flex flex-col w-full font-sans" :class="{ 'px-10': showNavigation }">
    <Navigation v-if="showNavigation"/>

    <router-view class="flex-1 shadow-lg"/>

    <!-- News Modal -->
    <NewsModal 
      v-if="showNewsModal && currentNews" 
      :news="currentNews" 
      @markRead="markNewsAsRead"
    />
  </div>
</template>

<style scoped>

</style>
