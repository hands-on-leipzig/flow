<script setup>
import {ref, onMounted} from 'vue'
import axios from 'axios'

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const error = ref(null)
const successMessage = ref(null)

const form = ref({
  tenant_id: '',
  client_id: '',
  client_secret: '',
  folder_url: '',
  is_enabled: false,
})
const hasClientSecret = ref(false)
const cachedRootName = ref(null)
const testResult = ref(null)

const fetchConfig = async () => {
  loading.value = true
  error.value = null
  try {
    const {data} = await axios.get('/admin/sharepoint')
    form.value.tenant_id = data.tenant_id || ''
    form.value.client_id = data.client_id || ''
    form.value.folder_url = data.folder_url || ''
    form.value.is_enabled = data.is_enabled ?? false
    form.value.client_secret = ''
    hasClientSecret.value = data.has_client_secret ?? false
    cachedRootName.value = data.cached_root_name
  } catch (err) {
    error.value = err.response?.data?.error || 'Konfiguration konnte nicht geladen werden.'
  } finally {
    loading.value = false
  }
}

const saveConfig = async () => {
  saving.value = true
  error.value = null
  successMessage.value = null
  testResult.value = null
  try {
    const payload = {
      tenant_id: form.value.tenant_id.trim() || null,
      client_id: form.value.client_id.trim() || null,
      folder_url: form.value.folder_url.trim() || null,
      is_enabled: form.value.is_enabled,
    }
    if (form.value.client_secret.trim()) {
      payload.client_secret = form.value.client_secret.trim()
    }
    const {data} = await axios.put('/admin/sharepoint', payload)
    successMessage.value = 'Konfiguration gespeichert.'
    form.value.client_secret = ''
    hasClientSecret.value = data.config?.has_client_secret ?? hasClientSecret.value
    cachedRootName.value = data.config?.cached_root_name
  } catch (err) {
    error.value = err.response?.data?.error || err.response?.data?.message || 'Speichern fehlgeschlagen.'
    if (err.response?.data?.errors) {
      error.value += ' ' + JSON.stringify(err.response.data.errors)
    }
  } finally {
    saving.value = false
  }
}

const runTest = async () => {
  testing.value = true
  error.value = null
  testResult.value = null
  try {
    const {data} = await axios.post('/admin/sharepoint/test')
    testResult.value = data
  } catch (err) {
    testResult.value = {
      success: false,
      error: err.response?.data?.error || 'Verbindungstest fehlgeschlagen.',
    }
  } finally {
    testing.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div class="max-w-3xl space-y-6">
    <div>
      <h2 class="text-xl font-bold mb-2">SharePoint / Azure</h2>
      <p class="text-gray-600 text-sm">
        Registriere Flow als App in Azure (App-Registrierung) und vergebe die Anwendungsberechtigung
        <strong>Sites.Read.All</strong> (mit Admin-Einwilligung). Trage Tenant-ID, Client-ID und Client-Secret ein
        und füge den Link zum SharePoint-Ordner ein (Freigabelink oder Ordner-URL).
      </p>
    </div>

    <p v-if="loading" class="text-gray-500">Lade Konfiguration…</p>

    <form v-else class="space-y-4 bg-white rounded-lg shadow p-6 border border-gray-200" @submit.prevent="saveConfig">
      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
      <p v-if="successMessage" class="text-sm text-green-600">{{ successMessage }}</p>

      <label class="flex items-center gap-2">
        <input v-model="form.is_enabled" type="checkbox" class="rounded"/>
        <span class="text-sm font-medium">SharePoint-Integration aktiv</span>
      </label>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Azure Tenant-ID</label>
        <input
            v-model="form.tenant_id"
            type="text"
            class="w-full px-3 py-2 border rounded"
            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Client-ID (Anwendungs-ID)</label>
        <input
            v-model="form.client_id"
            type="text"
            class="w-full px-3 py-2 border rounded"
            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
        />
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Client-Secret</label>
        <input
            v-model="form.client_secret"
            type="password"
            class="w-full px-3 py-2 border rounded"
            :placeholder="hasClientSecret ? '••••••••  (leer lassen = unverändert)' : 'Neues Secret eingeben'"
            autocomplete="new-password"
        />
        <p v-if="hasClientSecret" class="text-xs text-gray-500 mt-1">Ein Secret ist bereits hinterlegt.</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">SharePoint-Ordner-Link</label>
        <input
            v-model="form.folder_url"
            type="url"
            class="w-full px-3 py-2 border rounded"
            placeholder="https://…sharepoint.com/…"
        />
        <p v-if="cachedRootName" class="text-xs text-gray-500 mt-1">
          Zuletzt erkannt: <strong>{{ cachedRootName }}</strong>
        </p>
      </div>

      <div class="flex flex-wrap gap-3 pt-2">
        <button
            type="submit"
            class="px-6 py-2 rounded bg-blue-500 text-white hover:bg-blue-600 disabled:opacity-50"
            :disabled="saving"
        >
          {{ saving ? 'Speichern…' : 'Speichern' }}
        </button>
        <button
            type="button"
            class="px-6 py-2 rounded border border-gray-300 hover:bg-gray-50 disabled:opacity-50"
            :disabled="testing"
            @click="runTest"
        >
          {{ testing ? 'Teste…' : 'Verbindung testen' }}
        </button>
      </div>
    </form>

    <div
        v-if="testResult"
        class="p-4 rounded border text-sm"
        :class="testResult.success ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'"
    >
      <template v-if="testResult.success">
        Verbindung erfolgreich. Ordner „{{ testResult.folder_name }}“ — {{ testResult.item_count }} Einträge im Stammordner.
      </template>
      <template v-else>
        {{ testResult.error }}
      </template>
    </div>

    <div class="text-sm text-gray-600 bg-gray-50 rounded-lg p-4 border">
      <h3 class="font-semibold mb-2">Einrichtung in Azure</h3>
      <ol class="list-decimal list-inside space-y-1">
        <li>Azure Portal → App-Registrierungen → Neue Registrierung</li>
        <li>API-Berechtigungen → Microsoft Graph → Anwendung → <code class="text-xs">Sites.Read.All</code></li>
        <li>Admin-Einwilligung erteilen</li>
        <li>Zertifikate &amp; Geheimnisse → Neues Client-Secret</li>
        <li>SharePoint-Ordner in Teams/SharePoint öffnen → Link kopieren und hier einfügen</li>
      </ol>
    </div>
  </div>
</template>
