<script setup lang="ts">
import axios from 'axios'

defineOptions({name: 'HelpActionFeedback'})

const props = defineProps<{actionId: number}>()

async function send(helpful: boolean) {
  try {
    await axios.post(`/help/actions/${props.actionId}/feedback`, {helpful})
  } catch (e) {
    console.warn(e)
  }
}
</script>

<template>
  <div class="help-action-feedback">
    <p class="text-sm font-medium !mb-0">War das hilfreich?</p>
    <button type="button" class="help-action-feedback__vote" aria-label="Ja" title="Ja" @click="send(true)">
      <i class="bi bi-hand-thumbs-up" aria-hidden="true"/>
    </button>
    <button type="button" class="help-action-feedback__vote" aria-label="Nein" title="Nein" @click="send(false)">
      <i class="bi bi-hand-thumbs-down" aria-hidden="true"/>
    </button>
  </div>
</template>

<style scoped>
.help-action-feedback {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.help-action-feedback__vote {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.15rem;
  border: 0;
  background: transparent;
  color: var(--color-text-muted);
  font-size: 1.1rem;
  line-height: 1;
  cursor: pointer;
}
.help-action-feedback__vote:hover {
  color: var(--color-accent);
}
</style>
