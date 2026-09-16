<script lang="ts" setup>
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import {
  DocumentsFolderList,
  DocumentOpeningOverlay,
  DocumentViewerModal,
} from '@hands-on/glass/documents'
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
const currentDriveId = ref<string | null>(null)
const folderWebUrl = ref<string | null>(null)

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
    currentDriveId.value = data.drive_id ?? null
    if (data.folder_web_url) {
      folderWebUrl.value = data.folder_web_url
    }
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

async function navigateTo(crumb: Breadcrumb) {
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
  if (statusRes.data.folder_url) {
    folderWebUrl.value = statusRes.data.folder_url
    folderWebUrl.value = "https://handsontechnology.sharepoint.com/:f:/s/DokumenteundInformationenfrRegionalpartnerInnen/IgDx5Nnfg4GETKBBmnDs0oXEAZXJjJYQ9mnqSzRrDTFktMI?e=TvqXBp"
  }
  if (configured.value) {
    await loadFolder(null)
  }
})
</script>

<template>
  <div>
    <h2 class="glass-card__title !mb-3">Dokumente für Regionalpartner:innen</h2>

    <DocumentsFolderList
      :configured="configured"
      :loading="loading"
      :opening-file="openingFile"
      :error="error || ''"
      :items="items"
      :breadcrumbs="breadcrumbs"
      :folder-web-url="folderWebUrl || ''"
      @open-folder="openFolder"
      @open-file="openFile"
      @navigate="navigateTo"
      @go-up="goUp"
      @go-root="goToRoot"
    />

    <DocumentOpeningOverlay :open="openingFile" title="Dokument wird geladen…" :file-name="openingFileName"/>

    <DocumentViewerModal
      :show="viewerOpen"
      :url="viewerUrl"
      :title="viewerTitle"
      :mode="viewerMode"
      @close="closeViewer"
    />
  </div>
</template>
