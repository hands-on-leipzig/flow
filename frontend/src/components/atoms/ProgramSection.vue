<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {getProgramTheme} from '@/utils/programTheme'

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
      /** Optional muted text after the heading (e.g. "(3 verändert)"). */
      headingSuffix?: string
      /** When true, header toggles the body open/closed. */
      collapsible?: boolean
      /** Initial collapsed state when collapsible (ignored after first toggle). */
      defaultCollapsed?: boolean
    }>(),
    {
      active: true,
      showLogo: true,
      collapsible: false,
      defaultCollapsed: false,
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

const collapsed = ref(props.collapsible && props.defaultCollapsed)

watch(
    () => props.collapsible,
    (on) => {
      if (!on) collapsed.value = false
    }
)

const bodyOpen = computed(() => !props.collapsible || !collapsed.value)

function toggleCollapsed() {
  if (!props.collapsible) return
  collapsed.value = !collapsed.value
}
</script>

<template>
  <section
      class="program-section glass-card liquid-surface-inner"
      :class="{
        'program-section--inactive': !active,
        'program-section--collapsed': collapsible && collapsed,
      }"
      :style="{ '--program-accent': theme.accent }"
      :data-program="program"
  >
    <header
        class="program-section__header"
        :class="{ 'program-section__header--toggle': collapsible }"
    >
      <button
          v-if="collapsible"
          type="button"
          class="program-section__toggle"
          :aria-expanded="bodyOpen"
          @click="toggleCollapsed"
      >
        <div class="program-section__identity">
          <ProgramLogo
              v-if="showLogoImg"
              :program="theme.catalogName"
              size="section"
              :muted="!active"
          />
          <div class="min-w-0">
            <h3 class="program-section__title glass-card__title !mb-0">
              {{ heading }}<span
                  v-if="headingSuffix"
                  class="program-section__title-suffix"
              >{{ headingSuffix }}</span>
            </h3>
            <p v-if="subtitleText" class="program-section__subtitle">
              <template v-if="!subtitle && !title">
                <span class="italic">FIRST</span> LEGO League
              </template>
              <template v-else>{{ subtitleText }}</template>
            </p>
          </div>
        </div>
        <i
            class="bi program-section__chevron"
            :class="collapsed ? 'bi-chevron-down' : 'bi-chevron-up'"
            aria-hidden="true"
        />
      </button>
      <template v-else>
        <div class="program-section__identity">
          <ProgramLogo
              v-if="showLogoImg"
              :program="theme.catalogName"
              size="section"
              :muted="!active"
          />
          <div class="min-w-0">
            <h3 class="program-section__title glass-card__title !mb-0">
              {{ heading }}<span
                  v-if="headingSuffix"
                  class="program-section__title-suffix"
              >{{ headingSuffix }}</span>
            </h3>
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
      </template>
      <div v-if="collapsible && $slots.actions" class="program-section__actions">
        <slot name="actions"/>
      </div>
    </header>
    <div v-show="bodyOpen" class="program-section__body glass-settings-block">
      <slot/>
    </div>
    <div v-if="bodyOpen && $slots.footer" class="program-section__footer">
      <slot name="footer"/>
    </div>
  </section>
</template>

<style scoped>
.program-section {
  --program-accent: var(--color-accent);
  --program-accent-soft: color-mix(in srgb, var(--program-accent) 14%, transparent);
  position: relative;
  min-width: 0;
  overflow: visible;
  padding: 0.7rem 1.1rem 0.75rem;
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

.program-section--inactive .program-section__logo {
  filter: grayscale(0.35);
  opacity: 0.75;
}

.program-section--collapsed {
  padding-bottom: 0.55rem;
}

.program-section__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.85rem 1rem;
  flex-wrap: wrap;
  padding: 0 0 0.4rem;
  margin-bottom: 0;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.program-section--collapsed .program-section__header {
  border-bottom-color: transparent;
  padding-bottom: 0;
}

.program-section__toggle {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex: 1 1 auto;
  min-width: 0;
  margin: 0;
  padding: 0;
  border: none;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}

.program-section__toggle:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--program-accent) 45%, transparent);
  outline-offset: 2px;
  border-radius: var(--radius);
}

.program-section__chevron {
  flex-shrink: 0;
  font-size: 0.95rem;
  color: var(--color-text-muted);
}

.program-section__identity {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  flex: 1 1 auto;
}

.program-section__logo {
  width: 2.25rem;
  height: 2.25rem;
  flex-shrink: 0;
  object-fit: contain;
}

.program-section__title {
  margin: 0;
  letter-spacing: -0.03em;
  line-height: 1.2;
}

.program-section__title-suffix {
  font-weight: 550;
  letter-spacing: 0;
  color: var(--color-text-muted);
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
  padding: 0.5rem 0 0.1rem;
}

.program-section__footer {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.35rem;
  padding-top: 0.15rem;
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
    padding: 0.8rem 1.25rem 0.85rem;
  }
}
</style>
