<script setup lang="ts">
import {onMounted, onUnmounted, ref} from 'vue'
import ItemCard from '@/components/molecules/ItemCard.vue'

const props = withDefaults(defineProps<{
  title: string
  disabled?: boolean
  titlePlaceholder?: string
  emptyHint?: string
}>(), {
  disabled: false,
  titlePlaceholder: 'Titel',
  emptyHint: '',
})

const emit = defineEmits<{
  'update:title': [value: string]
  commit: []
}>()

const rootRef = ref<HTMLElement | null>(null)
const titleRef = ref<HTMLInputElement | null>(null)

function commit() {
  if (props.disabled) return
  if (!props.title.trim()) return
  emit('commit')
}

function onDocumentClick(event: MouseEvent) {
  const target = event.target
  if (!(target instanceof Node)) return
  if (target instanceof Element && target.closest('[data-item-composer]')) return
  commit()
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onUnmounted(() => document.removeEventListener('click', onDocumentClick))

defineExpose({
  focusTitle: () => titleRef.value?.focus(),
})
</script>

<template>
  <div ref="rootRef" data-item-composer class="item-composer" @keydown.enter.prevent="commit">
    <ItemCard dashed>
      <template #title>
        <input
            ref="titleRef"
            :value="title"
            :disabled="disabled"
            class="item-card__title glass-input glass-input--sm liquid-surface-control"
            type="text"
            :placeholder="titlePlaceholder"
            @input="emit('update:title', ($event.target as HTMLInputElement).value)"
        />
      </template>
      <p v-if="emptyHint && !title.trim()" class="item-card__hint">
        {{ emptyHint }}
      </p>
      <slot/>
    </ItemCard>
  </div>
</template>
