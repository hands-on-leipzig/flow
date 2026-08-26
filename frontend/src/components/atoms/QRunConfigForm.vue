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
  <div class="qrun-config">
    <div class="qrun-config__fields">
      <div class="qrun-config__field qrun-config__field--break">
        <label class="qrun-config__label">Programm</label>
        <div class="flex items-center gap-2">
          <button
            v-for="option in PROGRAM_OPTIONS"
            :key="option.id"
            type="button"
            class="qrun-program-choice liquid-surface-inner"
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

      <div class="qrun-config__field">
        <label class="qrun-config__label">Name für den QRun</label>
        <input
          type="text"
          class="glass-input liquid-surface-control !px-3 !py-2 w-64 max-w-full"
          :value="qrunName"
          @input="emit('update:qrunName', $event.target.value)"
          placeholder="z. B. letzter Test für heute"
        />
      </div>

      <div class="qrun-config__field">
        <label class="qrun-config__label">Teams (min–max)</label>
        <div class="flex gap-2 items-center">
          <input
            type="number"
            min="4"
            max="25"
            class="glass-input liquid-surface-control !px-3 !py-2 w-20"
            :value="minTeams"
            @input="emit('update:minTeams', Number($event.target.value))"
          />
          <span class="text-[var(--color-text-muted)]">–</span>
          <input
            type="number"
            min="4"
            max="25"
            class="glass-input liquid-surface-control !px-3 !py-2 w-20"
            :value="maxTeams"
            @input="emit('update:maxTeams', Number($event.target.value))"
          />
        </div>
      </div>

      <div class="qrun-config__field">
        <label class="qrun-config__label">Jury-Spuren</label>
        <div class="qrun-config__checks">
          <label
            v-for="i in 5"
            :key="'lane_' + i"
            class="qrun-config__check"
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

      <div class="qrun-config__field">
        <label class="qrun-config__label">{{ tablesLabel }}</label>
        <div class="qrun-config__checks">
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="tables.tables_2"
              @change="emit('update:tables', { ...tables, tables_2: $event.target.checked })"
            />
            2
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="tables.tables_4"
              @change="emit('update:tables', { ...tables, tables_4: $event.target.checked })"
            />
            4
          </label>
        </div>
      </div>

      <div class="qrun-config__field">
        <label class="qrun-config__label">Jury-Runden</label>
        <div class="qrun-config__checks">
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_3"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_3: $event.target.checked })"
            />
            3
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_4"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_4: $event.target.checked })"
            />
            4
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_5"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_5: $event.target.checked })"
            />
            5
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="juryRounds.rounds_6"
              @change="emit('update:juryRounds', { ...juryRounds, rounds_6: $event.target.checked })"
            />
            6
          </label>
        </div>
      </div>

      <div v-if="!isFuture8" class="qrun-config__field">
        <label class="qrun-config__label">Robot-Check</label>
        <div class="qrun-config__checks">
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="robotCheck.rc_off"
              @change="emit('update:robotCheck', { ...robotCheck, rc_off: $event.target.checked })"
            />
            Aus
          </label>
          <label class="qrun-config__check">
            <input
              type="checkbox"
              :checked="robotCheck.rc_on"
              @change="emit('update:robotCheck', { ...robotCheck, rc_on: $event.target.checked })"
            />
            An
          </label>
        </div>
      </div>

      <div class="qrun-config__field qrun-config__field--wide">
        <label class="qrun-config__label">Kommentar (optional)</label>
        <textarea
          rows="2"
          class="glass-input liquid-surface-control !px-3 !py-2 w-full"
          :value="qrunComment"
          @input="emit('update:qrunComment', $event.target.value)"
          placeholder="Notizen zum QRun …"
        />
      </div>

      <div class="qrun-config__actions">
        <button
          type="button"
          class="glass-btn-accent !px-5 !py-2.5 !text-sm inline-flex items-center gap-2 disabled:opacity-40"
          :disabled="!isValid"
          @click="emit('start')"
        >
          <i class="bi bi-play-fill" aria-hidden="true"/>
          Start
        </button>

        <button
          type="button"
          class="glass-btn-secondary !px-4 !py-2.5 !text-sm inline-flex items-center gap-2"
          title="Liste neu laden"
          @click="emit('refresh')"
        >
          <i class="bi bi-arrow-clockwise" aria-hidden="true"/>
          Aktualisieren
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.qrun-config__fields {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 1.25rem 1.5rem;
}

.qrun-config__field {
  min-width: 0;
}

.qrun-config__field--wide {
  width: 100%;
}

.qrun-config__field--break {
  flex: 1 0 100%;
}

.qrun-config__label {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 0.8125rem;
  font-weight: 650;
  color: var(--color-text-muted);
}

.qrun-config__checks {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.qrun-config__check {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.65rem;
  border-radius: var(--radius);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 82%, var(--liquid-tile-bg-inner));
  font-size: 0.875rem;
  color: var(--color-text);
  cursor: pointer;
}

.qrun-config__actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.qrun-program-choice {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.35rem 0.65rem 0.35rem 0.4rem;
  border-radius: var(--radius);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, var(--liquid-border-soft));
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.qrun-program-choice:hover {
  border-color: color-mix(in srgb, var(--program-accent) 40%, var(--color-border));
}

.qrun-program-choice--active {
  border-color: color-mix(in srgb, var(--program-accent) 55%, var(--color-border));
  background: color-mix(in srgb, var(--program-accent) 12%, #fff);
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
