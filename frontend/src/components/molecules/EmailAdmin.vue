<script setup>
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'EmailAdmin'})

const loading = ref(false)
const sending = ref(false)
const previewLoading = ref(false)
const error = ref(null)
const email = ref('')
const selectedKey = ref('')
const notifications = ref([])
const preview = ref(null)
const status = ref({
  mailer: '',
  from: '',
  configured: false,
})

const selected = computed(() =>
  notifications.value.find((item) => item.key === selectedKey.value) || null,
)

const canSend = computed(() => {
  return status.value.configured && email.value.trim() !== '' && !!selectedKey.value && !sending.value
})

const statusLabel = (value) => (value === 'live' ? 'aktiv' : 'Entwurf')

const load = async () => {
  loading.value = true
  error.value = null
  try {
    const [statusRes, listRes] = await Promise.all([
      axios.get('/admin/mail'),
      axios.get('/admin/mail/notifications'),
    ])
    status.value = {
      mailer: statusRes.data.mailer || '',
      from: statusRes.data.from || '',
      configured: !!statusRes.data.configured,
    }
    notifications.value = Array.isArray(listRes.data.notifications) ? listRes.data.notifications : []
    if (!notifications.value.some((item) => item.key === selectedKey.value)) {
      selectedKey.value = notifications.value[0]?.key || ''
    }
  } catch (err) {
    error.value = err.response?.data?.error || err.response?.data?.message || 'Mail-Status konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

const loadPreview = async (key) => {
  if (!key) {
    preview.value = null
    return
  }
  previewLoading.value = true
  try {
    const {data} = await axios.get(`/admin/mail/notifications/${encodeURIComponent(key)}/preview`)
    preview.value = data
  } catch (err) {
    preview.value = null
    error.value = err.response?.data?.error || 'Vorschau konnte nicht geladen werden.'
  } finally {
    previewLoading.value = false
  }
}

const sendSample = async () => {
  if (!canSend.value) return
  sending.value = true
  error.value = null
  try {
    await axios.post('/admin/mail/test', {
      email: email.value.trim(),
      key: selectedKey.value,
    })
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

watch(selectedKey, (key) => {
  void loadPreview(key)
})

onMounted(load)
</script>

<template>
  <div class="email-admin">
    <div>
      <h2 class="text-xl font-bold mb-2">E-Mail</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Trigger und Texte stehen im Code. Hier die Übersicht plus Vorschau mit Beispieldaten.
        Ein Editor kommt erst, wenn sich Copy ohne Deploy ändern muss.
      </p>
    </div>

    <p v-if="loading" class="text-[var(--color-text-subtle)]">Lade Notifications…</p>

    <div v-else class="email-admin__body">
      <div class="email-admin__meta glass-surface-lg border border-[var(--color-border)]">
        <dl class="email-admin__dl">
          <div>
            <dt>Absender</dt>
            <dd>{{ status.from || '—' }}</dd>
          </div>
          <div>
            <dt>Mailer</dt>
            <dd>{{ status.mailer || '—' }}</dd>
          </div>
        </dl>
        <p v-if="!status.configured" class="text-sm text-[var(--color-warning, #b45309)]">
          Mailer ist nicht vollständig konfiguriert. Bitte Tenant, Client und Secret auf dem Server prüfen.
        </p>
        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
        <form class="email-admin__send" @submit.prevent="sendSample">
          <label class="block min-w-[16rem] flex-1">
            <span class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Vorschau senden an</span>
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
            {{ sending ? 'Sende…' : 'Diese Vorschau senden' }}
          </button>
        </form>
      </div>

      <div class="email-admin__split">
        <section class="email-admin__list glass-surface-lg border border-[var(--color-border)]">
          <h3 class="email-admin__pane-title">Trigger</h3>
          <ul class="email-admin__items">
            <li v-for="item in notifications" :key="item.key">
              <button
                  type="button"
                  class="email-admin__item"
                  :class="{'email-admin__item--active': item.key === selectedKey}"
                  @click="selectedKey = item.key"
              >
                <span class="email-admin__item-name">{{ item.name }}</span>
                <span
                    class="glass-chip !px-2 !py-0.5 !text-xs"
                    :class="item.status === 'live' ? 'email-admin__chip--live' : ''"
                >
                  {{ statusLabel(item.status) }}
                </span>
                <span class="email-admin__item-trigger">{{ item.trigger }}</span>
              </button>
            </li>
          </ul>
        </section>

        <section class="email-admin__preview glass-surface-lg border border-[var(--color-border)]">
          <h3 class="email-admin__pane-title">Vorschau</h3>
          <p v-if="previewLoading" class="text-sm text-[var(--color-text-subtle)]">Lade Vorschau…</p>
          <template v-else-if="preview && selected">
            <dl class="email-admin__preview-meta">
              <div>
                <dt>Auslöser</dt>
                <dd>{{ selected.trigger }}</dd>
              </div>
              <div>
                <dt>Empfänger</dt>
                <dd>{{ selected.audience }}</dd>
              </div>
              <div>
                <dt>Betreff</dt>
                <dd>{{ preview.subject }}</dd>
              </div>
            </dl>
            <iframe
                class="email-admin__frame"
                title="Mail-Vorschau"
                sandbox
                :srcdoc="preview.html"
            />
          </template>
          <p v-else class="text-sm text-[var(--color-text-subtle)]">Bitte einen Trigger wählen.</p>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.email-admin {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-height: 0;
  height: 100%;
}

.email-admin__body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-height: 0;
  flex: 1 1 auto;
}

.email-admin__meta {
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.email-admin__dl {
  display: grid;
  gap: 0.75rem 1.5rem;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
}

.email-admin__dl dt {
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.email-admin__dl dd {
  margin: 0;
  font-weight: 600;
}

.email-admin__send {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 0.75rem;
}

.email-admin__split {
  display: grid;
  grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
  gap: 1rem;
  min-height: 0;
  flex: 1 1 auto;
}

.email-admin__list,
.email-admin__preview {
  min-height: 0;
  display: flex;
  flex-direction: column;
  padding: 1rem;
}

.email-admin__pane-title {
  font-size: 0.95rem;
  font-weight: 700;
  margin: 0 0 0.75rem;
}

.email-admin__items {
  list-style: none;
  margin: 0;
  padding: 0;
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.email-admin__item {
  width: 100%;
  text-align: left;
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.25rem 0.5rem;
  padding: 0.7rem 0.8rem;
  border-radius: 0.75rem;
  border: 1px solid transparent;
  background: transparent;
  color: inherit;
  cursor: pointer;
}

.email-admin__item:hover {
  background: var(--color-bg-hover, rgba(0, 0, 0, 0.04));
}

.email-admin__item--active {
  border-color: var(--color-border);
  background: var(--color-bg-muted, rgba(0, 0, 0, 0.04));
}

.email-admin__item-name {
  font-weight: 600;
}

.email-admin__item-trigger {
  grid-column: 1 / -1;
  font-size: 0.8rem;
  color: var(--color-text-muted);
  line-height: 1.35;
}

.email-admin__chip--live {
  background: color-mix(in srgb, var(--color-accent) 16%, transparent);
}

.email-admin__preview-meta {
  display: grid;
  gap: 0.55rem;
  margin: 0 0 0.85rem;
  font-size: 0.875rem;
}

.email-admin__preview-meta dt {
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.email-admin__preview-meta dd {
  margin: 0;
}

.email-admin__frame {
  flex: 1 1 auto;
  min-height: 18rem;
  width: 100%;
  border: 1px solid var(--color-border);
  border-radius: 0.75rem;
  background: #fff;
}

@media (max-width: 900px) {
  .email-admin__split {
    grid-template-columns: 1fr;
  }

  .email-admin__list {
    max-height: 16rem;
  }
}
</style>
