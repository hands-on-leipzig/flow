<script setup lang="ts">
/**
 * Ablauf / Zusatzaktivitäten shell: sidebar picks the left pane; plan preview stays on the right.
 */
import { onMounted, ref, watch } from 'vue'
import { useScheduleWorkspace } from '@/composables/useScheduleWorkspace'
import ScheduleToast from '@/components/atoms/ScheduleToast.vue'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import Preview from '@/components/molecules/Preview.vue'
import PanelSplitter from '@/components/atoms/PanelSplitter.vue'

defineOptions({ name: 'Schedule' })

const {
  selectedEvent,
  selectedPlanId,
  loading,
  isGenerating,
  generatorError,
  errorDetails,
  countdownSeconds,
  previewReload,
  planPopoutOpen,
  immediateFlush,
  ensureLoaded,
  reloadForEventChange,
  openPlanPopout,
  focusPlanPopout,
  dockPlanPopout,
} = useScheduleWorkspace()

const leftWidth = ref(50)

function clearGeneratorError() {
  generatorError.value = null
  errorDetails.value = null
}

onMounted(() => {
  void ensureLoaded()
})

watch(
  () => selectedEvent.value?.id,
  (id, prev) => {
    if (id && prev && id !== prev) void reloadForEventChange()
  }
)
</script>

<template>
  <div class="h-full min-h-0 flex flex-col gap-3 overflow-hidden">
    <div v-if="loading && !selectedPlanId" class="flex items-center justify-center h-full flex-col text-[var(--color-text-muted)]">
      <LoaderFlow/>
      <LoaderText/>
    </div>

    <template v-else>
      <ScheduleToast
          :is-generating="isGenerating"
          :countdown="countdownSeconds"
          :on-immediate-save="immediateFlush"
      />

      <div
          v-if="planPopoutOpen"
          class="shrink-0 flex flex-wrap items-center justify-between gap-2 rounded-md border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2"
      >
        <div class="text-xs md:text-sm text-[var(--color-text-muted)]">
          Plan im Pop-out
          <span v-if="isGenerating" class="ml-1 text-[var(--color-accent)]">· generiert …</span>
        </div>
        <div class="flex items-center gap-2">
          <button
              type="button"
              class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1.5 rounded-md border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)]"
              @click="focusPlanPopout"
          >
            <i class="bi bi-window" aria-hidden="true"/>
            Fenster zeigen
          </button>
          <button
              type="button"
              class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1.5 rounded-md border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)]"
              @click="dockPlanPopout"
          >
            <i class="bi bi-box-arrow-in-down-left" aria-hidden="true"/>
            Wieder hier zeigen
          </button>
        </div>
      </div>

      <div v-if="generatorError" class="glass-alert-error shrink-0">
        <div class="flex items-start justify-between gap-2">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <i class="bi bi-exclamation-triangle-fill text-[#dc2626] flex-shrink-0" aria-hidden="true"/>
              <h3 class="font-semibold text-sm md:text-base break-words">{{ generatorError }}</h3>
            </div>
            <p v-if="errorDetails" class="mt-2 text-xs md:text-sm text-[var(--color-text-muted)] break-words">{{ errorDetails }}</p>
          </div>
          <button
              type="button"
              class="ml-2 text-[var(--color-text-muted)] hover:text-[var(--color-text)] focus:outline-none flex-shrink-0"
              aria-label="Fehler schließen"
              @click="clearGeneratorError"
          >
            <i class="bi bi-x-lg" aria-hidden="true"/>
          </button>
        </div>
      </div>

      <div class="schedule-workspace flex-1 min-h-0 min-w-0">
        <div class="schedule-workspace__split">
          <section
              class="schedule-workspace__left"
              :class="{ 'schedule-workspace__left--full': planPopoutOpen }"
              :style="planPopoutOpen ? undefined : { flex: `0 0 ${leftWidth}%` }"
          >
            <div class="schedule-workspace__settings">
              <router-view v-slot="{ Component, route: paneRoute }">
                <keep-alive
                    include="ScheduleGeneral,ScheduleExpert,ScheduleBlocks,ScheduleFreeActivities,Slots"
                >
                  <component
                      :is="Component"
                      v-if="Component"
                      :key="paneRoute.name ?? paneRoute.path"
                  />
                </keep-alive>
              </router-view>
            </div>
          </section>

          <template v-if="!planPopoutOpen">
            <PanelSplitter
                v-model="leftWidth"
                class="hidden md:flex schedule-workspace__splitter"
                storage-key="flow-schedule-split-v2"
            />

            <section class="schedule-workspace__right">
              <div class="schedule-workspace__preview">
                <div class="schedule-workspace__preview-bar">
                  <h2 class="text-sm font-semibold text-[var(--color-text)] uppercase tracking-wide">Plan</h2>
                  <button
                      type="button"
                      class="inline-flex items-center gap-1.5 text-xs md:text-sm px-2.5 py-1.5 rounded-md border border-[var(--color-border)] bg-[var(--color-bg)] hover:bg-[var(--color-bg-hover)] text-[var(--color-text)] disabled:opacity-50"
                      :disabled="!selectedPlanId"
                      title="Plan in eigenem Fenster öffnen"
                      @click="openPlanPopout"
                  >
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"/>
                    <span>Pop-out</span>
                  </button>
                </div>

                <div class="flex-1 min-h-0 min-w-0 overflow-hidden">
                  <div v-if="isGenerating" class="flex items-center justify-center h-full w-full flex-col text-[var(--color-text-muted)]">
                    <LoaderFlow/>
                    <LoaderText/>
                  </div>
                  <Preview
                      v-else-if="selectedPlanId"
                      class="w-full h-full min-h-0"
                      :plan-id="selectedPlanId as number"
                      :reload="previewReload"
                      initial-view="overview"
                  />
                </div>
              </div>
            </section>
          </template>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.schedule-workspace {
  display: flex;
  flex-direction: column;
}

.schedule-workspace__split {
  display: flex;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  flex-direction: column;
  gap: 0.75rem;
}

@media (min-width: 768px) {
  .schedule-workspace__split {
    flex-direction: row;
    gap: 0.55rem;
    align-items: stretch;
  }
}

.schedule-workspace__left {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
}

.schedule-workspace__left--full {
  flex: 1 1 auto !important;
}

.schedule-workspace__settings {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  padding: 1.15rem 1.2rem 1.4rem;
  background: var(--glass-tab-surface, #ffffff);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 65%, transparent);
  border-radius: var(--radius-lg, 16px);
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.07),
    0 2px 6px rgba(15, 23, 42, 0.04);
}

.schedule-workspace__right {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 16rem;
  display: flex;
  flex-direction: column;
}

.schedule-workspace__preview-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-shrink: 0;
  margin-bottom: 0.65rem;
}

.schedule-workspace__preview {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 0.85rem 1rem 1rem;
  background: var(--glass-tab-surface, #ffffff);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 65%, transparent);
  border-radius: var(--radius-lg, 16px);
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.07),
    0 2px 6px rgba(15, 23, 42, 0.04);
}

@media (min-width: 768px) {
  .schedule-workspace__right {
    min-height: 0;
  }
}

@media (max-width: 767px) {
  .schedule-workspace__left:not(.schedule-workspace__left--full) {
    flex: 1 1 auto !important;
    max-height: 50vh;
  }
}
</style>
