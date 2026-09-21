<script setup lang="ts">
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from 'vue'
import {useRoute} from 'vue-router'
import {imageUrl} from '@/utils/images'
import PublicSchedule, {type PrintScheduleChrome} from '@/components/PublicSchedule.vue'
import ProgramOfficialName from '@/components/atoms/ProgramOfficialName.vue'

const PRINT_FIT_HOT_SRC = imageUrl('/flow/hot.png')
const PRINT_FIT_HOT_ORANGE = '#F78B1F'
const PAGE_STYLE_ID = 'flow-print-page-size'

const props = defineProps<{
  planId: number | string
}>()

const route = useRoute()
const isA3 = computed(() => {
  const raw = route.query.size
  const value = Array.isArray(raw) ? raw[0] : raw
  return value === 'a3'
})

const chrome = ref<PrintScheduleChrome>({
  eventName: '',
  subject: '',
  subjectLogoSrc: null,
  subjectLogoAlt: '',
  barColor: PRINT_FIT_HOT_ORANGE,
  onlinePlanQr: null,
  wifiQr: null,
  eventLogos: [],
  rolesReady: false,
  calendarReady: false,
})

const printFooterRowEl = ref<HTMLElement | null>(null)
const printFooterScale = ref(1)
const printFooterImagesReady = ref(true)
let printFooterObserver: ResizeObserver | null = null

const printFitReady = computed(() => chrome.value.calendarReady && printFooterImagesReady.value)

function sameOriginSrc(url: string): string {
  if (!url || url.startsWith('/') || url.startsWith('data:')) return url
  try {
    const parsed = new URL(url)
    if (
      parsed.hostname === 'localhost'
      || parsed.hostname === '127.0.0.1'
      || parsed.hostname === 'host.docker.internal'
    ) {
      return parsed.pathname + parsed.search
    }
  } catch {
    // keep original
  }
  return url
}

function notePrintFooterImages() {
  measurePrintFooter()
  const imgs = printFooterRowEl.value
      ? Array.from(printFooterRowEl.value.querySelectorAll('img'))
      : []
  if (chrome.value.eventLogos.length === 0 || imgs.length === 0) {
    printFooterImagesReady.value = chrome.value.eventLogos.length === 0
    return
  }
  printFooterImagesReady.value = imgs.every((img) => img.complete)
}

function measurePrintFooter() {
  const row = printFooterRowEl.value
  const inner = row?.parentElement?.parentElement
  if (!row || !inner) {
    printFooterScale.value = 1
    return
  }
  const avail = inner.clientWidth
  const need = row.scrollWidth
  const next = need > avail && avail > 0 ? avail / need : 1
  if (Math.abs(next - printFooterScale.value) > 0.001) {
    printFooterScale.value = next
  }
}

function startPrintFooterObserver() {
  stopPrintFooterObserver()
  if (typeof ResizeObserver === 'undefined') return
  measurePrintFooter()
  const inner = printFooterRowEl.value?.parentElement?.parentElement
  if (!inner) return
  printFooterObserver = new ResizeObserver(() => measurePrintFooter())
  printFooterObserver.observe(inner)
}

function stopPrintFooterObserver() {
  printFooterObserver?.disconnect()
  printFooterObserver = null
}

function onPrintChrome(next: PrintScheduleChrome) {
  chrome.value = next
}

function applyDocumentPrintSize(a3: boolean) {
  document.documentElement.classList.add('flow-print-fit')
  document.documentElement.classList.toggle('flow-print-a3', a3)
  let el = document.getElementById(PAGE_STYLE_ID) as HTMLStyleElement | null
  if (!el) {
    el = document.createElement('style')
    el.id = PAGE_STYLE_ID
    document.head.appendChild(el)
  }
  const size = a3 ? 'A3' : 'A4'
  el.textContent = `@page { size: ${size} portrait; margin: 3mm; }`
}

function clearDocumentPrintSize() {
  document.documentElement.classList.remove('flow-print-fit', 'flow-print-a3')
  document.getElementById(PAGE_STYLE_ID)?.remove()
}

watch(
    () => [printFooterRowEl.value, chrome.value.eventLogos],
    () => {
      startPrintFooterObserver()
      if (chrome.value.eventLogos.length === 0) {
        printFooterImagesReady.value = true
        return
      }
      printFooterImagesReady.value = false
      void nextTick(() => notePrintFooterImages())
    },
)

watch(isA3, (a3) => applyDocumentPrintSize(a3), {immediate: true})

onMounted(() => {
  applyDocumentPrintSize(isA3.value)
})

onUnmounted(() => {
  clearDocumentPrintSize()
  stopPrintFooterObserver()
})
</script>

