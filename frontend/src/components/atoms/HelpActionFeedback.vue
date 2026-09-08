<script setup lang="ts">
import {ref, watch} from 'vue'
import axios from 'axios'

defineOptions({name: 'HelpActionFeedback'})

const props = defineProps<{actionId: number}>()

const STORAGE_PREFIX = 'flow.helpVote.'

function storageKey(id: number): string {
  return STORAGE_PREFIX + id
}

function readVote(id: number): boolean | null {
  try {
    const raw = localStorage.getItem(storageKey(id))
    if (raw === 'yes') return true
    if (raw === 'no') return false
    return null
  } catch {
    return null
  }
}

function writeVote(id: number, helpful: boolean) {
  try {
    localStorage.setItem(storageKey(id), helpful ? 'yes' : 'no')
  } catch {
    /* ignore quota / private mode */
  }
}

const vote = ref<boolean | null>(readVote(props.actionId))

watch(() => props.actionId, (id) => {
  vote.value = readVote(id)
})

async function send(helpful: boolean) {
  const previous = vote.value
  if (previous === helpful) return
  try {
    await axios.post(`/help/actions/${props.actionId}/feedback`, {helpful, previous})
    writeVote(props.actionId, helpful)
    vote.value = helpful
  } catch (e) {
    console.warn(e)
  }
}
</script>

<template>
  <div class="help-action-feedback">
    <p class="help-action-feedback__label">War das hilfreich?</p>
    <button
        type="button"
        class="help-action-feedback__vote"
        :class="{'help-action-feedback__vote--on': vote === true}"
        aria-label="Ja"
        title="Ja"
        :aria-pressed="vote === true"
        @click="send(true)"
    >
      <i class="bi" :class="vote === true ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up'" aria-hidden="true"/>
    </button>
    <button
        type="button"
        class="help-action-feedback__vote"
        :class="{'help-action-feedback__vote--on': vote === false}"
        aria-label="Nein"
        title="Nein"
        :aria-pressed="vote === false"
        @click="send(false)"
    >
      <i class="bi" :class="vote === false ? 'bi-hand-thumbs-down-fill' : 'bi-hand-thumbs-down'" aria-hidden="true"/>
    </button>
  </div>
</template>

<style scoped>
.help-action-feedback {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.help-action-feedback__label {
  margin: 0;
  font-size: 0.75rem;
  font-weight: 400;
  line-height: 1.3;
  color: var(--color-text-muted);
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
.help-action-feedback__vote:hover,
.help-action-feedback__vote--on {
  color: var(--color-accent);
}
</style>
