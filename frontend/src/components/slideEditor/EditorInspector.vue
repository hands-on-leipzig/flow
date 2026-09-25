<script setup lang="ts">
import {computed, ref} from 'vue';
import ColorField from './ColorField.vue';
import NumberField from './NumberField.vue';
import {BACKGROUND_GRADIENTS, FONT_FAMILIES} from './constants';
import type {SelectionState, StrokeDash} from './selection';

const props = defineProps<{
  selection: SelectionState
  background: { color: string, gradientId: string | null, hasImage: boolean }
  snapping: boolean
}>();

const emit = defineEmits<{
  update: [patch: Partial<SelectionState>]
  action: [name: string]
  background: [value: { type: 'color', color: string } | { type: 'gradient', id: string }]
  'toggle-snapping': []
}>();

const keepRatio = ref(true);

const KIND_LABELS: Record<string, string> = {
  text: 'Text',
  shape: 'Form',
  line: 'Linie',
  image: 'Bild',
  group: 'Gruppe',
  multi: 'Auswahl',
};

const title = computed(() => {
  const s = props.selection;
  return s.kind === 'multi' ? `${s.count} Elemente` : KIND_LABELS[s.kind] ?? '';
});

const isMulti = computed(() => props.selection.kind === 'multi');
const isText = computed(() => props.selection.kind === 'text');
const hasStroke = computed(() => ['shape', 'line', 'text'].includes(props.selection.kind));

function update(patch: Partial<SelectionState>) {
  emit('update', patch);
}

function updateWidth(width: number) {
  const s = props.selection;
  if (keepRatio.value && !isText.value && s.width > 0) {
    update({width, height: Math.round(width * s.height / s.width)});
  } else {
    update({width});
  }
}

function updateHeight(height: number) {
  const s = props.selection;
  if (keepRatio.value && s.height > 0) {
    update({height, width: Math.round(height * s.width / s.height)});
  } else {
    update({height});
  }
}

function stepFontSize(delta: number) {
  update({fontSize: Math.max(4, Math.round(props.selection.fontSize + delta))});
}

const ALIGN_ACTIONS = [
  {id: 'align-left', icon: 'bi-align-start', title: 'Links ausrichten'},
  {id: 'align-hcenter', icon: 'bi-align-center', title: 'Horizontal zentrieren'},
  {id: 'align-right', icon: 'bi-align-end', title: 'Rechts ausrichten'},
  {id: 'align-top', icon: 'bi-align-top', title: 'Oben ausrichten'},
  {id: 'align-vcenter', icon: 'bi-align-middle', title: 'Vertikal zentrieren'},
  {id: 'align-bottom', icon: 'bi-align-bottom', title: 'Unten ausrichten'},
];

const ORDER_ACTIONS = [
  {id: 'front', icon: 'bi-front', title: 'Ganz nach vorne'},
  {id: 'forward', icon: 'bi-layer-forward', title: 'Eine Ebene nach vorne'},
  {id: 'backward', icon: 'bi-layer-backward', title: 'Eine Ebene nach hinten'},
  {id: 'back', icon: 'bi-back', title: 'Ganz nach hinten'},
];

const TEXT_ALIGN = [
  {id: 'left', icon: 'bi-text-left', title: 'Linksbündig'},
  {id: 'center', icon: 'bi-text-center', title: 'Zentriert'},
  {id: 'right', icon: 'bi-text-right', title: 'Rechtsbündig'},
  {id: 'justify', icon: 'bi-justify', title: 'Blocksatz'},
];

const DASH_STYLES: { id: StrokeDash, label: string }[] = [
  {id: 'solid', label: 'Durchgezogen'},
  {id: 'dashed', label: 'Gestrichelt'},
  {id: 'dotted', label: 'Gepunktet'},
];