<template>
  <div class="print-overview" :class="{'print-overview--a3': isA3}">
    <div class="print-overview__page">
      <div
          class="print-overview__stage"
          :data-print-ready="printFitReady ? 'true' : undefined"
      >
        <header
            v-if="chrome.rolesReady"
            class="print-overview__header"
            :style="{'--print-bar': chrome.barColor}"
        >
          <div class="print-overview__bar" aria-hidden="true"/>
          <div class="print-overview__header-row">
            <div class="print-overview__side print-overview__side--hot">
              <img :src="PRINT_FIT_HOT_SRC" alt="Hands on Technology" class="print-overview__hot"/>
            </div>
            <div class="print-overview__mid">
              <p class="print-overview__title">{{ chrome.eventName }}</p>
              <p class="print-overview__subject">
                <img
                    v-if="chrome.subjectLogoSrc"
                    :src="chrome.subjectLogoSrc"
                    :alt="chrome.subjectLogoAlt"
                    class="print-overview__subject-logo"
                />
                <ProgramOfficialName :html="chrome.subject"/>
              </p>
            </div>
            <div class="print-overview__side print-overview__side--qr">
              <div class="print-overview__qr">
                <div class="print-overview__qr-slot">
                  <img v-if="chrome.wifiQr" :src="chrome.wifiQr" alt="" class="print-overview__qr-img"/>
                </div>
                <span class="print-overview__qr-caption">WLAN</span>
              </div>
              <div class="print-overview__qr">
                <div class="print-overview__qr-slot">
                  <img
                      v-if="chrome.onlinePlanQr"
                      :src="chrome.onlinePlanQr"
                      alt=""
                      class="print-overview__qr-img"
                  />
                </div>
                <span class="print-overview__qr-caption">Online-Plan</span>
              </div>
            </div>
          </div>
        </header>

        <div class="print-overview__body">
          <PublicSchedule :plan-id="props.planId" print-fit @print-chrome="onPrintChrome"/>
        </div>

        <footer v-if="chrome.rolesReady" class="print-overview__footer">
          <div class="print-overview__footer-inner">
            <div
                class="print-overview__footer-scale"
                :style="{transform: `scale(${printFooterScale})`}"
            >
              <div ref="printFooterRowEl" class="print-overview__footer-row">
                <img
                    v-for="logo in chrome.eventLogos"
                    :key="logo.id"
                    :src="sameOriginSrc(logo.url)"
                    :alt="logo.title || 'Logo'"
                    class="print-overview__footer-logo"
                    @load="notePrintFooterImages"
                    @error="notePrintFooterImages"
                />
              </div>
            </div>
          </div>
        </footer>
      </div>
    </div>
  </div>
</template>

<style scoped>
.print-overview {
  --print-w: 204mm;
  --print-h: 291mm;
  --print-chrome: 28mm;
  --print-scale: 1;
  min-height: 100dvh;
  height: auto;
  max-height: none;
  overflow: visible;
  background: #e5e7eb;
  padding: 0;
  font-family: var(--font-sans);
}

.print-overview--a3 {
  --print-w: 291mm;
  --print-h: 414mm;
  --print-chrome: 39.6mm;
  --print-scale: 1.414285714;
}

.print-overview__page {
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  padding: 16px;
}

.print-overview__stage {
  width: var(--print-w);
  height: var(--print-h);
  flex-shrink: 0;
  background: #fff;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 8px 24px rgb(15 23 42 / 0.12);
}

.print-overview__header {
  flex: 0 0 var(--print-chrome);
  height: var(--print-chrome);
  position: relative;
  box-sizing: border-box;
}

