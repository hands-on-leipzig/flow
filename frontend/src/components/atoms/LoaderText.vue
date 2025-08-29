<script setup>
import { onMounted } from 'vue'

// Text-Array statt Hardcoded <span>
const texts = [
  'Münzen werden in die Zeit-Slots geworfen',
  'Wer hat die Bauanleitung für den Zeitplan gesehen?',
  'Jury-Spuren werden gefegt',
  'Das ist kein Chaos, das ist kreative Planung!',
  'Mittagessen wird gekocht',
  'Bitte Geduld... die Legosteine sortieren sich noch von selbst!',
  'Testdruck wird durchgeführt',
  'QR Code wird an die Wand gesprüht',
  'Fluxkompensator wird kalibriert',
  'Die Matrix wird neu gestartet',
  'Bis zur Unendlichkeit und noch viel weiter!',
]

const numberSentences = texts.length

// Fisher-Yates Shuffle
for (let i = texts.length - 1; i > 0; i--) {
  const j = Math.floor(Math.random() * (i + 1))
  ;[texts[i], texts[j]] = [texts[j], texts[i]]
}


let p = 1
const step = Math.floor(100 / numberSentences)
const holdIndex = Math.floor(numberSentences / 2)
let movetext = ''

for (let i = 0; i <= numberSentences + 1; i++) {
  const progress = i * step
  const left = (p-- * 100) - 5

  movetext += `${progress}% { left: ${left}vw; }\n`

  if (i === holdIndex) {
    movetext += `${progress + step / 16}% { left: ${left}vw; }\n`
  }
}

onMounted(() => {
  const style = document.createElement('style')
  style.innerHTML = `
    @keyframes movetext {
      ${movetext}
    }
  `
  document.head.appendChild(style)
})
</script>

<template>
  <div id="text-loader">
    <span
      v-for="(text, index) in texts"
      :key="index"
    >
      {{ text }}
    </span>
  </div>
</template>

<style scoped>
#text-loader {
  display: grid;
  grid-template-columns: repeat(v-bind('numberSentences'), 100vw);
  overflow: clip;
}

#text-loader > span {
  position: relative;
  animation: movetext 20s infinite;
  text-align: center;
  font-size: 1.5rem;
  font-weight: 500;
}
</style>