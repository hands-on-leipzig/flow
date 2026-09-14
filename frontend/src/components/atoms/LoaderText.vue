<script setup>
import {onUnmounted} from 'vue'

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

const numberSentences = texts.length

for (let i = texts.length - 1; i > 0; i--) {
  const j = Math.floor(Math.random() * (i + 1))
  ;[texts[i], texts[j]] = [texts[j], texts[i]]
}

const animationName = `loader-text-fly-${Math.random().toString(36).slice(2, 10)}`

let p = 1
const step = Math.floor(100 / numberSentences)
const holdIndex = Math.floor(numberSentences / 2)
let keyframes = ''

for (let i = 0; i <= numberSentences + 1; i++) {
  const progress = i * step
  const left = (p-- * 100) - 5

  keyframes += `${progress}% { left: ${left}vw; }\n`

  if (i === holdIndex) {
    keyframes += `${progress + step / 16}% { left: ${left}vw; }\n`
  }
}

let styleEl = null
if (typeof document !== 'undefined') {
  styleEl = document.createElement('style')
  styleEl.textContent = `@keyframes ${animationName} { ${keyframes} }`
  document.head.appendChild(styleEl)
}

onUnmounted(() => {
  styleEl?.remove()
})
</script>

<template>
  <div class="text-loader">
    <div class="text-loader__track">
      <span
        v-for="(text, index) in texts"
        :key="index"
        :style="{ animationName }"
      >
        {{ text }}
      </span>
    </div>
  </div>
</template>

<style scoped>
.text-loader {
  overflow: clip;
  width: 100%;
  min-width: 0;
  align-self: stretch;
}

.text-loader__track {
  display: grid;
  grid-template-columns: repeat(v-bind('numberSentences'), 100vw);
  /* 100vw cells are viewport-wide; shift so the pause sits in the overlay, not off to the right. */
  margin-left: calc(50% - 50vw);
}

.text-loader__track > span {
  position: relative;
  animation-duration: 20s;
  animation-iteration-count: infinite;
  text-align: center;
  font-size: 1.5rem;
  font-weight: 500;
  white-space: nowrap;
}
</style>