const SHORTCUTS = [
  ['Strg + Z / Strg + Umschalt + Z', 'Rückgängig / Wiederholen'],
  ['Strg + C / X / V', 'Kopieren / Ausschneiden / Einfügen'],
  ['Strg + D', 'Duplizieren'],
  ['Strg + A', 'Alles auswählen'],
  ['Strg + G / Strg + Umschalt + G', 'Gruppieren / Aufheben'],
  ['Pfeiltasten (+ Umschalt)', 'Verschieben um 1 px (10 px)'],
  ['Entf / Rücktaste', 'Löschen'],
  ['Esc', 'Auswahl aufheben'],
];
</script>

<template>
  <div class="inspector">
    <!-- Folie (keine Auswahl) -->
    <template v-if="selection.kind === 'none'">
      <section class="se-section">
        <h3 class="se-section__title">Folie</h3>
        <div v-if="background.hasImage" class="flex items-center justify-between gap-2">
          <span class="se-label"><i class="bi bi-image"></i> Hintergrundbild</span>
          <button type="button" class="se-btn !w-auto" @click="emit('action', 'remove-background-image')">
            <i class="bi bi-x-lg"></i> Entfernen
          </button>
        </div>
        <p v-if="background.hasImage" class="se-hint">
          Das Hintergrundbild füllt auf den Displays den ganzen Bildschirm. Farbe und Verlauf liegen darunter.
        </p>
        <ColorField label="Hintergrundfarbe"
                    :model-value="background.gradientId ? null : background.color"
                    @update:model-value="emit('background', { type: 'color', color: $event ?? '#ffffff' })"/>
        <div>
          <span class="se-label">Verläufe</span>
          <div class="gradient-grid mt-1.5">
            <button v-for="g in BACKGROUND_GRADIENTS" :key="g.id" type="button" class="gradient-grid__item"
                    :class="{ 'is-active': background.gradientId === g.id }"
                    :style="{ background: `linear-gradient(135deg, ${g.from}, ${g.to})` }"
                    :title="g.label" @click="emit('background', { type: 'gradient', id: g.id })"></button>
          </div>
        </div>
      </section>

      <section class="se-section">
        <h3 class="se-section__title">Arbeitshilfen</h3>
        <label class="flex items-center justify-between gap-2 cursor-pointer">
          <span class="se-label">Hilfslinien &amp; Einrasten</span>
          <input type="checkbox" class="se-switch" :checked="snapping" @change="emit('toggle-snapping')"/>
        </label>
      </section>

      <section class="se-section">
        <details>
          <summary class="se-section__title cursor-pointer select-none">Tastenkürzel</summary>
          <dl class="shortcut-list">
            <template v-for="[keys, label] in SHORTCUTS" :key="keys">
              <dt>{{ label }}</dt>
              <dd>{{ keys }}</dd>
            </template>
          </dl>
        </details>
      </section>

      <p class="se-hint px-4 py-3">
        <i class="bi bi-hand-index"></i>
        Klicke ein Element auf der Folie an, um es zu bearbeiten. Doppelklick auf Text startet die Texteingabe.
      </p>
    </template>

    <template v-else>
      <!-- Kopf mit Schnellaktionen -->
      <div class="inspector__header">
        <span class="font-semibold">{{ title }}</span>
        <div class="flex items-center gap-1">
          <button type="button" class="se-icon-btn" title="Duplizieren (Strg+D)" @click="emit('action', 'duplicate')">
            <i class="bi bi-files"></i>
          </button>
          <button v-if="!isMulti" type="button" class="se-icon-btn" :class="{ 'is-active': selection.locked }"
                  :title="selection.locked ? 'Entsperren' : 'Sperren'" @click="emit('action', 'lock')">
            <i class="bi" :class="selection.locked ? 'bi-lock-fill' : 'bi-unlock'"></i>
          </button>
          <button type="button" class="se-icon-btn se-icon-btn--danger" :disabled="selection.locked"
                  :title="selection.locked ? 'Gesperrte Elemente können nicht gelöscht werden' : 'Löschen (Entf)'"
                  @click="emit('action', 'delete')">
            <i class="bi bi-trash"></i>
          </button>
        </div>
      </div>

      <!-- Text -->
      <section v-if="isText" class="se-section">
        <h3 class="se-section__title">Text</h3>
        <select class="se-select" :value="selection.fontFamily"
                @change="update({ fontFamily: ($event.target as HTMLSelectElement).value })">
          <option v-for="f in FONT_FAMILIES" :key="f.value" :value="f.value" :style="{ fontFamily: f.value }">
            {{ f.label }}
          </option>
        </select>
        <div class="flex items-center gap-1.5">
          <button type="button" class="se-icon-btn" title="Kleiner" @click="stepFontSize(-2)">
            <i class="bi bi-dash-lg"></i>
          </button>
          <NumberField class="flex-1" :model-value="selection.fontSize" :min="4" :max="400" suffix="pt"
                       title="Schriftgröße" @update:model-value="update({ fontSize: $event })"/>
          <button type="button" class="se-icon-btn" title="Größer" @click="stepFontSize(2)">
            <i class="bi bi-plus-lg"></i>
          </button>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="se-segmented">
            <button type="button" :class="{ 'is-active': selection.bold }" title="Fett"
                    @click="update({ bold: !selection.bold })"><i class="bi bi-type-bold"></i></button>
            <button type="button" :class="{ 'is-active': selection.italic }" title="Kursiv"
                    @click="update({ italic: !selection.italic })"><i class="bi bi-type-italic"></i></button>
            <button type="button" :class="{ 'is-active': selection.underline }" title="Unterstrichen"
                    @click="update({ underline: !selection.underline })"><i class="bi bi-type-underline"></i></button>
            <button type="button" :class="{ 'is-active': selection.linethrough }" title="Durchgestrichen"
                    @click="update({ linethrough: !selection.linethrough })"><i class="bi bi-type-strikethrough"></i>
            </button>
          </div>
          <div class="se-segmented">
            <button v-for="a in TEXT_ALIGN" :key="a.id" type="button" :title="a.title"
                    :class="{ 'is-active': selection.textAlign === a.id }" @click="update({ textAlign: a.id })">
              <i class="bi" :class="a.icon"></i>
            </button>
          </div>
        </div>
        <ColorField label="Schriftfarbe" :model-value="selection.fill" @update:model-value="update({ fill: $event ?? '#000000' })"/>
        <ColorField label="Hervorhebung" allow-transparent :model-value="selection.textBackgroundColor"
                    @update:model-value="update({ textBackgroundColor: $event })"/>
        <label class="se-range">
          <span class="se-label">Zeilenabstand</span>
          <input type="range" min="0.7" max="2.5" step="0.05" :value="selection.lineHeight"
                 @input="update({ lineHeight: Number(($event.target as HTMLInputElement).value) })"/>
          <span class="se-range__value">{{ selection.lineHeight.toFixed(2) }}</span>
        </label>
        <label class="se-range">
          <span class="se-label">Zeichenabstand</span>
          <input type="range" min="-100" max="800" step="10" :value="selection.charSpacing"
                 @input="update({ charSpacing: Number(($event.target as HTMLInputElement).value) })"/>
          <span class="se-range__value">{{ selection.charSpacing }}</span>
        </label>
        <p class="se-hint">Tipp: Markiere beim Bearbeiten einzelne Wörter, um nur diese zu formatieren.</p>
      </section>

      <!-- Form -->
      <section v-if="selection.kind === 'shape'" class="se-section">
        <h3 class="se-section__title">Form</h3>
        <ColorField label="Füllung" allow-transparent :model-value="selection.fill"
                    @update:model-value="update({ fill: $event ?? '' })"/>
        <label v-if="selection.hasRadius" class="se-range">
          <span class="se-label">Eckenradius</span>
          <input type="range" min="0" max="120" step="1" :value="selection.radius"
                 @input="update({ radius: Number(($event.target as HTMLInputElement).value) })"/>
          <span class="se-range__value">{{ selection.radius }}</span>
        </label>
      </section>

      <!-- Rahmen / Linie -->
      <section v-if="hasStroke" class="se-section">
        <h3 class="se-section__title">{{ selection.kind === 'line' ? 'Linie' : selection.kind === 'text' ? 'Kontur' : 'Rahmen' }}</h3>
        <ColorField label="Farbe" :allow-transparent="selection.kind !== 'line'"
                    :model-value="selection.strokeWidth > 0 || selection.kind === 'line' ? selection.stroke : null"
                    @update:model-value="$event ? update({ stroke: $event, strokeWidth: selection.strokeWidth || 2 }) : update({ strokeWidth: 0 })"/>
        <div class="flex items-center justify-between gap-2">
          <span class="se-label">Stärke</span>
          <NumberField class="w-24" :model-value="selection.strokeWidth" :min="0" :max="80" :step="selection.kind === 'text' ? 0.5 : 1" suffix="px"
                       @update:model-value="update({ strokeWidth: $event })"/>
        </div>
        <div v-if="selection.kind !== 'text' && selection.strokeWidth > 0" class="se-segmented se-segmented--full">
          <button v-for="d in DASH_STYLES" :key="d.id" type="button" :class="{ 'is-active': selection.strokeDash === d.id }"
                  @click="update({ strokeDash: d.id })">{{ d.label }}
          </button>
        </div>
      </section>

      <!-- Bild -->
      <section v-if="selection.kind === 'image'" class="se-section">
        <h3 class="se-section__title">Bild</h3>
        <button type="button" class="se-btn" @click="emit('action', 'as-background')">
          <i class="bi bi-aspect-ratio"></i> Als Folienhintergrund verwenden
        </button>
      </section>

      <!-- Gruppe / Mehrfachauswahl -->
      <section v-if="selection.kind === 'group' || isMulti" class="se-section">
        <h3 class="se-section__title">{{ isMulti ? 'Mehrere Elemente' : 'Gruppe' }}</h3>
        <button v-if="isMulti" type="button" class="se-btn" @click="emit('action', 'group')">
          <i class="bi bi-collection"></i> Gruppieren
        </button>
        <button v-else type="button" class="se-btn" @click="emit('action', 'ungroup')">
          <i class="bi bi-boxes"></i> Gruppierung aufheben
        </button>
        <div v-if="isMulti" class="grid grid-cols-2 gap-1.5">
          <button type="button" class="se-btn" :disabled="selection.count < 3" title="Mindestens 3 Elemente"
                  @click="emit('action', 'distribute-h')">
            <i class="bi bi-distribute-horizontal"></i> Horizontal verteilen
          </button>
          <button type="button" class="se-btn" :disabled="selection.count < 3" title="Mindestens 3 Elemente"
                  @click="emit('action', 'distribute-v')">
            <i class="bi bi-distribute-vertical"></i> Vertikal verteilen
          </button>
        </div>
      </section>

      <!-- Effekte -->
      <section class="se-section">
        <h3 class="se-section__title">Darstellung</h3>
        <label class="se-range">
          <span class="se-label">Deckkraft</span>
          <input type="range" min="0" max="100" step="1" :value="selection.opacity"
                 @input="update({ opacity: Number(($event.target as HTMLInputElement).value) })"/>
          <span class="se-range__value">{{ selection.opacity }}%</span>
        </label>
        <label v-if="!isMulti" class="flex items-center justify-between gap-2 cursor-pointer">
          <span class="se-label">Schatten</span>
          <input type="checkbox" class="se-switch" :checked="selection.shadow"
                 @change="update({ shadow: !selection.shadow })"/>
        </label>
        <div v-if="!isMulti" class="flex items-center justify-between gap-2">
          <span class="se-label">Spiegeln</span>
          <div class="se-segmented">
            <button type="button" :class="{ 'is-active': selection.flipX }" title="Horizontal spiegeln"
                    @click="update({ flipX: !selection.flipX })"><i class="bi bi-symmetry-vertical"></i></button>
            <button type="button" :class="{ 'is-active': selection.flipY }" title="Vertikal spiegeln"
                    @click="update({ flipY: !selection.flipY })"><i class="bi bi-symmetry-horizontal"></i></button>
          </div>
        </div>
      </section>

      <!-- Anordnen -->
      <section class="se-section">
        <h3 class="se-section__title">Anordnen</h3>
        <span class="se-label">{{ isMulti ? 'Zueinander ausrichten' : 'Auf der Folie ausrichten' }}</span>
        <div class="se-segmented se-segmented--full">
          <button v-for="a in ALIGN_ACTIONS" :key="a.id" type="button" :title="a.title" @click="emit('action', a.id)">
            <i class="bi" :class="a.icon"></i>
          </button>
        </div>
        <span class="se-label">Ebene</span>
        <div class="se-segmented se-segmented--full">
          <button v-for="a in ORDER_ACTIONS" :key="a.id" type="button" :title="a.title" @click="emit('action', a.id)">
            <i class="bi" :class="a.icon"></i>
          </button>
        </div>
      </section>

      <!-- Position & Größe -->
      <section class="se-section">
        <h3 class="se-section__title">Position &amp; Größe</h3>
        <div class="grid grid-cols-2 gap-1.5">
          <NumberField prefix="X" :model-value="selection.x" title="Abstand von links"
                       @update:model-value="update({ x: $event })"/>
          <NumberField prefix="Y" :model-value="selection.y" title="Abstand von oben"
                       @update:model-value="update({ y: $event })"/>
        </div>
        <div class="grid grid-cols-[1fr_auto_1fr] items-center gap-1.5">
          <NumberField prefix="B" :model-value="selection.width" :min="1" title="Breite"
                       @update:model-value="updateWidth"/>
          <button type="button" class="se-icon-btn se-icon-btn--sm" :class="{ 'is-active': keepRatio && !isText }"
                  :disabled="isText" :title="keepRatio ? 'Seitenverhältnis beibehalten' : 'Seitenverhältnis frei'"
                  @click="keepRatio = !keepRatio">
            <i class="bi" :class="keepRatio ? 'bi-link-45deg' : 'bi-link'"></i>
          </button>
          <NumberField prefix="H" :model-value="selection.height" :min="1" :disabled="isText"
                       :title="isText ? 'Die Höhe ergibt sich aus dem Text' : 'Höhe'"
                       @update:model-value="updateHeight"/>
        </div>
        <NumberField prefix="Drehung" suffix="°" :model-value="selection.angle" :min="-360" :max="360"
                     @update:model-value="update({ angle: $event })"/>
      </section>
    </template>
  </div>
</template>

<style scoped>
.inspector {
  display: flex;
  flex-direction: column;
}

.inspector__header {
  position: sticky;
  top: 0;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--color-border-strong);
  background: var(--se-panel-bg);
}

.gradient-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 0.35rem;
}

.gradient-grid__item {
  aspect-ratio: 16 / 10;
  border-radius: 6px;
  box-shadow: inset 0 0 0 1px rgba(15, 23, 42, 0.15);
}

.gradient-grid__item.is-active {
  outline: 2px solid var(--color-accent);
  outline-offset: 1px;
}

.shortcut-list {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0.35rem 0.75rem;
  margin-top: 0.6rem;
  font-size: 0.75rem;
}

.shortcut-list dt {
  color: var(--color-text-muted);
}

.shortcut-list dd {
  text-align: right;
  font-variant-numeric: tabular-nums;
  color: var(--color-text);
}
</style>
