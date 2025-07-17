<script setup>
import {onMounted} from "vue";

const numberSentences = 7
let p = 1
const step = Math.floor(100 / numberSentences)
let movetext = ''

for (let i = 0; i <= numberSentences + 1; i++) {
  movetext += `${i * step}% { left: ${(p-- * 100) - 5}vw; }\n`
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
    <span>Münzen werden in die Zeit-Slots geworfen</span>
    <span>Wer hat die Bauanleitung für den Zeitplan gesehen?</span>
    <span>Jury-Spuren werden gefegt</span>
    <span>Das ist kein Chaos, das ist kreative Planung!</span>
    <span>Mittagessen wird gekocht</span>
    <span>Bitte Geduld... die Legosteine sortieren sich noch von selbst!</span>
    <span>Testdruck wird durchgeführt</span>
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

}
</style>