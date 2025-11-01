<template>
  <div class="min-h-screen bg-gray-50 flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <div class="text-center">
        <!-- Logo or Icon -->
        <div class="mx-auto h-16 w-16 text-gray-400 mb-4">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 12h6m-6-4h6m2 5.291A7.962 7.962 0 0112 15c-2.34 0-4.29-1.009-5.824-2.709M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
          </svg>
        </div>
        
        <!-- Error Message -->
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
          Veranstaltung nicht gefunden
        </h1>
        <p class="text-lg text-gray-600 mb-8">
          Die angeforderte Veranstaltung konnte nicht gefunden werden.
        </p>
        
        <!-- Additional Info -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-8">
          <h2 class="text-lg font-semibold text-gray-900 mb-3">
            Mögliche Ursachen:
          </h2>
          <ul class="text-left text-gray-600 space-y-2">
            <li class="flex items-start">
              <span class="text-red-500 mr-2">•</span>
              Der Link ist falsch geschrieben oder unvollständig
            </li>
            <li class="flex items-start">
              <span class="text-red-500 mr-2">•</span>
              Die Veranstaltung wurde entfernt oder umbenannt
            </li>
            <li class="flex items-start">
              <span class="text-red-500 mr-2">•</span>
              Der Link ist abgelaufen oder nicht mehr gültig
            </li>
          </ul>
        </div>
        
        <!-- Action Buttons -->
        <div class="space-y-4">
          <button
            @click="goBack"
            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
          >
            Zurück zur vorherigen Seite
          </button>
          
          <button
            @click="goHome"
            class="w-full flex justify-center py-3 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
          >
            Zur Startseite
          </button>
        </div>
        
        <!-- Contact Info -->
        <div class="mt-8 text-sm text-gray-500">
          <p>
            Falls du glaubst, dass dies ein Fehler ist, wende dich bitte an den Veranstalter.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'

const router = useRouter()

const goBack = () => {
  if (window.history.length > 1) {
    router.go(-1)
  } else {
    goHome()
  }
}

const goHome = () => {
  // Redirect to a default page or the main application
  window.location.href = '/plan/event'
}
</script>

<style scoped>
/* Additional custom styles if needed */
</style>
