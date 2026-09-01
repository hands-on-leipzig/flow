<script setup lang="ts">
/**
 * Device-framed live iframe preview (Veröffentlichung, Check-In, Cockpit).
 */
import {usePreviewViewport} from '@/composables/usePreviewViewport'

defineOptions({name: 'MobileLivePreview'})

const props = withDefaults(defineProps<{
  /** Short label after “Live-Vorschau ·” */
  label: string
  previewUrl: string
  iframeKey: number
  loading?: boolean
  emptyText?: string
  iframeTitle?: string
  ariaLabel?: string
  /** Show “In Tab öffnen” when URL is set */
  openTabUrl?: string
}>(), {
  loading: false,
  emptyText: 'Keine Vorschau verfügbar.',
  iframeTitle: 'Live-Vorschau',
  ariaLabel: 'Live-Vorschau',
  openTabUrl: '',
})

const emit = defineEmits<{
  reload: []
  load: []
}>()

const {
  previewViewports,
  previewViewportId,
  isFixedPreviewViewport,
  deviceShellStyle,
  previewViewportHint,
} = usePreviewViewport()

function openInTab() {
  const url = props.openTabUrl || props.previewUrl
  if (url) window.open(url, '_blank', 'noopener')
}
</script>

<template>
  <div
      class="mlp glass-card liquid-surface-inner"
      :aria-label="ariaLabel"
  >
    <div class="mlp__bar">
      <span class="mlp__dot" aria-hidden="true"/>
      <span class="mlp__dot" aria-hidden="true"/>
      <span class="mlp__dot" aria-hidden="true"/>
      <span class="mlp__path">
        Live-Vorschau · {{ label }}
        <span v-if="isFixedPreviewViewport" class="mlp__viewport"> · {{ previewViewportHint }}</span>
      </span>
      <div class="mlp__actions">
        <label class="mlp__device glass-btn-accent">
          <span class="mlp__device-label">Gerät</span>
          <select
              v-model="previewViewportId"
              class="mlp__device-select"
              aria-label="Vorschau-Gerät"
          >
            <option v-for="viewport in previewViewports" :key="viewport.id" :value="viewport.id">
              {{ viewport.label }}
            </option>
          </select>
        </label>
        <button
            v-if="previewUrl"
            type="button"
            class="mlp__icon-btn"
            title="Vorschau neu laden"
            @click="emit('reload')"
        >
          <i class="bi bi-arrow-clockwise" aria-hidden="true"/>
        </button>
        <button
            v-if="previewUrl && (openTabUrl || previewUrl)"
            type="button"
            class="glass-btn-secondary mlp__tab-btn"
            @click="openInTab"
        >
          In Tab öffnen
        </button>
      </div>
    </div>

    <div
        class="mlp__stage"
        :class="{'mlp__stage--fixed': isFixedPreviewViewport}"
    >
      <div
          class="mlp__shell"
          :class="{'mlp__shell--full': !isFixedPreviewViewport}"
          :style="deviceShellStyle"
      >
        <div v-if="loading && previewUrl" class="mlp__placeholder">
          Lade Vorschau…
        </div>
        <iframe
            v-if="previewUrl"
            :key="iframeKey"
            class="mlp__frame"
            :src="previewUrl"
            :title="iframeTitle"
            @load="emit('load')"
        />
        <div v-else class="mlp__placeholder mlp__placeholder--empty">
          {{ emptyText }}
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.mlp {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  min-height: 0;
  min-width: 0;
  padding: 0 !important;
  overflow: hidden;
  background: var(--glass-tab-surface, #ffffff);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 65%, transparent);
  border-radius: var(--radius-lg, 16px);
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.07),
    0 2px 6px rgba(15, 23, 42, 0.04);
}

.mlp__bar {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.55rem 0.85rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 45%, #fff);
  flex-shrink: 0;
  flex-wrap: wrap;
}

.mlp__actions {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin-left: auto;
  flex-shrink: 0;
}

.mlp__device.glass-btn-accent {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.55rem 0.35rem 0.75rem;
  cursor: default;
}

.mlp__device-label {
  font-size: 0.75rem;
  font-weight: 650;
  color: var(--color-on-accent);
  white-space: nowrap;
}

.mlp__device-select {
  appearance: none;
  min-width: 12.5rem;
  max-width: 16rem;
  padding: 0.2rem 1.65rem 0.2rem 0.45rem;
  border: 1px solid color-mix(in srgb, #fff 28%, transparent);
  border-radius: calc(var(--radius) - 2px);
  background:
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='white' viewBox='0 0 16 16'%3E%3Cpath d='M4.646 6.646a.5.5 0 0 1 .708 0L8 9.293l2.646-2.647a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 0 1 0-.708'/%3E%3C/svg%3E")
    no-repeat right 0.45rem center / 0.75rem,
    color-mix(in srgb, #fff 18%, transparent);
  color: var(--color-on-accent);
  font-size: 0.75rem;
  font-weight: 650;
  line-height: 1.3;
  cursor: pointer;
}

.mlp__device-select:focus-visible {
  outline: 2px solid color-mix(in srgb, #fff 75%, transparent);
  outline-offset: 1px;
}

.mlp__device-select option {
  color: var(--color-text);
  background: #fff;
}

.mlp__tab-btn {
  padding: 0.375rem 0.75rem;
  font-size: 0.75rem;
  white-space: nowrap;
}

.mlp__icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  padding: 0;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
}

.mlp__icon-btn:hover {
  color: var(--color-accent);
  background: color-mix(in srgb, var(--color-accent) 10%, transparent);
}

.mlp__dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-border-strong) 45%, transparent);
}

.mlp__path {
  margin-left: 0.45rem;
  flex: 1;
  min-width: 0;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.mlp__viewport {
  font-variant-numeric: tabular-nums;
}

.mlp__stage {
  position: relative;
  flex: 1 1 auto;
  min-height: 0;
  min-width: 0;
  overflow: auto;
  background: color-mix(in srgb, var(--color-bg-muted) 38%, #eef2f7);
}

.mlp__stage--fixed {
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 0.85rem;
}

.mlp__shell {
  position: relative;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  background: #fff;
  overflow: hidden;
}

.mlp__shell--full {
  width: 100%;
}

.mlp__shell:not(.mlp__shell--full) {
  flex: 0 0 auto;
  max-width: calc(100% - 1.7rem);
  border-radius: calc(var(--radius-lg, 16px) + 2px);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  box-shadow:
    0 16px 36px rgba(15, 23, 42, 0.14),
    0 2px 8px rgba(15, 23, 42, 0.06);
}

.mlp__frame {
  width: 100%;
  height: 100%;
  border: none;
  display: block;
  background: #fff;
}

.mlp__placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.9rem;
  color: var(--color-text-muted);
  background: color-mix(in srgb, #ffffff 92%, var(--color-bg-muted));
  pointer-events: none;
}

.mlp__placeholder--empty {
  pointer-events: auto;
}
</style>