.print-overview__bar {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: calc(3mm * var(--print-scale));
  background: var(--print-bar, #F78B1F);
}

.print-overview__header-row {
  position: absolute;
  top: calc(4mm * var(--print-scale));
  left: calc(4mm * var(--print-scale));
  right: calc(4mm * var(--print-scale));
  bottom: 0;
  display: flex;
  align-items: flex-start;
  gap: calc(2mm * var(--print-scale));
}

.print-overview__side {
  width: calc(34mm * var(--print-scale));
  flex-shrink: 0;
}

.print-overview__side--hot {
  height: calc(19.8mm * var(--print-scale));
  display: flex;
  align-items: center;
}

.print-overview__hot {
  width: calc(34mm * var(--print-scale));
  max-height: calc(19.8mm * var(--print-scale));
  height: auto;
  object-fit: contain;
  object-position: left center;
}

.print-overview__mid {
  flex: 1;
  min-width: 0;
}

.print-overview__title {
  margin: 0;
  font-family: var(--font-sans);
  font-size: calc(0.85rem * var(--print-scale));
  font-weight: 700;
  font-synthesis: none;
  letter-spacing: 0.01em;
  line-height: 1.25;
  color: #000;
}

.print-overview__subject {
  margin: calc(2mm * var(--print-scale)) 0 0;
  display: flex;
  align-items: center;
  gap: calc(1.5mm * var(--print-scale));
  min-height: calc(7mm * var(--print-scale));
  font-family: var(--font-sans);
  font-size: calc(1.05rem * var(--print-scale));
  font-weight: 700;
  font-synthesis: none;
  letter-spacing: 0.01em;
  line-height: 1.2;
  color: #000;
}

.print-overview__subject-logo {
  width: calc(7mm * var(--print-scale));
  height: calc(7mm * var(--print-scale));
  object-fit: contain;
  flex-shrink: 0;
}

.print-overview__side--qr {
  display: flex;
  gap: calc(2mm * var(--print-scale));
}

.print-overview__qr {
  width: calc(16mm * var(--print-scale));
  display: flex;
  flex-direction: column;
  align-items: center;
}

.print-overview__qr-slot {
  width: calc(16mm * var(--print-scale));
  height: calc(16mm * var(--print-scale));
}

.print-overview__qr-img {
  width: calc(16mm * var(--print-scale));
  height: calc(16mm * var(--print-scale));
  object-fit: contain;
}

.print-overview__qr-caption {
  height: calc(3.8mm * var(--print-scale));
  font-family: var(--font-sans);
  font-size: calc(0.7rem * var(--print-scale));
  font-weight: 700;
  font-synthesis: none;
  letter-spacing: 0.01em;
  line-height: calc(3.8mm * var(--print-scale));
  text-align: center;
  color: #000;
}

.print-overview__body {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.print-overview__body :deep(.public-schedule) {
  flex: 1;
  min-height: 0;
  height: 100%;
}

.print-overview__footer {
  flex: 0 0 var(--print-chrome);
  height: var(--print-chrome);
  box-sizing: border-box;
}

.print-overview__footer-inner {
  height: 100%;
  padding: calc(2mm * var(--print-scale)) calc(4mm * var(--print-scale));
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}

.print-overview__footer-scale {
  transform-origin: center center;
}

.print-overview__footer-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: calc(4mm * var(--print-scale));
  height: calc(24mm * var(--print-scale));
}

.print-overview__footer-logo {
  height: calc(24mm * var(--print-scale));
  width: auto;
  object-fit: contain;
}

@media print {
  .print-overview {
    min-height: 0 !important;
    width: var(--print-w) !important;
    height: var(--print-h) !important;
    max-height: var(--print-h) !important;
    padding: 0 !important;
    overflow: hidden !important;
    background: #fff !important;
  }

  .print-overview__page {
    min-height: 0 !important;
    width: var(--print-w) !important;
    height: var(--print-h) !important;
    max-height: var(--print-h) !important;
    padding: 0 !important;
    display: block;
    overflow: hidden !important;
  }

  /* Keep the designed mm stage (not 100% of Chromium's 800x600 viewport). */
  .print-overview__stage {
    box-shadow: none !important;
    width: var(--print-w) !important;
    height: var(--print-h) !important;
    max-width: var(--print-w) !important;
    max-height: var(--print-h) !important;
    overflow: hidden !important;
  }
}
</style>

<style>
html.flow-print-fit {
  color-scheme: light;
  font-family: var(--font-sans);
}

@media print {
  html.flow-print-fit,
  html.flow-print-fit body,
  html.flow-print-fit #app {
    margin: 0 !important;
    padding: 0 !important;
    width: 210mm !important;
    height: 297mm !important;
    max-height: 297mm !important;
    min-height: 0 !important;
    overflow: hidden !important;
    font-family: var(--font-sans) !important;
  }

  html.flow-print-fit #app > .min-h-dvh {
    min-height: 0 !important;
    width: 210mm !important;
    height: 297mm !important;
    max-height: 297mm !important;
    overflow: hidden !important;
    padding: 0 !important;
    margin: 0 !important;
  }

  html.flow-print-a3,
  html.flow-print-a3 body,
  html.flow-print-a3 #app {
    width: 297mm !important;
    height: 420mm !important;
    max-height: 420mm !important;
  }

  html.flow-print-a3 #app > .min-h-dvh {
    width: 297mm !important;
    height: 420mm !important;
    max-height: 420mm !important;
  }

  html.flow-print-fit .glass-toast {
    display: none !important;
  }

  html.flow-print-fit,
  html.flow-print-fit *,
  html.flow-print-fit *::before,
  html.flow-print-fit *::after {
    print-color-adjust: exact !important;
    -webkit-print-color-adjust: exact !important;
    color-adjust: exact !important;
  }
}
</style>
