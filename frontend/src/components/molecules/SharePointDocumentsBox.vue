<script lang="ts" setup>
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'

interface SharePointItem {
  id: string
  name: string
  type: 'folder' | 'file'
  size: number | null
  modified: string | null
  web_url: string | null
}

interface Breadcrumb {
  id: string
  name: string
}

const configured = ref(false)
const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<SharePointItem[]>([])
const breadcrumbs = ref<Breadcrumb[]>([])
const currentItemId = ref<string | null>(null)

const formatSize = (bytes: number | null) => {
  if (bytes == null) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

const formatDate = (iso: string | null) => {
  if (!iso) return ''
  return dayjs(iso).format('DD.MM.YYYY HH:mm')
}

const canGoUp = computed(() => breadcrumbs.value.length > 1)

async function loadFolder(itemId: string | null = null) {
  loading.value = true
  error.value = null
  try {
    const params = itemId ? {item_id: itemId} : {}
    const {data} = await axios.get('/sharepoint/documents', {params})
    configured.value = data.configured ?? false
    if (data.error) {
      error.value = data.error
      items.value = []
      return
    }
    items.value = data.items ?? []
    breadcrumbs.value = data.breadcrumbs ?? []
    currentItemId.value = data.current_item_id ?? null
  } catch (err: unknown) {
    const axiosErr = err as { response?: { data?: { error?: string } } }
    error.value = axiosErr.response?.data?.error || 'Dokumente konnten nicht geladen werden.'
    items.value = []
  } finally {
    loading.value = false
  }
}

async function openFolder(item: SharePointItem) {
  await loadFolder(item.id)
}

function openFile(item: SharePointItem) {
  if (item.web_url) {
    window.open(item.web_url, '_blank', 'noopener,noreferrer')
  }
}

async function navigateTo(crumb: Breadcrumb, index: number) {
  if (index === breadcrumbs.value.length - 1) return
  await loadFolder(crumb.id)
}

async function goUp() {
  if (!canGoUp.value) return
  const parent = breadcrumbs.value[breadcrumbs.value.length - 2]
  await loadFolder(parent.id)
}

async function goToRoot() {
  await loadFolder(null)
}

onMounted(async () => {
  const statusRes = await axios.get('/sharepoint/status')
  configured.value = statusRes.data.configured ?? false
  if (configured.value) {
    await loadFolder(null)
  }
})
</script>

<template>
  <div>
    <h2 class="glass-card__title !mb-3">Dokumente für Regionalpartner:innen</h2>

    <p v-if="!configured && !loading" class="text-sm text-[var(--color-text-muted)]">
      Noch keine Dokumentenablage konfiguriert.
    </p>

    <template v-else>
      <div v-if="breadcrumbs.length" class="flex flex-wrap items-center gap-1 text-sm text-[var(--color-text-muted)] mb-3">
        <button
            type="button"
            class="text-[var(--color-accent)] hover:underline"
            :disabled="loading"
            @click="goToRoot"
        >
          Start
        </button>
        <template v-for="(crumb, index) in breadcrumbs" :key="crumb.id">
          <span>/</span>
          <button
              v-if="index < breadcrumbs.length - 1"
              type="button"
              class="text-[var(--color-accent)] hover:underline"
              :disabled="loading"
              @click="navigateTo(crumb, index)"
          >
            {{ crumb.name }}
          </button>
          <span v-else class="font-medium text-[var(--color-text)]">{{ crumb.name }}</span>
        </template>
        <button
            v-if="canGoUp"
            type="button"
            class="ml-2 text-xs text-[var(--color-text-subtle)] hover:text-[var(--color-text-muted)]"
            :disabled="loading"
            @click="goUp"
        >
          ↑ Eine Ebene höher
        </button>
      </div>

      <p v-if="error" class="text-sm text-red-600 mb-2">{{ error }}</p>

      <div v-if="loading" class="text-sm text-[var(--color-text-muted)] py-4">Lade Dokumente…</div>

      <div
          v-else-if="items.length === 0 && !error"
          class="text-sm text-[var(--color-text-muted)] py-4"
      >
        Dieser Ordner ist leer.
      </div>

      <ul v-else-if="!loading" class="glass-list liquid-surface-inner">
        <li
            v-for="item in items"
            :key="item.id"
            class="glass-list__item"
        >
          <span class="flex-shrink-0 w-5 text-center" aria-hidden="true">
            {{ item.type === 'folder' ? '📁' : '📄' }}
          </span>
          <button
              v-if="item.type === 'folder'"
              type="button"
              class="flex-1 text-left font-medium text-[var(--color-accent)] hover:underline truncate"
              @click="openFolder(item)"
          >
            {{ item.name }}
          </button>
          <button
              v-else
              type="button"
              class="flex-1 text-left truncate hover:underline text-[var(--color-text)]"
              @click="openFile(item)"
          >
            {{ item.name }}
          </button>
          <span v-if="item.type === 'file' && item.size" class="text-[var(--color-text-subtle)] text-xs flex-shrink-0">
            {{ formatSize(item.size) }}
          </span>
          <span v-if="item.modified" class="text-[var(--color-text-subtle)] text-xs flex-shrink-0 hidden sm:inline">
            {{ formatDate(item.modified) }}
          </span>
        </li>
      </ul>
    </template>
  </div>
</template>
