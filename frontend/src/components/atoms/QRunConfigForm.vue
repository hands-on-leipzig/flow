<script setup>
import { computed } from 'vue'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import { getProgramTheme } from '@/utils/programTheme'

const FIRST_PROGRAM = {
  CHALLENGE: 3,
  FUTURE_8: 8,
}

const PROGRAM_OPTIONS = [
  { id: FIRST_PROGRAM.CHALLENGE, key: 'challenge' },
  { id: FIRST_PROGRAM.FUTURE_8, key: 'future8' },
]

const props = defineProps({
  firstProgram: Number,
  minTeams: Number,
  maxTeams: Number,
  juryLanes: Object,
  tables: Object,
  juryRounds: Object,
  qrunName: String,
  qrunComment: String,
  isValid: Boolean,
  robotCheck: Object,
})

const emit = defineEmits([
  'update:firstProgram',
  'update:minTeams',
  'update:maxTeams',
  'update:juryLanes',
  'update:tables',
  'update:juryRounds',
  'update:robotCheck',
  'update:qrunName',
  'update:qrunComment',
  'start',
  'refresh',
])

const isFuture8 = computed(() => props.firstProgram === FIRST_PROGRAM.FUTURE_8)
const tablesLabel = computed(() => (isFuture8.value ? 'RG-Felder' : 'RG-Tische'))

function themeFor(key) {
  return getProgramTheme(key)
}

function selectProgram(id) {
  emit('update:firstProgram', id)
}
</script>

<template>
  <div class="sticky top-0 bg-white border-b p-4 z-10">
    <div class="flex flex-wrap items-end gap-6">
      <!-- Program (Ablauf / ProgramSection identity) -->
      <div>
        <label class="block font-semibold mb-1">Programm</label>
        <div class="flex items-center gap-2">
          <button
            v-for="option in PROGRAM_OPTIONS"
            :key="option.id"
            type="button"
            class="qrun-program-choice"
            :class="{ 'qrun-program-choice--active': firstProgram === option.id }"
            :style="{ '--program-accent': themeFor(option.key).accent }"
            :title="themeFor(option.key).shortName"
            @click="selectProgram(option.id)"
          >
            <img
              v-if="themeFor(option.key).catalogName"
              :src="programLogoSrc(themeFor(option.key).catalogName)"
              :alt="programLogoAlt(themeFor(option.key).catalogName)"
              class="qrun-program-choice__logo"
            />
            <span class="qrun-program-choice__label">{{ themeFor(option.key).shortName }}</span>
          </button>
        </div>
      </div>

      <!-- Name -->
      <div>
        <label class="block font-semibold mb-1">Name für den QRun</label>
        <input
          type="text"
          class="border rounded px-2 py-1 w-64"
          :value="qrunName"
          @input="emit('update:qrunName', $event.target.value)"
          placeholder="z. B. letzter Test für heute"
        />
      </div>

      <!-- Team Range -->
      <div>
        <label class="block font-semibold mb-1">Teams (min–max)</label>
        <div class="flex gap-2">
          <input
            type="number"
            min="4"
            max="25"
            class="border rounded px-2 py-1 w-20"
            :value="minTeams"
            @input="emit('update:minTeams', Number($event.target.value))"
          />
          <span class="self-center">–</span>
          <input
            type="number"
            min="4"
            max="25"
            class="border rounded px-2 py-1 w-20"
            :value="maxTeams"
            @input="emit('update:maxTeams', Number($event.target.value))"
          />
        </div>
      </div>

      <!-- Jury Lanes -->
      <div>
        <label class="block font-semibold mb-1">Jury-Spuren</label>
        <div class="flex flex-wrap gap-2">
          <label
            v-for="i in 5"
            :key="'lane_' + i"
            class="flex items-center gap-1"
          >
            <input
              type="checkbox"
              :checked="juryLanes['lane_' + i]"
              @change="emit('update:juryLanes', {
                ...juryLanes,
                ['lane_' + i]: $event.target.checked
              })"
            />
            {{ i }}
          </label>
        </div>
      </div>

      <!-- Tables / Fields -->
      <div>
        <label class="block font-semibold mb-1">{{ tablesLabel }}</label>
        <div class="flex gap-4">
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="tables.tables_2"
              @change="emit('update:tables', { ...tables, tables_2: $event.target.checked })"
            />
            2
          </label>
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="tables.tables_4"
              @change="emit('update:tables', { ...tables, tables_4: $event.target.checked })"
            />
            4
          </label>
        </div>
      </div>

      <!-- Jury Runden -->
      <div>
        <label class="block font-semibold mb-1">Jury-Runden</label>
        <div class="flex gap-4">
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_3"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_3: $event.target.checked })"
            />
            3
          </label>
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_4"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_4: $event.target.checked })"
            />
            4
          </label>
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_5"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_5: $event.target.checked })"
            />
            5
          </label>
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_6"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_6: $event.target.checked })"
            />
            6
          </label>
        </div>
      </div>

      <!-- Robot-Check (Challenge only) -->
      <div v-if="!isFuture8">
        <label class="block font-semibold mb-1">Robot-Check</label>
        <div class="flex gap-4">
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="robotCheck.rc_off"
              @change="emit('update:robotCheck', { ...robotCheck, rc_off: $event.target.checked })"
            />
            ❌ Aus
          </label>
          <label class="flex items-center gap-1">
            <input
              type="checkbox"
              :checked="robotCheck.rc_on"
              @change="emit('update:robotCheck', { ...robotCheck, rc_on: $event.target.checked })"
            />
            ✅ An
          </label>
        </div>
      </div>

      <!-- Kommentar -->
      <div class="w-full">
        <label class="block font-semibold mb-1">Kommentar (optional)</label>
        <textarea
          rows="2"
          class="border rounded px-2 py-1 w-full"
          :value="qrunComment"
          @input="emit('update:qrunComment', $event.target.value)"
          placeholder="Notizen zum QRun …"
        />
      </div>

      <!-- Buttons: Start + Refresh -->
      <div class="flex items-center gap-3">
        <button
          class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded disabled:opacity-40"
          :disabled="!isValid"
          @click="emit('start')"
        >
          ▶️ Start
        </button>

        <button
          class="bg-gray-200 hover:bg-gray-300 text-[var(--color-text)] font-semibold px-3 py-2 rounded"
          title="Liste neu laden"
          @click="emit('refresh')"
        >
          🔄 Aktualisieren
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.qrun-program-choice {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.35rem 0.65rem 0.35rem 0.4rem;
  border-radius: 0.5rem;
  border: 1px solid var(--color-border);
  background: #fff;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.qrun-program-choice:hover {
  border-color: color-mix(in srgb, var(--program-accent) 40%, var(--color-border));
}

.qrun-program-choice--active {
  border-color: color-mix(in srgb, var(--program-accent) 55%, var(--color-border));
  background: color-mix(in srgb, var(--program-accent) 10%, #fff);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--program-accent) 16%, transparent);
}

.qrun-program-choice:not(.qrun-program-choice--active) {
  opacity: 0.72;
}

.qrun-program-choice__logo {
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  object-fit: contain;
}

.qrun-program-choice__label {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text);
  letter-spacing: -0.02em;
}

.qrun-program-choice--active .qrun-program-choice__label {
  color: color-mix(in srgb, var(--program-accent) 72%, #111);
}
</style>
