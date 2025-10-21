<script setup>
import { defineProps, defineEmits, computed } from 'vue'

const props = defineProps({
  news: {
    type: Object,
    required: true
  }
})

const emit = defineEmits(['markRead'])

const handleMarkRead = () => {
  console.log('NewsModal handleMarkRead called', { newsId: props.news.id, news: props.news })
  emit('markRead', props.news.id)
}

const mailtoLink = computed(() => {
  const subject = encodeURIComponent(`Frage zu ${props.news.title}`)
  return `mailto:flow@hands-on-technology.org?subject=${subject}`
})
</script>

<template>
  <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-lg shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
      <!-- Header -->
      <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-4 rounded-t-lg">
        <h2 class="text-2xl font-bold text-white">{{ news.title }}</h2>
        <p class="text-blue-100 text-sm mt-1">
          {{ new Date(news.created_at).toLocaleDateString('de-DE', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
          }) }} {{ new Date(news.created_at).toLocaleTimeString('de-DE', { 
            hour: '2-digit', 
            minute: '2-digit' 
          }) }}
        </p>
      </div>

      <!-- Content -->
      <div class="px-6 py-6">
        <div class="text-gray-800 whitespace-pre-wrap leading-relaxed">
          {{ news.text }}
        </div>

        <!-- Optional Link -->
        <div v-if="news.link" class="mt-4">
          <a 
            :href="news.link" 
            target="_blank" 
            rel="noopener noreferrer"
            class="inline-flex items-center text-blue-600 hover:text-blue-800 hover:underline"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
            Mehr erfahren
          </a>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 rounded-b-lg">
        <!-- Contact text -->
        <p class="text-sm text-gray-600 mb-4">
          Fragen gerne per Mail an 
          <a 
            :href="mailtoLink" 
            class="text-blue-600 hover:text-blue-800 hover:underline"
          >
            flow@hands-on-technology.org
          </a>
        </p>

        <!-- Action button -->
        <button
          @click="handleMarkRead"
          class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-4 rounded-lg transition-colors duration-200 shadow-md hover:shadow-lg"
        >
          Gelesen
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Optional: Add animation for modal appearance */
.fixed {
  animation: fadeIn 0.2s ease-in-out;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}
</style>

