<script setup>
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'EmailAdmin'})

const loading = ref(false)
const sending = ref(false)
const error = ref(null)
const email = ref('')
const status = ref({
  mailer: '',
  from: '',
  configured: false,
})

const canSend = computed(() => {
  return status.value.configured && email.value.trim() !== '' && !sending.value
})

const loadStatus = async () => {
  loading.value = true
  error.value = null
  try {
    const {data} = await axios.get('/admin/mail')
    status.value = {
      mailer: data.mailer || '',
      from: data.from || '',
      configured: !!data.configured,
    }
  } catch (err) {
    error.value = err.response?.data?.error || 'Mail-Status konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

const sendTest = async () => {
  if (!canSend.value) return
  sending.value = true
  error.value = null
  try {
    await axios.post('/admin/mail/test', {email: email.value.trim()})
    showGlassToast('Testmail gesendet.', 'success')
  } catch (err) {
    const message = err.response?.data?.error
      || err.response?.data?.message
      || err.response?.data?.errors?.email?.[0]
      || 'Senden fehlgeschlagen.'
    error.value = message
    showGlassToast('Senden fehlgeschlagen.', 'error')
  } finally {
    sending.value = false
  }
}

onMounted(loadStatus)
</script>

<template>
  <div class="max-w-3xl space-y-6">
    <div>
      <h2 class="text-xl font-bold mb-2">E-Mail</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Testversand über Microsoft Graph. Absender und App-Registrierung stehen in der Server-Konfiguration.
      </p>
    </div>

    <p v-if="loading" class="text-[var(--color-text-subtle)]">Lade Status…</p>

    <div v-else class="space-y-4 glass-surface-lg border border-[var(--color-border)]">
      <dl class="grid gap-2 text-sm sm:grid-cols-[8rem_1fr]">
        <dt class="text-[var(--color-text-muted)]">Absender</dt>
        <dd class="font-medium">{{ status.from || '—' }}</dd>
        <dt class="text-[var(--color-text-muted)]">Mailer</dt>
        <dd class="font-medium">{{ status.mailer || '—' }}</dd>
      </dl>

      <p v-if="!status.configured" class="text-sm text-[var(--color-warning, #b45309)]">
        Mailer ist nicht vollständig konfiguriert. Bitte Tenant, Client und Secret auf dem Server prüfen.
      </p>
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

      <form class="flex flex-wrap items-end gap-3" @submit.prevent="sendTest">
        <label class="block min-w-[16rem] flex-1">
          <span class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Empfänger</span>
          <input
              v-model="email"
              type="email"
              class="glass-input w-full"
              placeholder="name@example.org"
              autocomplete="email"
              :disabled="sending || !status.configured"
          />
        </label>
        <button
            type="submit"
            class="glass-btn-accent !px-4 !py-2 !text-sm disabled:opacity-50"
            :disabled="!canSend"
        >
          {{ sending ? 'Sende…' : 'Testmail senden' }}
        </button>
      </form>
    </div>
  </div>
</template>
