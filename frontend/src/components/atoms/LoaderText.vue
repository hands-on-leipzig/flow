<script setup>
import {onMounted, onUnmounted, ref} from 'vue'

const texts = [
  'Münzen werden in die Zeit-Slots geworfen',
  'Wer hat die Bauanleitung für den Zeitplan gesehen?',
  'Jury-Spuren werden gefegt',
  'Das ist kein Chaos, das ist kreative Planung!',
  'Mittagessen wird gekocht',
  'Bitte Geduld… die Legosteine sortieren sich noch von selbst!',
  'Testdruck wird durchgeführt',
  'QR Code wird an die Wand gesprüht',
  'Fluxkompensator wird kalibriert',
  'Die Matrix wird neu gestartet',
  'Bis zur Unendlichkeit und noch viel weiter!',
]

for (let i = texts.length - 1; i > 0; i--) {
  const j = Math.floor(Math.random() * (i + 1))
  ;[texts[i], texts[j]] = [texts[j], texts[i]]
}

const index = ref(0)
let timer

onMounted(() => {
  timer = window.setInterval(() => {
    index.value = (index.value + 1) % texts.length
  }, 4500)
})

onUnmounted(() => {
  if (timer) window.clearInterval(timer)
})
</script>

<template>
  <div class="text-loader">
    <Transition name="loader-text" mode="out-in">
      <span :key="index" class="text-loader__line">{{ texts[index] }}</span>
    </Transition>
  </div>
</template>

<style scoped>
.text-loader {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 2.25rem;
  width: 100%;
  padding: 0 1rem;
}

.text-loader__line {
  text-align: center;
  font-size: 1.5rem;
  font-weight: 500;
}

.loader-text-enter-active,
.loader-text-leave-active {
  transition: opacity 0.35s ease;
}

.loader-text-enter-from,
.loader-text-leave-to {
  opacity: 0;
}
</style>
