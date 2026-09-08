<script setup lang="ts">
import {computed} from 'vue'
import {programLogoSrc} from '@/utils/images'

const props = withDefaults(defineProps<{
  label: string
  subtitle?: string | null
  logoStem?: string | null
  /** Bootstrap icon class for non-program scopes (cross / local). */
  scopeIcon?: string | null
  status?: string | null
  statusTitle?: string
  time?: string | null
  mobile?: string | null
  /** Telefonbuch only — Check-In missing/search hide the call control. */
  showCall?: boolean
  /** Render as a clickable button (Check-In lists). */
  interactive?: boolean
}>(), {
  subtitle: null,
  logoStem: null,
  scopeIcon: null,
  status: null,
  statusTitle: '',
  time: null,
  mobile: null,
  showCall: false,
  interactive: false,
})

const emit = defineEmits<{
  (e: 'select'): void
}>()

const programSrc = computed(() =>
  props.logoStem ? programLogoSrc({logo_stem: props.logoStem}) : '',
)

const showSub = computed(() =>
  !!(props.logoStem || props.scopeIcon || props.subtitle),
)

function statusIcon(status: string | null | undefined) {
  if (status === 'no_show') return 'bi-x-circle-fill'
  if (status === 'checked_in') return 'bi-check-circle-fill'
  return 'bi-circle'
}

function telHref(mobile: string) {
  return `tel:${mobile.replace(/[^\d+]/g, '')}`
}
</script>

<template>
  <component
      :is="interactive ? 'button' : 'div'"
      :type="interactive ? 'button' : undefined"
      class="ci-hit liquid-surface-inner"
      :class="{'ci-hit--interactive': interactive}"
      @click="interactive ? emit('select') : undefined"
  >
    <span class="ci-hit__row">
      <span class="ci-hit__label">{{ label }}</span>
      <span class="ci-hit__trailing">
        <span v-if="time" class="ci-hit__time">{{ time }}</span>
        <a
            v-if="showCall && mobile"
            class="ci-call"
            :href="telHref(mobile)"
            :title="`Anrufen ${mobile}`"
            @click.stop
        >
          <i class="bi bi-telephone-fill" aria-hidden="true"/>
          <span class="sr-only">Anrufen</span>
        </a>
        <span
            class="ci-hit__status"
            :class="{
              'ci-hit__status--in': status === 'checked_in',
              'ci-hit__status--no': status === 'no_show',
            }"
            :title="statusTitle || undefined"
        >
          <i class="bi" :class="statusIcon(status)" aria-hidden="true"/>
          <span class="sr-only">{{ statusTitle }}</span>
        </span>
      </span>
    </span>
    <span v-if="showSub" class="ci-hit__row ci-hit__row--sub">
      <img
          v-if="programSrc"
          class="ci-hit__program"
          :src="programSrc"
          alt=""
          aria-hidden="true"
      />
      <i
          v-else-if="scopeIcon"
          class="bi ci-hit__program-icon"
          :class="scopeIcon"
          aria-hidden="true"
      />
      <span v-if="subtitle" class="ci-hit__sub">{{ subtitle }}</span>
    </span>
  </component>
</template>

<style scoped>
.ci-hit {
  width: 100%;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.85rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border-soft);
  color: var(--color-text);
  background: transparent;
  font: inherit;
}

.ci-hit--interactive {
  cursor: pointer;
}

.ci-hit--interactive:hover {
  background: var(--color-bg-hover);
}

.ci-hit__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  min-width: 0;
}

.ci-hit__row--sub {
  justify-content: flex-start;
  gap: 0.45rem;
}

.ci-hit__label {
  font-weight: 700;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ci-hit__trailing {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  flex-shrink: 0;
}

.ci-hit__time {
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-text-muted);
}

.ci-hit__program {
  width: 1.15rem;
  height: 1.15rem;
  object-fit: contain;
  flex-shrink: 0;
}

.ci-hit__program-icon {
  flex-shrink: 0;
  font-size: 1rem;
  line-height: 1;
  color: var(--color-text-muted);
}

.ci-hit__sub {
  font-size: 0.85rem;
  color: var(--color-text-muted);
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.ci-hit__status {
  font-size: 1.25rem;
  line-height: 1;
  flex-shrink: 0;
  color: var(--color-text-muted);
}

.ci-hit__status--in {
  color: #059669;
}

.ci-hit__status--no {
  color: #dc2626;
}

.ci-call {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  padding: 0;
  border: none;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-accent) 14%, transparent);
  color: var(--color-accent);
  font-size: 1.05rem;
  line-height: 1;
  text-decoration: none;
  cursor: pointer;
}

.ci-call:hover {
  background: color-mix(in srgb, var(--color-accent) 22%, transparent);
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
