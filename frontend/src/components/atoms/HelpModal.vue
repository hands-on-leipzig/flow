<script setup>
import { computed, ref } from 'vue'
import { imageUrl } from '@/utils/images'

const props = defineProps({
  show: {
    type: Boolean,
    required: true
  }
})

const emit = defineEmits(['close'])

const handleClose = () => {
  emit('close')
}

const mailtoLink = computed(() => {
  const subject = encodeURIComponent('Frage oder Idee zu FLOW')
  return `mailto:flow@hands-on-technology.org?subject=${subject}`
})

// Video URL - recorded intro session
const videoUrl = ref('https://handsontechnology-my.sharepoint.com/:v:/g/personal/jr_hands-on-technology_org/EYLes-Kq4GlDuBpUaxolgn4B4naGZakiVMW7Dq0xgWmskA?nav=eyJyZWZlcnJhbEluZm8iOnsicmVmZXJyYWxBcHAiOiJTdHJlYW1XZWJBcHAiLCJyZWZlcnJhbFZpZXciOiJTaGFyZURpYWxvZy1MaW5rIiwicmVmZXJyYWxBcHBQbGF0Zm9ybSI6IldlYiIsInJlZmVycmFsTW9kZSI6InZpZXcifX0%3D&e=T5yiJJ')

// Presentation URL - upload presentation to backend/public/flow/ directory
// Then set the filename here (e.g., 'presentation.pdf' or 'flow-intro.pptx')
// The path will be: /flow/[filename]
const presentationFilename = ref('FLOW 2025-2026.pdf')
const presentationUrl = computed(() => {
  return presentationFilename.value ? imageUrl(`/flow/${presentationFilename.value}`) : ''
})

// Special situation guides
const specialSituationFilename = ref('FLOW - Wenn Teams am Tag der Veranstaltung nicht erscheinen.pdf')
const specialSituationUrl = computed(() => {
  return specialSituationFilename.value ? imageUrl(`/flow/${specialSituationFilename.value}`) : ''
})
</script>

<template>
  <div 
    v-if="show"
    class="glass-scrim fixed inset-0 flex items-center justify-center z-50 p-4"
    @click.self="handleClose"
  >
    <div class="glass-modal glass-modal-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
      <!-- Header -->
      <div class="glass-modal-header">
        <h2 class="text-2xl font-bold">Hilfe zu FLOW</h2>
      </div>

      <!-- Content -->
      <div class="px-6 py-6">
        <!-- Section Header: Quick Start -->
        <h3 class="text-lg font-semibold text-[var(--color-text)] mb-4">Schneller Einstieg in die Benutzung</h3>
        
        <!-- Intro text -->
        <div class="text-[var(--color-text)] whitespace-pre-wrap leading-relaxed mb-6">
          <p class="mb-4">
            Wenn du wissen möchtest, wie FLOW funktioniert, findest du hier das Einführungsvideo und die Präsentation mit allen Schritten.
          </p>
        </div>

        <!-- Video Link -->
        <div v-if="videoUrl" class="mb-4">
          <a 
            :href="videoUrl" 
            target="_blank" 
            rel="noopener noreferrer"
            class="inline-flex items-center text-blue-600 hover:text-blue-800 hover:underline font-medium"
          >
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Einführungsvideo ansehen
          </a>
        </div>
        <div v-else class="mb-4 text-[var(--color-text-subtle)] italic">
          <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Video-Link wird noch hinzugefügt
        </div>

        <!-- Presentation Link -->
        <div v-if="presentationUrl" class="mb-4">
          <a 
            :href="presentationUrl" 
            target="_blank" 
            rel="noopener noreferrer"
            class="inline-flex items-center text-blue-600 hover:text-blue-800 hover:underline font-medium"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
            </svg>
            Präsentation öffnen
          </a>
        </div>
        <div v-else class="mb-4 text-[var(--color-text-subtle)] italic">
          <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
          </svg>
          Präsentation wird noch hochgeladen
        </div>

        <!-- Special Situation Guides Section -->
        <div class="mt-6 pt-6 border-t border-[var(--color-border)]">
          <h3 class="text-lg font-semibold text-[var(--color-text)] mb-4">Kurzanleitungen für spezielle Situationen</h3>
          
          <!-- Special Situation PDF Link -->
          <div v-if="specialSituationUrl" class="mb-4">
            <a 
              :href="specialSituationUrl" 
              target="_blank" 
              rel="noopener noreferrer"
              class="inline-flex items-center text-blue-600 hover:text-blue-800 hover:underline font-medium"
            >
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
              Wenn Teams am Tag der Veranstaltung nicht erscheinen
            </a>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="glass-modal-footer">
        <!-- Contact text -->
        <p class="text-sm text-[var(--color-text-muted)] mb-4">
          Fragen oder Ideen gerne per Mail an 
          <a 
            :href="mailtoLink" 
            class="text-blue-600 hover:text-blue-800 hover:underline"
          >
            flow@hands-on-technology.org
          </a>
        </p>

        <!-- Close button -->
        <button
          @click="handleClose"
          class="w-full glass-btn-accent !py-3 !px-4 !text-base !font-semibold"
        >
          Schließen
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

