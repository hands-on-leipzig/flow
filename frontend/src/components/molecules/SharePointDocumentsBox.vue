<script lang="ts" setup>
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import DocumentViewerModal from '@/components/atoms/DocumentViewerModal.vue'
import {useSharePointFileOpen} from '@/composables/useSharePointFileOpen'

interface SharePointItem {
  id: string
  drive_id?: string | null
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
const openingFile = ref(false)
const openingFileName = ref('')
const error = ref<string | null>(null)
const items = ref<SharePointItem[]>([])
const breadcrumbs = ref<Breadcrumb[]>([])
const currentItemId = ref<string | null>(null)
const currentDriveId = ref<string | null>(null)

const viewerOpen = ref(false)
const viewerUrl = ref('')
const viewerTitle = ref('')
const viewerMode = ref<'pdf' | 'image'>('pdf')
const viewerBlobUrl = ref('')

function closeViewer() {
  viewerOpen.value = false
  viewerUrl.value = ''
  viewerTitle.value = ''
  if (viewerBlobUrl.value) {
    URL.revokeObjectURL(viewerBlobUrl.value)
    viewerBlobUrl.value = ''
  }
}

async function openPdfFromBlob(blob: Blob, title: string): Promise<boolean> {
  if (!blob || blob.size < 100) return false
  if (viewerBlobUrl.value) {
    URL.revokeObjectURL(viewerBlobUrl.value)
  }
  viewerBlobUrl.value = URL.createObjectURL(blob)
  viewerUrl.value = viewerBlobUrl.value
  viewerTitle.value = title
  viewerMode.value = 'pdf'
  viewerOpen.value = true
  return true
}

async function openImageFromBlob(blob: Blob, title: string): Promise<boolean> {
  if (!blob || blob.size < 1) return false
  if (viewerBlobUrl.value) {
    URL.revokeObjectURL(viewerBlobUrl.value)
  }
  viewerBlobUrl.value = URL.createObjectURL(blob)
  viewerUrl.value = viewerBlobUrl.value
  viewerTitle.value = title
  viewerMode.value = 'image'
  viewerOpen.value = true
  return true
}

const {openDocumentFile} = useSharePointFileOpen({
  openPdfFromBlob,
  openImageFromBlob,
})

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
    currentDriveId.value = data.drive_id ?? null
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

async function openFile(item: SharePointItem) {
  if (openingFile.value) return
  openingFile.value = true
  openingFileName.value = item.name
  error.value = null
  try {
    const driveId = item.drive_id || currentDriveId.value || undefined
    if (!driveId) {
      error.value = 'Datei konnte nicht geöffnet werden (keine Drive-ID).'
      return
    }
    const ok = await openDocumentFile({...item, drive_id: driveId})
    if (!ok) {
      error.value = 'Datei konnte nicht geladen werden.'
    }
  } finally {
    openingFile.value = false
    openingFileName.value = ''
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
    <h2 class="text-lg font-semibold mb-3">Dokumente für Regionalpartner:innen</h2>

    <p v-if="!configured && !loading" class="text-sm text-gray-500">
      Noch keine Dokumentenablage konfiguriert.
    </p>

    <template v-else>
      <div v-if="breadcrumbs.length" class="flex flex-wrap items-center gap-1 text-sm text-gray-600 mb-3">
        <button
            type="button"
            class="text-blue-600 hover:underline"
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
              class="text-blue-600 hover:underline"
              :disabled="loading"
              @click="navigateTo(crumb, index)"
          >
            {{ crumb.name }}
          </button>
          <span v-else class="font-medium text-gray-800">{{ crumb.name }}</span>
        </template>
        <button
            v-if="canGoUp"
            type="button"
            class="ml-2 text-xs text-gray-500 hover:text-gray-700"
            :disabled="loading"
            @click="goUp"
        >
          ↑ Eine Ebene höher
        </button>
      </div>

      <p v-if="error" class="text-sm text-red-600 mb-2">{{ error }}</p>

      <p v-if="openingFile" class="text-sm text-gray-500 mb-2">
        Öffne {{ openingFileName }}…
      </p>

      <div v-if="loading" class="text-sm text-gray-500 py-4">Lade Dokumente…</div>

      <div
          v-else-if="items.length === 0 && !error"
          class="text-sm text-gray-500 py-4"
      >
        Dieser Ordner ist leer.
      </div>

      <ul v-else-if="!loading" class="divide-y border rounded max-h-80 overflow-y-auto">
        <li
            v-for="item in items"
            :key="item.id"
            class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 text-sm"
        >
          <span class="flex-shrink-0 w-5 text-center" aria-hidden="true">
            {{ item.type === 'folder' ? '📁' : '📄' }}
          </span>
          <button
              v-if="item.type === 'folder'"
              type="button"
              class="flex-1 text-left font-medium text-blue-700 hover:underline truncate"
              @click="openFolder(item)"
          >
            {{ item.name }}
          </button>
          <button
              v-else
              type="button"
              class="flex-1 text-left truncate hover:underline"
              :disabled="openingFile"
              @click="openFile(item)"
          >
            {{ item.name }}
          </button>
          <span v-if="item.type === 'file' && item.size" class="text-gray-400 text-xs flex-shrink-0">
            {{ formatSize(item.size) }}
          </span>
          <span v-if="item.modified" class="text-gray-400 text-xs flex-shrink-0 hidden sm:inline">
            {{ formatDate(item.modified) }}
          </span>
        </li>
      </ul>
    </template>

    <DocumentViewerModal
        :show="viewerOpen"
        :url="viewerUrl"
        :title="viewerTitle"
        :mode="viewerMode"
        @close="closeViewer"
    />
  </div>
</template>
