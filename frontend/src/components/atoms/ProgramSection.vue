<script setup lang="ts">
import {computed} from 'vue'
import {getProgramTheme} from '@/utils/programTheme'
import {programLogoAlt, programLogoSrc} from '@/utils/images'

const props = withDefaults(
    defineProps<{
      program: string
      /** When false, section stays visible but visually muted (program off). */
      active?: boolean
      showLogo?: boolean
      /** Override the short name (main heading) */
      shortName?: string
      /** Plain title mode — single heading (e.g. Zeiten) */
      title?: string
      /** Optional subtitle under the heading */
      subtitle?: string
    }>(),
    {
      active: true,
      showLogo: true,
    }
)

const theme = computed(() => getProgramTheme(props.program))
const heading = computed(() => props.title || props.shortName || theme.value.shortName)
const showLogoImg = computed(() => props.showLogo && !!theme.value.catalogName)
const subtitleText = computed(() => {
  if (props.subtitle) return props.subtitle
  if (props.title) return null
  return 'FIRST LEGO League'
})
</script>

<template>
  <section
      class="program-section"
      :class="{ 'program-section--inactive': !active }"
      :style="{ '--program-accent': theme.accent }"
      :data-program="program"
  >
    <header class="program-section__header">
      <div class="program-section__identity">
        <div v-if="showLogoImg" class="program-section__logo-wrap" aria-hidden="true">
          <img
              :alt="programLogoAlt(theme.catalogName)"
              :src="programLogoSrc(theme.catalogName)"
              class="program-section__logo"
          />
        </div>
        <div class="min-w-0">
          <h3 class="program-section__title">{{ heading }}</h3>
          <p v-if="subtitleText" class="program-section__subtitle">
            <template v-if="!subtitle && !title">
              <span class="italic">FIRST</span> LEGO League
            </template>
            <template v-else>{{ subtitleText }}</template>
          </p>
        </div>
      </div>
      <div v-if="$slots.actions" class="program-section__actions">
        <slot name="actions"/>
      </div>
    </header>
    <div class="program-section__body glass-settings-block">
      <slot/>
    </div>
  </section>
</template>

<style scoped>
.program-section {
  --program-accent: var(--color-accent);
  --program-accent-soft: color-mix(in srgb, var(--program-accent) 14%, transparent);
  position: relative;
  min-width: 0;
  padding: 1rem 1.1rem 1.05rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 42%, transparent);
  border-radius: 14px;
  background: transparent;
  transition:
    opacity 0.18s ease,
    border-color 0.18s ease,
    box-shadow 0.18s ease;
}

.program-section::before {
  content: '';
  position: absolute;
  left: 0;
  top: 12px;
  bottom: 12px;
  width: 3px;
  border-radius: 0 3px 3px 0;
  background: var(--program-accent);
  opacity: 0.95;
}

.program-section--inactive {
  border-color: color-mix(in srgb, var(--color-border-strong) 28%, transparent);
}

.program-section--inactive::before {
  opacity: 0.35;
}

.program-section--inactive .program-section__logo-wrap {
  filter: grayscale(0.35);
  opacity: 0.75;
}

.program-section__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.85rem 1rem;
  flex-wrap: wrap;
  padding: 0 0 0.85rem;
  margin-bottom: 0.15rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.program-section__identity {
  display: flex;
  align-items: center;
  gap: 0.8rem;
  min-width: 0;
  flex: 1 1 auto;
}

.program-section__logo-wrap {
  display: grid;
  place-items: center;
  width: 2.75rem;
  height: 2.75rem;
  flex-shrink: 0;
  border-radius: 12px;
  background: var(--program-accent-soft);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--program-accent) 18%, transparent);
}

.program-section__logo {
  width: 1.85rem;
  height: 1.85rem;
  object-fit: contain;
}

.program-section__title {
  margin: 0;
  font-size: 1.125rem;
  font-weight: 750;
  letter-spacing: -0.03em;
  color: var(--color-text);
  line-height: 1.2;
}

.program-section__subtitle {
  margin: 0.2rem 0 0;
  font-size: 0.78rem;
  font-weight: 500;
  color: var(--color-text-muted);
  line-height: 1.3;
  letter-spacing: 0.01em;
}

.program-section__actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
}

.program-section__body {
  padding: 0.95rem 0 0.1rem;
}

.program-section__body :slotted(.program-empty) {
  margin: 0;
  padding: 0.2rem 0 0;
  font-size: 0.875rem;
  color: var(--color-text-muted);
  line-height: 1.4;
}

/* Choice pills pick up program accent when selected */
.program-section :deep(.glass-choice--active) {
  border-color: color-mix(in srgb, var(--program-accent) 55%, var(--color-border));
  background: color-mix(in srgb, var(--program-accent) 10%, #fff);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--program-accent) 16%, transparent);
  color: color-mix(in srgb, var(--program-accent) 72%, #111);
}

.program-section :deep(.glass-choice:focus-visible) {
  outline: none;
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--program-accent) 35%, transparent);
}

@media (min-width: 768px) {
  .program-section {
    padding: 1.1rem 1.25rem 1.15rem;
  }

  .program-section__title {
    font-size: 1.2rem;
  }

  .program-section__logo-wrap {
    width: 3rem;
    height: 3rem;
  }

  .program-section__logo {
    width: 2rem;
    height: 2rem;
  }
}
</style>
