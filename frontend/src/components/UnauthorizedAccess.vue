<template>
  <div class="flex flex-col items-center justify-center min-h-screen bg-gray-50 px-4">
    <div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8 text-center">
      <!-- Logos at top -->
      <div class="flex items-center justify-center gap-4 mb-8">
        <img :src="imageUrl('/flow/flow.png')" alt="FLOW Logo" class="h-8 w-auto"/>
        <img :src="imageUrl('/flow/hot+fll.png')" alt="Hands on Technology und FIRST LEGO League Logo"
             class="h-8 w-auto"/>
      </div>

      <!-- Icon -->
      <div class="mb-6">
        <svg
            class="mx-auto h-24 w-24 text-red-500"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
        >
          <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
          />
        </svg>
      </div>

      <!-- Title -->
      <h1 class="text-3xl font-bold text-gray-900 mb-4">
        Zugriff verweigert
      </h1>

      <!-- Message -->
      <p class="text-gray-600 mb-6">
        Dieser Account hat keine Berechtigung,<br>auf FLOW zuzugreifen.
      </p>

      <!-- Contact information -->
      <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <p class="text-sm text-blue-800">
          Wenn du glaubst, dass du Zugriff haben solltest, kontaktiere bitte
          <a
              href="mailto:flow@hands-on-technology.org"
              class="font-semibold underline hover:text-blue-900"
          >
            flow@hands-on-technology.org
          </a>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {imageUrl} from '@/utils/images'
</script>

