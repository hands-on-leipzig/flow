<script setup lang="ts">
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from 'vue'
import {imageUrl} from '@/utils/images'
import PublicSchedule, {type PrintScheduleChrome} from '@/components/PublicSchedule.vue'

const PRINT_FIT_HOT_SRC = imageUrl('/flow/hot.png')
const PRINT_FIT_HOT_ORANGE = '#F78B1F'

const props = defineProps<{
  planId: number | string
}>()

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

onMounted(() => {
  document.documentElement.classList.add('flow-print-fit')
})

onUnmounted(() => {
  document.documentElement.classList.remove('flow-print-fit')
  stopPrintFooterObserver()
})
</script>

<template>
  <div class="print-overview">
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
                <span>{{ chrome.subject }}</span>
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
  min-height: 100dvh;
  height: auto;
  max-height: none;
  overflow: visible;
  background: #e5e7eb;
  padding: 0;
  font-family: var(--font-sans);
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
  width: 204mm;
  height: 291mm;
  flex-shrink: 0;
  background: #fff;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 8px 24px rgb(15 23 42 / 0.12);
}

.print-overview__header {
  flex: 0 0 28mm;
  height: 28mm;
  position: relative;
  box-sizing: border-box;
}

.print-overview__bar {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3mm;
  background: var(--print-bar, #F78B1F);
}

.print-overview__header-row {
  position: absolute;
  top: 4mm;
  left: 4mm;
  right: 4mm;
  bottom: 0;
  display: flex;
  align-items: flex-start;
  gap: 2mm;
}

.print-overview__side {
  width: 34mm;
  flex-shrink: 0;
}

.print-overview__side--hot {
  height: 19.8mm;
  display: flex;
  align-items: center;
}

.print-overview__hot {
  width: 34mm;
  max-height: 19.8mm;
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
  font-size: 0.85rem;
  font-weight: 700;
  font-synthesis: none;
  letter-spacing: 0.01em;
  line-height: 1.25;
  color: #000;
}

.print-overview__subject {
  margin: 2mm 0 0;
  display: flex;
  align-items: center;
  gap: 1.5mm;
  min-height: 7mm;
  font-family: var(--font-sans);
  font-size: 1.05rem;
  font-weight: 700;
  font-synthesis: none;
  letter-spacing: 0.01em;
  line-height: 1.2;
  color: #000;
}

.print-overview__subject-logo {
  width: 7mm;
  height: 7mm;
  object-fit: contain;
  flex-shrink: 0;
}

.print-overview__side--qr {
  display: flex;
  gap: 2mm;
}

.print-overview__qr {
  width: 16mm;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.print-overview__qr-slot {
  width: 16mm;
  height: 16mm;
}

.print-overview__qr-img {
  width: 16mm;
  height: 16mm;
  object-fit: contain;
}

.print-overview__qr-caption {
  height: 3.8mm;
  font-family: var(--font-sans);
  font-size: 0.7rem;
  font-weight: 700;
  font-synthesis: none;
  letter-spacing: 0.01em;
  line-height: 3.8mm;
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
  flex: 0 0 28mm;
  height: 28mm;
  box-sizing: border-box;
}

.print-overview__footer-inner {
  height: 100%;
  padding: 2mm 4mm;
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
  gap: 4mm;
  height: 24mm;
}

.print-overview__footer-logo {
  height: 24mm;
  width: auto;
  object-fit: contain;
}

@media print {
  .print-overview {
    min-height: 0 !important;
    width: 204mm !important;
    height: 291mm !important;
    max-height: 291mm !important;
    padding: 0 !important;
    overflow: hidden !important;
    background: #fff !important;
  }

  .print-overview__page {
    min-height: 0 !important;
    width: 204mm !important;
    height: 291mm !important;
    max-height: 291mm !important;
    padding: 0 !important;
    display: block;
    overflow: hidden !important;
  }

  /* Keep the designed A4 stage (not 100% of Chromium's 800x600 viewport). */
  .print-overview__stage {
    box-shadow: none !important;
    width: 204mm !important;
    height: 291mm !important;
    max-width: 204mm !important;
    max-height: 291mm !important;
    overflow: hidden !important;
  }
}
</style>

<style>
html.flow-print-fit {
  color-scheme: light;
  font-family: var(--font-sans);
}

@page {
  size: A4 portrait;
  margin: 3mm;
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
