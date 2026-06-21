<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

// ─── State ────────────────────────────────────────────────────────────────────

const loading     = ref(false)
const syncing     = ref(false)
const error       = ref(null)
const partners    = ref([])
const seasonId    = ref(null)
const expandedIds = ref(new Set())   // expanded partner IDs
const baseUrl     = ref(window.location.origin)

// Edit state for partner prefixes
const editingPrefixes   = ref(null)   // partner id being edited
const prefixForm        = ref({ slug_long: '', slug_short: '' })
const savingPrefixes    = ref(false)
const prefixSaveError   = ref(null)

// Edit state for individual slugs
const editingSlugId     = ref(null)
const slugEditValue     = ref('')
const savingSlug        = ref(false)
const slugSaveError     = ref(null)

// Per-event regenerating state
const regeneratingEvent = ref(null)

// ─── Lifecycle ────────────────────────────────────────────────────────────────

onMounted(loadOverview)

// ─── Load / Sync ──────────────────────────────────────────────────────────────

async function loadOverview() {
  loading.value = true
  error.value   = null
  try {
    const { data } = await axios.get('/admin/slugs/overview')
    partners.value = data.partners
    seasonId.value = data.season_id
  } catch (err) {
    error.value = err.response?.data?.error || 'Fehler beim Laden der Slug-Übersicht'
  } finally {
    loading.value = false
  }
}

async function syncAll() {
  if (!confirm('Slugs für alle Events der aktuellen Saison neu synchronisieren?\n\nVorhandene Slugs bleiben erhalten und werden bei Bedarf ergänzt. QR-Codes werden nicht neu generiert.')) return

  syncing.value = true
  error.value   = null
  try {
    const { data } = await axios.post('/admin/slugs/sync-season')
    alert(`Synchronisation abgeschlossen.\n\n✅ Generiert: ${data.generated}\n❌ Fehler: ${data.failed}\n\n${data.errors.join('\n')}`)
    await loadOverview()
  } catch (err) {
    error.value = err.response?.data?.error || 'Synchronisation fehlgeschlagen'
  } finally {
    syncing.value = false
  }
}

// ─── Partner expand / collapse ───────────────────────────────────────────────

function togglePartner(id) {
  expandedIds.value.has(id) ? expandedIds.value.delete(id) : expandedIds.value.add(id)
}

function expandAll()   { partners.value.forEach(p => expandedIds.value.add(p.id)) }
function collapseAll() { expandedIds.value.clear() }

// ─── Partner prefix editing ───────────────────────────────────────────────────

function startEditPrefixes(partner) {
  editingPrefixes.value = partner.id
  prefixForm.value = { slug_long: partner.slug_long || '', slug_short: partner.slug_short || '' }
  prefixSaveError.value = null
}

function cancelEditPrefixes() {
  editingPrefixes.value = null
  prefixSaveError.value = null
}

async function savePrefixes(partner) {
  savingPrefixes.value  = true
  prefixSaveError.value = null
  try {
    const { data } = await axios.put(`/admin/slugs/partners/${partner.id}/prefixes`, prefixForm.value)
    partner.slug_long  = data.slug_long
    partner.slug_short = data.slug_short
    editingPrefixes.value = null
  } catch (err) {
    prefixSaveError.value = err.response?.data?.error
      || err.response?.data?.message
      || 'Fehler beim Speichern'
  } finally {
    savingPrefixes.value = false
  }
}

// ─── Individual slug editing ──────────────────────────────────────────────────

function startEditSlug(slug) {
  editingSlugId.value = slug.id
  slugEditValue.value = slug.slug
  slugSaveError.value = null
}

function cancelEditSlug() {
  editingSlugId.value = null
  slugSaveError.value = null
}

async function saveSlug(event, slug) {
  savingSlug.value    = true
  slugSaveError.value = null
  try {
    const { data } = await axios.put(`/admin/slugs/${slug.id}`, { slug: slugEditValue.value })
    const idx = event.slugs.findIndex(s => s.id === slug.id)
    if (idx !== -1) event.slugs[idx].slug = data.slug.slug
    editingSlugId.value = null
  } catch (err) {
    slugSaveError.value = err.response?.data?.error || 'Fehler beim Speichern'
  } finally {
    savingSlug.value = false
  }
}

// ─── Event regenerate ─────────────────────────────────────────────────────────

