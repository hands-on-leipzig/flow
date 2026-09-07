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
    <p class="text-sm font-medium mb-1">War das hilfreich?</p>
    <div class="flex gap-2">
      <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="send(true)">Ja</button>
      <button type="button" class="glass-btn-secondary !px-3 !py-1" @click="send(false)">Nein</button>
    </div>
  </div>
</template>