async function regenerateEvent(event) {
  if (!confirm(`Slugs für "${event.name}" neu generieren?\n\nDies löscht alle vorhandenen Slugs und QR-Codes für dieses Event und erstellt sie neu.`)) return

  regeneratingEvent.value = event.id
  try {
    const { data } = await axios.post(`/admin/slugs/events/${event.id}/regenerate`)
    event.slugs    = data.slugs
    event.has_slugs = data.slugs.length > 0
  } catch (err) {
    alert('Fehler beim Regenerieren: ' + (err.response?.data?.error || err.message))
  } finally {
    regeneratingEvent.value = null
  }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

const variantLabel = { long: 'lang', short: 'kurz' }
const programLabel = { explore: 'Explore', challenge: 'Challenge', future: 'Future', joint: 'Joint' }

const programColor = {
  explore:   'bg-green-100 text-green-800 border-green-200',
  challenge: 'bg-red-100 text-red-800 border-red-200',
  future:    'bg-purple-100 text-purple-800 border-purple-200',
  joint:     'bg-blue-100 text-blue-800 border-blue-200',
}

const slugCopyToast = ref(null)

async function copySlug(slug) {
  try {
    await navigator.clipboard.writeText(`${baseUrl.value}/${slug}`)
    slugCopyToast.value = slug
    setTimeout(() => { slugCopyToast.value = null }, 1800)
  } catch {
    // fallback: select text
  }
}

const totalEvents = computed(() => partners.value.reduce((n, p) => n + p.events.length, 0))
const totalSlugs  = computed(() => partners.value.reduce((n, p) => n + p.events.reduce((m, e) => m + e.slugs.length, 0), 0))
const missingSlugEvents = computed(() => partners.value.flatMap(p => p.events.filter(e => !e.has_slugs)))
</script>

<template>
  <div class="space-y-4">

    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold">Slug-Verwaltung</h2>
        <p v-if="!loading" class="text-sm text-gray-500 mt-0.5">
          {{ partners.length }} Regionalpartner · {{ totalEvents }} Events · {{ totalSlugs }} Slugs
          <span v-if="missingSlugEvents.length > 0" class="ml-2 text-amber-600 font-medium">
            · {{ missingSlugEvents.length }} ohne Slugs
          </span>
        </p>
      </div>
      <div class="flex gap-2">
        <button @click="loadOverview" :disabled="loading"
                class="px-3 py-1.5 text-sm border rounded hover:bg-gray-50 disabled:opacity-50">
          ↺ Aktualisieren
        </button>
        <button @click="expandAll" class="px-3 py-1.5 text-sm border rounded hover:bg-gray-50">
          Alle aufklappen
        </button>
        <button @click="collapseAll" class="px-3 py-1.5 text-sm border rounded hover:bg-gray-50">
          Alle einklappen
        </button>
        <button @click="syncAll" :disabled="syncing || loading"
                class="px-3 py-1.5 text-sm bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50 font-medium">
          {{ syncing ? 'Synchronisiert…' : '⟳ Alle synchronisieren' }}
        </button>
      </div>
    </div>

    <!-- Missing slugs warning -->
    <div v-if="missingSlugEvents.length > 0 && !loading"
         class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800">
      <strong>{{ missingSlugEvents.length }} Events ohne Slugs:</strong>
      {{ missingSlugEvents.map(e => e.name).join(', ') }}
      – Klicke auf „Alle synchronisieren" um sie zu generieren.
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16 text-gray-400">
      <svg class="animate-spin h-6 w-6 mr-2" fill="none" viewBox="0 0 24 24">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
      </svg>
      Lade Slug-Übersicht…
    </div>

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700">
      {{ error }}
    </div>

    <!-- Partner cards -->
    <div v-else class="space-y-2">
      <div v-for="partner in partners" :key="partner.id"
           class="border rounded-lg overflow-hidden">

        <!-- Partner header row -->
        <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 cursor-pointer select-none"
             @click="togglePartner(partner.id)">

          <!-- Expand toggle -->
          <span class="text-gray-400 text-xs w-4 flex-shrink-0">
            {{ expandedIds.has(partner.id) ? '▼' : '▶' }}
          </span>

          <!-- Partner name -->
          <span class="font-semibold text-gray-900 min-w-0 flex-1">{{ partner.name }}</span>

          <!-- Slug prefixes (display or edit form) -->
          <div class="flex items-center gap-2 flex-shrink-0" @click.stop>
            <template v-if="editingPrefixes === partner.id">
              <div class="flex items-center gap-2">
                <div>
                  <label class="text-xs text-gray-500 block">Lang</label>
                  <input v-model="prefixForm.slug_long"
                         class="border rounded px-2 py-0.5 text-sm w-32 font-mono"
                         placeholder="z.B. muenchen" />
                </div>
                <div>
                  <label class="text-xs text-gray-500 block">Kurz (KFZ)</label>
                  <input v-model="prefixForm.slug_short"
                         class="border rounded px-2 py-0.5 text-sm w-20 font-mono"
                         placeholder="z.B. m" />
                </div>
                <div class="mt-4 flex gap-1">
                  <button @click="savePrefixes(partner)" :disabled="savingPrefixes"
                          class="px-2 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">
                    {{ savingPrefixes ? '…' : 'Speichern' }}
                  </button>
                  <button @click="cancelEditPrefixes"
                          class="px-2 py-1 text-xs border rounded hover:bg-gray-100">
                    Abbrechen
                  </button>
                </div>
              </div>
              <p v-if="prefixSaveError" class="text-xs text-red-600 mt-1">{{ prefixSaveError }}</p>
            </template>

            <template v-else>
              <div class="flex items-center gap-1.5 text-sm">
                <span v-if="partner.slug_long"
                      class="px-2 py-0.5 bg-gray-200 text-gray-700 rounded font-mono text-xs">
                  {{ partner.slug_long }}
                </span>
                <span v-else class="text-xs text-gray-400 italic">kein Präfix</span>
                <span v-if="partner.slug_short"
                      class="px-2 py-0.5 bg-gray-300 text-gray-800 rounded font-mono text-xs font-bold">
                  {{ partner.slug_short }}
                </span>
              </div>
              <button @click="startEditPrefixes(partner)"
                      class="px-2 py-1 text-xs border rounded hover:bg-white text-gray-600">
                Präfixe bearbeiten
              </button>
            </template>
          </div>

          <!-- Event count badge -->
          <span class="text-xs text-gray-500 flex-shrink-0">
            {{ partner.events.length }} Event{{ partner.events.length !== 1 ? 's' : '' }}
          </span>
        </div>

        <!-- Events table (expanded) -->
        <div v-if="expandedIds.has(partner.id)" class="divide-y">
          <div v-for="event in partner.events" :key="event.id"
               class="px-4 py-3">

            <!-- Event header -->
            <div class="flex items-center gap-3 mb-2">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="font-medium text-gray-900 text-sm">{{ event.name }}</span>
                  <span class="text-xs text-gray-400">{{ event.date }}</span>
                  <span class="text-xs px-1.5 py-0.5 bg-gray-100 rounded text-gray-600">
                    {{ event.level_name }}
                  </span>

                  <!-- Program badges -->
                  <span v-if="event.is_joint"
                        class="text-xs px-1.5 py-0.5 bg-blue-100 text-blue-700 rounded border border-blue-200">
                    Joint
                  </span>
                  <span v-for="prog in event.programs" :key="prog"
                        class="text-xs px-1.5 py-0.5 rounded border"
                        :class="programColor[prog]">
                    {{ programLabel[prog] }}
                  </span>
                  <span v-if="event.programs.length === 0"
                        class="text-xs text-amber-600">⚠ kein Programm gesetzt</span>
                </div>
              </div>

              <!-- Regenerate button -->
              <button @click="regenerateEvent(event)"
                      :disabled="regeneratingEvent === event.id"
                      class="flex-shrink-0 px-2.5 py-1 text-xs border rounded hover:bg-gray-50 disabled:opacity-50 text-gray-600">
                {{ regeneratingEvent === event.id ? '…' : '↺ Neu generieren' }}
              </button>
            </div>

            <!-- No slugs hint -->
            <div v-if="!event.has_slugs" class="text-xs text-amber-600 pl-1">
              Noch keine Slugs generiert. Klicke „Neu generieren".
            </div>

            <!-- Slugs grid -->
            <div v-else class="flex flex-wrap gap-1.5">
              <div v-for="slug in event.slugs" :key="slug.id"
                   class="group relative flex items-center gap-1 px-2 py-1 rounded border text-xs"
                   :class="[
                     programColor[slug.program],
                     slug.is_primary ? 'ring-2 ring-offset-1 ring-blue-400' : ''
                   ]">

                <!-- Edit mode -->
                <template v-if="editingSlugId === slug.id">
                  <input v-model="slugEditValue"
                         @keyup.enter="saveSlug(event, slug)"
                         @keyup.escape="cancelEditSlug"
                         class="border-b border-current bg-transparent font-mono w-32 outline-none"
                         autofocus />
                  <button @click="saveSlug(event, slug)" :disabled="savingSlug"
                          class="font-bold hover:opacity-70 disabled:opacity-40">✓</button>
                  <button @click="cancelEditSlug"
                          class="hover:opacity-70">✕</button>
                  <p v-if="slugSaveError && editingSlugId === slug.id"
                     class="absolute -bottom-5 left-0 text-red-600 whitespace-nowrap text-xs z-10 bg-white border border-red-200 rounded px-1">
                    {{ slugSaveError }}
                  </p>
                </template>

                <!-- Display mode -->
                <template v-else>
                  <span v-if="slug.is_primary" title="Primärer Slug (QR-Code)" class="opacity-60">★</span>
                  <span class="font-mono">{{ slug.slug }}</span>
                  <span class="opacity-50 text-xs">{{ variantLabel[slug.variant] }}</span>

                  <!-- Hover actions -->
                  <span class="hidden group-hover:flex items-center gap-0.5 ml-1">
                    <button @click="startEditSlug(slug)"
                            title="Slug bearbeiten"
                            class="hover:opacity-70 px-0.5">✎</button>
                    <button @click="copySlug(slug.slug)"
                            title="URL kopieren"
                            class="hover:opacity-70 px-0.5">
                      {{ slugCopyToast === slug.slug ? '✓' : '⎘' }}
                    </button>
                  </span>
                </template>
              </div>
            </div>

          </div>
        </div>

      </div>

      <p v-if="partners.length === 0" class="text-center text-gray-400 py-12">
        Keine Events für die aktuelle Saison gefunden.
      </p>
    </div>

  </div>
</template>
