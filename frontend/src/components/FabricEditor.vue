<script setup lang="ts">
import {ActiveSelection, Canvas, FabricImage, FabricObject, Gradient, Group, Point, Rect, Textbox, util} from 'fabric'
import {AligningGuidelines} from 'fabric-aligning-guidelines'
import {computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, useSlots} from 'vue';
import {Popover, PopoverButton, PopoverPanel} from '@headlessui/vue';
import axios from "axios";
import {Slide} from "@/models/slide";
import {imageUrl} from '@/utils/images'
import {rewriteImageSrcs} from '@/utils/sameOriginSrc'
import {coverSlideBackground} from '@/utils/coverSlideBackground'
import {programDisplayName} from '@/utils/eventPrograms'
import {seasonLogoPlacement, type SeasonLogoPlacement, SEASON_LOGO_HEIGHT, SEASON_LOGO_WIDTH} from '@/models/seasonLogo'
import {useEventStore} from "@/stores/event";
import {showGlassToast} from '@/composables/useGlassToast';
import EditorInspector from '@/components/slideEditor/EditorInspector.vue';
import EditorLayers from '@/components/slideEditor/EditorLayers.vue';
import ImagePickerModal from '@/components/slideEditor/ImagePickerModal.vue';
import {useHistory} from '@/components/slideEditor/useHistory';
import {createShape, createText, SHAPES, TEXT_PRESETS, type ShapeId, type TextPresetId} from '@/components/slideEditor/objectFactory';
import {
  ACCENT,
  BACKGROUND_GRADIENTS,
  createGradient,
  matchGradient,
  SERIALIZED_PROPS,
  SLIDE_HEIGHT,
  SLIDE_WIDTH,
} from '@/components/slideEditor/constants';
import {
  applyPatch,
  emptySelection,
  layerIcon,
  layerLabel,
  readSelection,
  type LayerItem,
  type SelectionState,
} from '@/components/slideEditor/selection';
import '@/components/slideEditor/editor.css';

const props = withDefaults(defineProps<{
  slide: Slide
  defaultPanel?: 'design' | 'layers' | 'settings'
}>(), {
  defaultPanel: 'design',
});

const slots = useSlots();
const hasSettings = computed(() => !!slots.settings);

const emit = defineEmits<{
  change: []
}>();

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const qrWifiUrl = computed(() => {
  return event.value?.wifi_qrcode ? `data:image/png;base64,${event.value.wifi_qrcode}` : '';
});

const CONTROL_STYLE = {
  transparentCorners: false,
  cornerColor: '#ffffff',
  cornerStrokeColor: ACCENT,
  borderColor: ACCENT,
  cornerSize: 10,
  cornerStyle: 'circle' as const,
  borderScaleFactor: 1.5,
};
const CLIPBOARD_KEY = 'flow-slide-clipboard';
const SNAPPING_KEY = 'flow-slide-snapping';
const ZOOM_STEPS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2];

const stageEl = shallowRef<HTMLDivElement | null>(null);
const canvasEl = shallowRef<HTMLCanvasElement | null>(null);
let canvas: Canvas;
let guidelines: AligningGuidelines | null = null;
let resizeObserver: ResizeObserver | null = null;
let disposed = false;
// True while the canvas is rebuilt from JSON; suppresses history/save handling.
let suspended = false;

const history = useHistory();
const {canUndo, canRedo} = history;

const selection = reactive<SelectionState>(emptySelection());
const activeIds = ref<number[]>([]);
const layers = ref<LayerItem[]>([]);
const slideBackground = reactive({color: '#ffffff', gradientId: null as string | null, hasImage: false});
const coveredLogo = ref<SeasonLogoPlacement | null>(null);
const panelTab = ref<'design' | 'layers' | 'settings'>(
    props.defaultPanel === 'settings' && !hasSettings.value ? 'design' : props.defaultPanel,
);
const snapping = ref(localStorage.getItem(SNAPPING_KEY) !== 'off');
const zoomMode = ref<'fit' | number>('fit');
const zoom = ref(1);

// ---------------------------------------------------------------------------
// Images / QR codes
// ---------------------------------------------------------------------------

function standardImages() {
  return [
    {title: 'Hands on Technology', url: imageUrl('flow/hot.png')},
    {title: 'Hands on Technology', url: imageUrl('flow/hot_outline.png')},
    {title: 'Bioglow', url: imageUrl('flow/season_bioglow+fll_h.png')},
    {title: 'Bioglow', url: imageUrl('flow/season_bioglow_v.png')},
    {title: 'Bioglow', url: imageUrl('flow/season_bioglow_wordmark.png')},
    {title: 'FIRST LEGO League', url: imageUrl('flow/first+fll_h.png')},
    {title: 'FIRST LEGO League', url: imageUrl('flow/first+fll_v.png')},
    {title: 'FIRST', url: imageUrl('flow/first_h.png')},
    {title: 'FIRST', url: imageUrl('flow/first_v.png')},
    {title: programDisplayName('CHALLENGE'), url: imageUrl('flow/fll_challenge_h.png')},
    {title: programDisplayName('CHALLENGE'), url: imageUrl('flow/fll_challenge_hs.png')},
    {title: programDisplayName('CHALLENGE'), url: imageUrl('flow/fll_challenge_v.png')},
    {title: programDisplayName('EXPLORE'), url: imageUrl('flow/fll_explore_h.png')},
    {title: programDisplayName('EXPLORE'), url: imageUrl('flow/fll_explore_hs.png')},
    {title: programDisplayName('EXPLORE'), url: imageUrl('flow/fll_explore_v.png')},
    {title: programDisplayName('FUTURE_8'), url: imageUrl('flow/fll_future8_h.png')},
    {title: programDisplayName('FUTURE_8'), url: imageUrl('flow/fll_future8_hs.png')},
    {title: programDisplayName('FUTURE_8'), url: imageUrl('flow/fll_future8_v.png')},
  ]
}

const availableImages = ref(standardImages());
const availableQrCodes = ref<{ title: string, content: string }[]>([]);
const imagePicker = ref<null | 'images' | 'qr'>(null);
const uploading = ref(false);

async function loadImages() {
  try {
    const {data} = await axios.get('/logos');
    availableImages.value = [...data, ...standardImages()];
  } catch (e) {
    console.error('Fehler beim Laden der Logos:', e);
  }
}

async function loadQRCodeImages() {
  try {
    const publishData = await axios.get(`/publish/link/${event.value.id}`)
    const qr = publishData.data?.qrcode ?? null;

    const codes = qr ? [{title: 'Zeitplan', content: qr}] : [];
    if (qrWifiUrl.value) {
      codes.push({title: 'WiFi', content: qrWifiUrl.value});
    }
    availableQrCodes.value = codes;
  } catch (e) {
    console.error('Fehler beim Laden von Publish-Daten:', e);
    showGlassToast('QR-Codes konnten nicht geladen werden.', 'error');
  }
}

async function openQrPicker() {
  await loadQRCodeImages();
  if (availableQrCodes.value.length > 1) {
    imagePicker.value = 'qr';
  } else if (availableQrCodes.value.length === 1) {
    await insertImage(availableQrCodes.value[0].content);
  } else {
    showGlassToast('Für diese Veranstaltung ist noch kein QR-Code verfügbar.', 'info');
  }
}

async function onPickImage(img: { url?: string, content?: string }) {
  imagePicker.value = null;
  const src = img.content || img.url;
  if (src) await insertImage(src);
}

async function insertImage(src: string) {
  try {
    const img = await FabricImage.fromURL(src);
    const maxWidth = SLIDE_WIDTH * 0.5;
    const maxHeight = SLIDE_HEIGHT * 0.5;
    if (img.width > maxWidth || img.height > maxHeight) {
      img.scale(Math.min(maxWidth / img.width, maxHeight / img.height));
    }
    addObject(img);
  } catch (e) {
    console.error('Bild konnte nicht geladen werden:', e);
    showGlassToast('Bild konnte nicht geladen werden.', 'error');
  }
}

async function uploadImage(file: File) {
  const partner = event.value?.regional_partner;
  if (!partner) {
    showGlassToast('Bitte wähle zuerst ein Event aus, bevor du ein Bild hochlädst.', 'info');
    return;
  }
  if (!file.type.startsWith('image/') && !/\.svg$/i.test(file.name)) {
    showGlassToast('Datei muss ein Bild sein.', 'error');
    return;
  }
  if (file.size > 2 * 1024 * 1024) {
    showGlassToast('Datei ist zu groß. Maximum: 2 MB', 'error');
    return;
  }

  uploading.value = true;
  try {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('regional_partner', String(partner));
    const {data} = await axios.post('/logos', formData);
    imagePicker.value = null;
    loadImages();
    if (data?.url) await insertImage(data.url);
  } catch (e) {
    console.error('Upload fehlgeschlagen:', e);
    showGlassToast('Upload fehlgeschlagen.', 'error');
  } finally {
    uploading.value = false;
  }
}

function onStageDrop(e: DragEvent) {
  const file = e.dataTransfer?.files?.[0];
  if (file) uploadImage(file);
}

// ---------------------------------------------------------------------------
// Canvas lifecycle
// ---------------------------------------------------------------------------

onMounted(async () => {
  canvas = new Canvas(canvasEl.value!, {
    width: SLIDE_WIDTH,
    height: SLIDE_HEIGHT,
    backgroundColor: '#ffffff',
    preserveObjectStacking: true,
    selectionColor: 'rgba(255, 122, 0, 0.08)',
    selectionBorderColor: ACCENT,
    selectionLineWidth: 1,
  });

  registerCanvasEvents();
  enableGuidelines(snapping.value);

  resizeObserver = new ResizeObserver(() => applyZoom());
  resizeObserver.observe(stageEl.value!);
  applyZoom();

  window.addEventListener('keydown', onKeyDown);
  loadImages();

  await loadFonts();
  if (!disposed) await paintSlide();
});

onBeforeUnmount(() => {
  disposed = true;
  window.removeEventListener('keydown', onKeyDown);
  resizeObserver?.disconnect();
  if (commitTimer) commit();
  guidelines?.dispose();
  canvas?.dispose();
});

async function loadFonts() {
  try {
    await Promise.all(['400 32px Uniform', '700 32px Uniform'].map(f => document.fonts.load(f)));
  } catch (e) {
    console.error('Font loading failed', e);
  }
}

function parseBackground(background: unknown) {
  if (typeof background !== 'string') return background;
  try {
    return JSON.parse(background);
  } catch {
    return background;
  }
}

async function paintSlide() {
  const background = props.slide?.content?.background;
  if (background) {
    suspended = true;
    try {
      await canvas.loadFromJSON(rewriteImageSrcs(parseBackground(background)));
    } catch (e) {
      console.error('Folie konnte nicht geladen werden:', e);
    } finally {
      suspended = false;
    }
  }
  if (disposed) return;
  afterCanvasLoaded();
  history.reset(serialize());
}

/** Editor-only setup after the canvas was (re)built from JSON. */
function afterCanvasLoaded() {
  canvas.getObjects().forEach(prepareObject);
  coveredLogo.value = coverSlideBackground(canvas, props.slide?.content);
  syncBackground();
  refreshLayers();
  refreshSelection();
  canvas.requestRenderAll();
}

function registerCanvasEvents() {
  canvas.on('object:added', ({target}) => {
    if (suspended) return;
    prepareObject(target);
    onContentChanged();
  });
  canvas.on('object:removed', () => onContentChanged());
  canvas.on('object:modified', ({target}) => {
    if (target) normalizeTextScale(target);
    onContentChanged(0);
  });
  canvas.on('object:moving', refreshSelectionSoon);
  canvas.on('object:scaling', refreshSelectionSoon);
  canvas.on('object:rotating', refreshSelectionSoon);
  canvas.on('object:resizing', refreshSelectionSoon);
  canvas.on('selection:created', onSelectionChanged);
  canvas.on('selection:updated', onSelectionChanged);
  canvas.on('selection:cleared', onSelectionChanged);
  canvas.on('text:changed', () => onContentChanged(500));
  canvas.on('text:selection:changed', refreshSelection);
  canvas.on('text:editing:exited', () => onContentChanged(0));
}

function prepareObject(obj: FabricObject) {
  obj.set(CONTROL_STYLE);
  setLocked(obj, !!(obj as any).locked);
}

/** Locked objects can't be clicked on the slide; they stay reachable through the layer list. */
function setLocked(obj: FabricObject, locked: boolean) {
  obj.set({
    locked,
    selectable: !locked,
    hasControls: !locked,
    lockMovementX: locked,
    lockMovementY: locked,
    lockScalingX: locked,
    lockScalingY: locked,
    lockRotation: locked,
  } as any);
  if (obj instanceof Textbox) obj.set('editable', !locked);
}

/** Corner-scaling a textbox changes its font size instead of stretching the glyphs. */
function normalizeTextScale(obj: FabricObject) {
  if (!(obj instanceof Textbox) || (obj.scaleX === 1 && obj.scaleY === 1)) return;
  obj.set({
    fontSize: Math.round(obj.fontSize * obj.scaleY * 10) / 10,
    width: obj.width * obj.scaleX,
    scaleX: 1,
    scaleY: 1,
  });
  obj.initDimensions();
  obj.setCoords();
}

function enableGuidelines(enabled: boolean) {
  guidelines?.dispose();
  guidelines = null;
  if (!enabled) return;

  // Detached frame so objects also snap to the slide edges and centre lines.
  const frame = new Rect({left: SLIDE_WIDTH / 2, top: SLIDE_HEIGHT / 2, width: SLIDE_WIDTH, height: SLIDE_HEIGHT, strokeWidth: 0});
  frame.setCoords();

  guidelines = new AligningGuidelines(canvas, {
    margin: 6,
    width: 1,
    color: ACCENT,
    getObjectsByTarget(target) {
      const exclude = target instanceof ActiveSelection ? target.getObjects() : [target];
      const set = new Set<FabricObject>([frame]);
      canvas.getObjects().forEach(o => {
        if (o.visible && !exclude.includes(o)) set.add(o);
      });
      return set;
    },
  });
}

function toggleSnapping() {
  snapping.value = !snapping.value;
  localStorage.setItem(SNAPPING_KEY, snapping.value ? 'on' : 'off');
  enableGuidelines(snapping.value);
}

// ---------------------------------------------------------------------------
// Zoom
// ---------------------------------------------------------------------------

const zoomLabel = computed(() => `${Math.round(zoom.value * 100)} %`);

const seasonLogoStyle = computed(() => {
  if (!props.slide?.content?.showSeasonLogo) return null;
  const place = coveredLogo.value ?? seasonLogoPlacement(props.slide.content.background);
  const z = zoom.value;
  return {
    left: `${place.centerX * z}px`,
    top: `${place.top * z}px`,
    width: `${SEASON_LOGO_WIDTH * place.scaleX * z}px`,
    height: `${SEASON_LOGO_HEIGHT * place.scaleY * z}px`,
  };
});

function fitZoom() {
  const el = stageEl.value;
  if (!el) return 1;
  const padding = 48;
  return Math.max(0.1, Math.min((el.clientWidth - padding) / SLIDE_WIDTH, (el.clientHeight - padding) / SLIDE_HEIGHT));
}

function applyZoom() {
  if (!canvas || disposed) return;
  const z = zoomMode.value === 'fit' ? fitZoom() : zoomMode.value;
  zoom.value = z;
  canvas.setDimensions({width: Math.round(SLIDE_WIDTH * z), height: Math.round(SLIDE_HEIGHT * z)});
  canvas.setZoom(z);
  canvas.calcOffset();
  canvas.requestRenderAll();
}

function zoomIn() {
  zoomMode.value = ZOOM_STEPS.find(s => s > zoom.value + 0.01) ?? ZOOM_STEPS[ZOOM_STEPS.length - 1];
  applyZoom();
}

function zoomOut() {
  zoomMode.value = [...ZOOM_STEPS].reverse().find(s => s < zoom.value - 0.01) ?? ZOOM_STEPS[0];
  applyZoom();
}

function zoomFit() {
  zoomMode.value = 'fit';
  applyZoom();
}

function onStageWheel(e: WheelEvent) {
  if (!e.ctrlKey && !e.metaKey) return;
  e.preventDefault();
  e.deltaY < 0 ? zoomIn() : zoomOut();
}

// ---------------------------------------------------------------------------
// State sync, history & saving
// ---------------------------------------------------------------------------

const objectIds = new WeakMap<FabricObject, number>();
let nextObjectId = 1;

function idOf(obj: FabricObject) {
  let id = objectIds.get(obj);
  if (!id) {
    id = nextObjectId++;
    objectIds.set(obj, id);
  }
  return id;
}

function objectById(id: number) {
  return canvas.getObjects().find(o => idOf(o) === id);
}

let rafId = 0;

function refreshSelectionSoon() {
  if (rafId) return;
  rafId = requestAnimationFrame(() => {
    rafId = 0;
    refreshSelection();
  });
}

function refreshSelection() {
  if (!canvas || disposed) return;
  Object.assign(selection, readSelection(canvas.getActiveObject()));
  activeIds.value = canvas.getActiveObjects().map(idOf);
}

function onSelectionChanged() {
  const active = canvas.getActiveObject();
  if (active instanceof ActiveSelection) active.set(CONTROL_STYLE);
  if (active && panelTab.value === 'settings') panelTab.value = 'design';
  refreshSelection();
}

function refreshLayers() {
  layers.value = canvas.getObjects().slice().reverse().map(obj => ({
    id: idOf(obj),
    label: layerLabel(obj),
    icon: layerIcon(obj),
    visible: obj.visible !== false,
    locked: !!(obj as any).locked,
  }));
}

function syncBackground() {
  const bg = canvas.backgroundColor as unknown;
  if (bg instanceof Gradient) {
    slideBackground.gradientId = matchGradient(bg as Gradient<'linear'>) ?? 'custom';
  } else {
    slideBackground.gradientId = null;
    slideBackground.color = typeof bg === 'string' && bg ? bg : '#ffffff';
  }
  slideBackground.hasImage = canvas.backgroundImage instanceof FabricImage;
}

function serialize() {
  return JSON.stringify(rewriteImageSrcs(canvas.toObject(SERIALIZED_PROPS)));
}

let commitTimer: ReturnType<typeof setTimeout> | null = null;

function scheduleCommit(delay = 250) {
  if (commitTimer) clearTimeout(commitTimer);
  commitTimer = setTimeout(commit, delay);
}

function commit() {
  if (commitTimer) {
    clearTimeout(commitTimer);
    commitTimer = null;
  }
  if (!canvas) return;
  const state = serialize();
  if (history.push(state)) persist(state);
}

function persist(state: string) {
  if (props.slide) {
    props.slide.content.background = state;
    emit('change');
  }
}

function onContentChanged(delay = 250) {
  if (suspended || disposed) return;
  refreshLayers();
  refreshSelection();
  scheduleCommit(delay);
}

function afterEdit(delay = 0) {
  canvas.requestRenderAll();
  onContentChanged(delay);
}

let restoring = false;

async function restore(state: string | null) {
  if (!state || restoring) return;
  restoring = true;
  suspended = true;
  try {
    canvas.discardActiveObject();
    await canvas.loadFromJSON(state);
  } finally {
    suspended = false;
    restoring = false;
  }
  if (disposed) return;
  afterCanvasLoaded();
  persist(state);
}

function undo() {
  if (commitTimer) commit();
  restore(history.undo());
}

function redo() {
  restore(history.redo());
}

// ---------------------------------------------------------------------------
// Editing operations
// ---------------------------------------------------------------------------

/** Centres new objects and cascades them so repeated inserts don't stack exactly. */
function addObject(obj: FabricObject) {
  const center = new Point(SLIDE_WIDTH / 2, SLIDE_HEIGHT / 2);
  const occupied = canvas.getObjects().filter(o => o.getCenterPoint().distanceFrom(center) < 4).length;
  obj.setPositionByOrigin(center.add(new Point(occupied * 24, occupied * 24)), 'center', 'center');
  canvas.add(obj);
  canvas.setActiveObject(obj);
  canvas.requestRenderAll();
  panelTab.value = 'design';
}

function addText(preset: TextPresetId) {
  addObject(createText(preset));
}

function addShape(id: ShapeId) {
  addObject(createShape(id));
}

function selectObjects(objects: FabricObject[]) {
  canvas.discardActiveObject();
  if (objects.length === 1) {
    canvas.setActiveObject(objects[0]);
  } else if (objects.length > 1) {
    canvas.setActiveObject(new ActiveSelection(objects, {canvas, ...CONTROL_STYLE}));
  }
  canvas.requestRenderAll();
  refreshSelection();
}

function selectAll() {
  selectObjects(canvas.getObjects().filter(o => o.visible && !(o as any).locked));
}

function removeSelection() {
  const objects = canvas.getActiveObjects().filter(o => !(o as any).locked);
  if (!objects.length) return;
  canvas.discardActiveObject();
  canvas.remove(...objects);
  canvas.requestRenderAll();
}

function copy() {
  const objects = canvas.getActiveObjects();
  if (!objects.length) return;
  // Objects inside an ActiveSelection carry coordinates relative to it; discard to serialize absolute ones.
  const wasMulti = canvas.getActiveObject() instanceof ActiveSelection;
  if (wasMulti) canvas.discardActiveObject();
  const data = objects.map(o => o.toObject(SERIALIZED_PROPS));
  if (wasMulti) selectObjects(objects);
  localStorage.setItem(CLIPBOARD_KEY, JSON.stringify(data));
}

function cut() {
  copy();
  removeSelection();
}

function addCopies(objects: FabricObject[]) {
  objects.forEach(o => {
    o.set({left: o.left + 20, top: o.top + 20, locked: false} as any);
    canvas.add(o);
  });
  selectObjects(objects);
}

async function paste() {
  const raw = localStorage.getItem(CLIPBOARD_KEY);
  if (!raw) return;
  try {
    addCopies(await util.enlivenObjects<FabricObject>(JSON.parse(raw)));
  } catch (e) {
    console.error('Einfügen fehlgeschlagen:', e);
  }
}

async function duplicate() {
  const objects = canvas.getActiveObjects();
  if (!objects.length) return;
  canvas.discardActiveObject();
  addCopies(await Promise.all(objects.map(o => o.clone(SERIALIZED_PROPS))));
}

function nudge(dx: number, dy: number) {
  const obj = canvas.getActiveObject();
  if (!obj || (obj as any).locked) return;
  obj.set({left: obj.left + dx, top: obj.top + dy});
  obj.setCoords();
  afterEdit(400);
}

function moveBy(obj: FabricObject, dx: number, dy: number) {
  obj.set({left: obj.left + dx, top: obj.top + dy});
  obj.setCoords();
}

type Area = { left: number, top: number, right: number, bottom: number };

function alignInArea(obj: FabricObject, mode: string, area: Area) {
  const b = obj.getBoundingRect();
  switch (mode) {
    case 'align-left':
      return moveBy(obj, area.left - b.left, 0);
    case 'align-hcenter':
      return moveBy(obj, (area.left + area.right) / 2 - (b.left + b.width / 2), 0);
    case 'align-right':
      return moveBy(obj, area.right - (b.left + b.width), 0);
    case 'align-top':
      return moveBy(obj, 0, area.top - b.top);
    case 'align-vcenter':
      return moveBy(obj, 0, (area.top + area.bottom) / 2 - (b.top + b.height / 2));
    case 'align-bottom':
      return moveBy(obj, 0, area.bottom - (b.top + b.height));
  }
}

function align(mode: string) {
  const active = canvas.getActiveObject();
  if (!active) return;
  if (active instanceof ActiveSelection) {
    const objects = active.getObjects();
    canvas.discardActiveObject();
    const boxes = objects.map(o => o.getBoundingRect());
    const area = {
      left: Math.min(...boxes.map(b => b.left)),
      top: Math.min(...boxes.map(b => b.top)),
      right: Math.max(...boxes.map(b => b.left + b.width)),
      bottom: Math.max(...boxes.map(b => b.top + b.height)),
    };
    objects.forEach(o => alignInArea(o, mode, area));
    selectObjects(objects);
  } else {
    alignInArea(active, mode, {left: 0, top: 0, right: SLIDE_WIDTH, bottom: SLIDE_HEIGHT});
  }
  afterEdit();
}

function distribute(axis: 'h' | 'v') {
  const active = canvas.getActiveObject();
  if (!(active instanceof ActiveSelection) || active.size() < 3) return;
  const objects = active.getObjects();
  canvas.discardActiveObject();

  const horizontal = axis === 'h';
  const items = objects
      .map(o => ({o, b: o.getBoundingRect()}))
      .sort((a, b) => horizontal ? a.b.left - b.b.left : a.b.top - b.b.top);
  const start = horizontal ? items[0].b.left : items[0].b.top;
  const last = items[items.length - 1].b;
  const end = horizontal ? last.left + last.width : last.top + last.height;
  const sizes = items.reduce((sum, it) => sum + (horizontal ? it.b.width : it.b.height), 0);
  const gap = (end - start - sizes) / (items.length - 1);

  let cursor = start;
  items.forEach(({o, b}) => {
    const delta = cursor - (horizontal ? b.left : b.top);
    moveBy(o, horizontal ? delta : 0, horizontal ? 0 : delta);
    cursor += (horizontal ? b.width : b.height) + gap;
  });
  selectObjects(objects);
  afterEdit();
}

function reorder(mode: 'front' | 'forward' | 'backward' | 'back') {
  const all = canvas.getObjects();
  const objects = [...canvas.getActiveObjects()].sort((a, b) => all.indexOf(a) - all.indexOf(b));
  if (!objects.length) return;
  switch (mode) {
    case 'front':
      objects.forEach(o => canvas.bringObjectToFront(o));
      break;
    case 'back':
      [...objects].reverse().forEach(o => canvas.sendObjectToBack(o));
      break;
    case 'forward':
      [...objects].reverse().forEach(o => canvas.bringObjectForward(o));
      break;
    case 'backward':
      objects.forEach(o => canvas.sendObjectBackwards(o));
      break;
  }
  afterEdit();
}

function group() {
  const active = canvas.getActiveObject();
  if (!(active instanceof ActiveSelection)) return;
  const all = canvas.getObjects();
  const objects = [...active.getObjects()].sort((a, b) => all.indexOf(a) - all.indexOf(b));
  const index = all.indexOf(objects[objects.length - 1]) - (objects.length - 1);
  canvas.discardActiveObject();
  canvas.remove(...objects);
  const grouped = new Group(objects);
  canvas.insertAt(index, grouped);
  canvas.setActiveObject(grouped);
  afterEdit();
}

function ungroup() {
  const active = canvas.getActiveObject();
  if (!(active instanceof Group) || active instanceof ActiveSelection) return;
  const index = canvas.getObjects().indexOf(active);
  canvas.discardActiveObject();
  const objects = active.removeAll();
  canvas.remove(active);
  canvas.insertAt(index, ...objects);
  selectObjects(objects);
  afterEdit();
}

function toggleLock(obj: FabricObject | undefined = canvas.getActiveObject()) {
  if (!obj || obj instanceof ActiveSelection) return;
  setLocked(obj, !(obj as any).locked);
  afterEdit();
}

/** The canvas background image is shown full-screen on the displays (see FabricSlideContentRenderer). */
function useAsBackground() {
  const img = canvas.getActiveObject();
  if (!(img instanceof FabricImage)) return;
  canvas.discardActiveObject();
  canvas.remove(img);
  img.set({angle: 0, flipX: false, flipY: false, opacity: 1, shadow: null});
  canvas.set('backgroundImage', img);
  coveredLogo.value = coverSlideBackground(canvas, props.slide?.content);
  syncBackground();
  afterEdit();
  showGlassToast('Bild ist jetzt der Folienhintergrund und füllt auf den Displays den ganzen Bildschirm.', 'info');
}

function removeBackgroundImage() {
  canvas.set('backgroundImage', undefined);
  coveredLogo.value = null;
  syncBackground();
  afterEdit();
}

function setBackground(value: { type: 'color', color: string } | { type: 'gradient', id: string }) {
  if (value.type === 'color') {
    canvas.set('backgroundColor', value.color);
  } else {
    const preset = BACKGROUND_GRADIENTS.find(g => g.id === value.id);
    if (preset) canvas.set('backgroundColor', createGradient(preset));
  }
  syncBackground();
  afterEdit(150);
}

function onInspectorUpdate(patch: Partial<SelectionState>) {
  const obj = canvas.getActiveObject();
  if (!obj) return;
  applyPatch(obj, patch);
  afterEdit(300);
}

function onInspectorAction(name: string) {
  if (name.startsWith('align-')) return align(name);
  switch (name) {
    case 'distribute-h':
      return distribute('h');
    case 'distribute-v':
      return distribute('v');
    case 'front':
    case 'forward':
    case 'backward':
    case 'back':
      return reorder(name);
    case 'duplicate':
      return duplicate();
    case 'delete':
      return removeSelection();
    case 'lock':
      return toggleLock();
    case 'group':
      return group();
    case 'ungroup':
      return ungroup();
    case 'as-background':
      return useAsBackground();
    case 'remove-background-image':
      return removeBackgroundImage();
  }
}

// ---------------------------------------------------------------------------
// Layer panel
// ---------------------------------------------------------------------------

function onLayerSelect(id: number, additive: boolean) {
  const obj = objectById(id);
  if (!obj) return;
  if (additive) {
    const current = canvas.getActiveObjects();
    selectObjects(current.includes(obj) ? current.filter(o => o !== obj) : [...current, obj]);
  } else {
    selectObjects([obj]);
  }
}

function onLayerToggleVisible(id: number) {
  const obj = objectById(id);
  if (!obj) return;
  obj.set('visible', !obj.visible);
  if (!obj.visible && canvas.getActiveObjects().includes(obj)) canvas.discardActiveObject();
  afterEdit();
}

function onLayerToggleLock(id: number) {
  const obj = objectById(id);
  if (obj) toggleLock(obj);
}

function onLayerReorder(id: number, displayIndex: number) {
  const obj = objectById(id);
  if (!obj) return;
  canvas.moveObjectTo(obj, canvas.getObjects().length - 1 - displayIndex);
  afterEdit();
}

function onLayerRename(id: number, name: string) {
  const obj = objectById(id);
  if (!obj) return;
  obj.set('name', name || undefined);
  afterEdit();
}

// ---------------------------------------------------------------------------
// Keyboard
// ---------------------------------------------------------------------------

function onKeyDown(e: KeyboardEvent) {
  if (!canvas || imagePicker.value) return;

  // Don't interfere with typing in form fields (includes fabric's hidden textarea while editing text)
  const target = e.target as HTMLElement | null;
  if (target && (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable)) return;

  const mod = e.ctrlKey || e.metaKey;
  const key = e.key.toLowerCase();

  if (mod && key === 'z') {
    e.preventDefault();
    e.shiftKey ? redo() : undo();
    return;
  }
  if (mod && key === 'y') {
    e.preventDefault();
    redo();
    return;
  }
  if (mod && key === 'v') {
    e.preventDefault();
    paste();
    return;
  }
  if (mod && key === 'a') {
    e.preventDefault();
    selectAll();
    return;
  }

  if (!canvas.getActiveObject()) return;

  if (mod && key === 'c') {
    e.preventDefault();
    copy();
  } else if (mod && key === 'x') {
    e.preventDefault();
    cut();
  } else if (mod && key === 'd') {
    e.preventDefault();
    duplicate();
  } else if (mod && key === 'g') {
    e.preventDefault();
    e.shiftKey ? ungroup() : group();
  } else if (e.key === 'Delete' || e.key === 'Backspace') {
    e.preventDefault();
    removeSelection();
  } else if (e.key === 'Escape') {
    canvas.discardActiveObject();
    canvas.requestRenderAll();
  } else if (e.key.startsWith('Arrow')) {
    e.preventDefault();
    const step = e.shiftKey ? 10 : 1;
    const dx = e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0;
    const dy = e.key === 'ArrowUp' ? -step : e.key === 'ArrowDown' ? step : 0;
    nudge(dx, dy);
  }
}

function onStageMouseDown() {
  canvas.discardActiveObject();
  canvas.requestRenderAll();
}
</script>

<template>
  <div class="slide-editor">
    <!-- Werkzeugleiste -->
    <div class="se-toolbar">
      <div class="se-toolbar__group">
        <Popover class="relative">
          <PopoverButton class="se-tool" title="Text einfügen">
            <i class="bi bi-fonts"></i><span>Text</span><i class="bi bi-chevron-down se-tool__caret"></i>
          </PopoverButton>
          <PopoverPanel v-slot="{ close }" class="se-menu">
            <button v-for="preset in TEXT_PRESETS" :key="preset.id" type="button" class="se-menu__text"
                    :style="{ fontSize: `${Math.min(26, 10 + preset.fontSize / 3)}px`, fontWeight: preset.bold ? 700 : 400 }"
                    @click="addText(preset.id); close()">
              {{ preset.label }}
            </button>
          </PopoverPanel>
        </Popover>

        <Popover class="relative">
          <PopoverButton class="se-tool" title="Form einfügen">
            <i class="bi bi-square"></i><span>Formen</span><i class="bi bi-chevron-down se-tool__caret"></i>
          </PopoverButton>
          <PopoverPanel v-slot="{ close }" class="se-menu se-menu--grid">
            <button v-for="shape in SHAPES" :key="shape.id" type="button" class="se-menu__shape" :title="shape.label"
                    @click="addShape(shape.id); close()">
              <i class="bi" :class="shape.icon"></i>
              <span>{{ shape.label }}</span>
            </button>
          </PopoverPanel>
        </Popover>

        <button type="button" class="se-tool" title="Logo oder Bild einfügen" @click="imagePicker = 'images'">
          <i class="bi bi-image"></i><span>Bild</span>
        </button>
        <button type="button" class="se-tool" title="QR-Code einfügen" @click="openQrPicker">
          <i class="bi bi-qr-code"></i><span>QR-Code</span>
        </button>
      </div>

      <div class="se-toolbar__group">
        <button type="button" class="se-icon-btn" :disabled="!canUndo" title="Rückgängig (Strg+Z)" @click="undo">
          <i class="bi bi-arrow-counterclockwise"></i>
        </button>
        <button type="button" class="se-icon-btn" :disabled="!canRedo" title="Wiederholen (Strg+Umschalt+Z)"
                @click="redo">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
      </div>

      <div class="se-toolbar__group ml-auto">
        <button type="button" class="se-icon-btn" title="Verkleinern" @click="zoomOut">
          <i class="bi bi-zoom-out"></i>
        </button>
        <button type="button" class="se-zoom-label" title="An Fenster anpassen" @click="zoomFit">{{ zoomLabel }}</button>
        <button type="button" class="se-icon-btn" title="Vergrößern" @click="zoomIn">
          <i class="bi bi-zoom-in"></i>
        </button>
        <button type="button" class="se-icon-btn" :class="{ 'is-active': zoomMode === 'fit' }" title="An Fenster anpassen"
                @click="zoomFit">
          <i class="bi bi-aspect-ratio"></i>
        </button>
      </div>
    </div>

    <div class="se-body">
      <!-- Bühne -->
      <div ref="stageEl" class="se-stage" @wheel="onStageWheel" @dragover.prevent @drop.prevent="onStageDrop"
           @mousedown.self="onStageMouseDown">
        <div class="se-stage__slide">
          <canvas ref="canvasEl"></canvas>
          <div v-if="props.slide?.type === 'TeamsMapSlideContent'" class="se-map-guide"
               title="Hier wird die Karte mit den Teams angezeigt"></div>
          <img v-if="seasonLogoStyle" class="se-season-logo" src="/logo.png" alt="" :style="seasonLogoStyle"/>
        </div>
      </div>

      <!-- Seitenleiste -->
      <aside class="se-panel">
        <div class="se-panel__tabs" role="tablist">
          <button v-if="hasSettings" type="button" role="tab" :aria-selected="panelTab === 'settings'"
                  :class="{ 'is-active': panelTab === 'settings' }" @click="panelTab = 'settings'">
            <i class="bi bi-gear"></i> Einstellungen
          </button>
          <button type="button" role="tab" :aria-selected="panelTab === 'design'"
                  :class="{ 'is-active': panelTab === 'design' }" @click="panelTab = 'design'">
            <i class="bi bi-sliders"></i> Gestalten
          </button>
          <button type="button" role="tab" :aria-selected="panelTab === 'layers'"
                  :class="{ 'is-active': panelTab === 'layers' }" @click="panelTab = 'layers'">
            <i class="bi bi-layers"></i> Ebenen
            <span v-if="layers.length" class="se-panel__count">{{ layers.length }}</span>
          </button>
        </div>
        <div class="se-panel__body">
          <div v-if="panelTab === 'settings'">
            <slot name="settings"></slot>
          </div>
          <EditorInspector v-else-if="panelTab === 'design'"
                           :selection="selection"
                           :background="slideBackground"
                           :snapping="snapping"
                           @update="onInspectorUpdate"
                           @action="onInspectorAction"
                           @background="setBackground"
                           @toggle-snapping="toggleSnapping"/>
          <EditorLayers v-else
                        :layers="layers"
                        :active-ids="activeIds"
                        @select="onLayerSelect"
                        @toggle-visible="onLayerToggleVisible"
                        @toggle-lock="onLayerToggleLock"
                        @reorder="onLayerReorder"
                        @rename="onLayerRename"/>
        </div>
      </aside>
    </div>

    <ImagePickerModal v-if="imagePicker === 'images'"
                      title="Logo oder Bild einfügen"
                      :images="availableImages"
                      allow-upload
                      :uploading="uploading"
                      @pick="onPickImage"
                      @upload="uploadImage"
                      @close="imagePicker = null"/>
    <ImagePickerModal v-else-if="imagePicker === 'qr'"
                      title="QR-Code einfügen"
                      :images="availableQrCodes"
                      @pick="onPickImage"
                      @close="imagePicker = null"/>
  </div>
</template>

<style scoped>
.slide-editor {
  display: flex;
  flex: 1 1 auto;
  flex-direction: column;
  min-height: 0;
  border-top: 1px solid var(--color-border-strong);
}

.se-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 1rem;
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid var(--color-border-strong);
  background: var(--se-panel-bg);
}

.se-toolbar__group {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.se-toolbar__group + .se-toolbar__group:not(.ml-auto) {
  padding-left: 1rem;
  border-left: 1px solid var(--color-border-strong);
}

.se-zoom-label {
  min-width: 3.6rem;
  height: 2rem;
  padding: 0 0.4rem;
  border-radius: 8px;
  font-size: 0.8rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-text);
}

.se-zoom-label:hover {
  background: var(--color-bg-hover);
}

/* Fills the remaining page height; the page itself must not scroll. */
.se-body {
  display: grid;
  flex: 1 1 auto;
  grid-template-columns: minmax(0, 1fr) auto;
  min-height: 420px;
}

.se-stage {
  display: flex;
  overflow: auto;
  background-color: var(--se-stage-bg);
  background-image: radial-gradient(var(--se-stage-dot) 1px, transparent 1px);
  background-size: 18px 18px;
}

.se-map-guide {
  position: absolute;
  z-index: 15;
  left: 10%;
  top: 10%;
  width: 80%;
  height: 80%;
  box-sizing: border-box;
  border: 8px solid #fff;
  box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.45);
  pointer-events: none;
}

.se-season-logo {
  position: absolute;
  z-index: 20;
  max-width: none;
  transform: translateX(-50%);
  pointer-events: none;
}

.se-stage__slide {
  position: relative;
  overflow: hidden;
  margin: auto;
  line-height: 0;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.12), 0 12px 32px rgba(15, 23, 42, 0.14);
}

.se-panel {
  display: flex;
  flex-direction: column;
  width: var(--se-panel-width);
  min-width: min-content;
  min-height: 0;
  border-left: 1px solid var(--color-border-strong);
  background: var(--se-panel-bg);
}

.se-panel__tabs {
  display: flex;
  gap: 0.25rem;
  padding: 0.5rem;
  border-bottom: 1px solid var(--color-border-strong);
}

.se-panel__tabs button {
  flex: 1 1 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  height: 2rem;
  padding: 0 0.55rem;
  border-radius: 8px;
  font-size: 0.8rem;
  font-weight: 500;
  white-space: nowrap;
  color: var(--color-text-muted);
}

.se-panel__tabs button:hover {
  background: var(--color-bg-hover);
}

.se-panel__tabs button.is-active {
  background: var(--color-accent-soft);
  color: var(--color-accent);
}

.se-panel__count {
  min-width: 1.2rem;
  padding: 0 0.3rem;
  border-radius: 999px;
  font-size: 0.68rem;
  line-height: 1.2rem;
  background: var(--color-bg-muted);
  color: var(--color-text-muted);
}

.se-panel__body {
  flex: 1;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
  scrollbar-gutter: stable;
  scrollbar-width: thin;
}

@media (max-width: 1023px) {
  .se-body {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: minmax(300px, 55vh) auto;
    height: auto;
  }

  .se-panel {
    width: auto;
    max-height: 60vh;
    border-left: 0;
    border-top: 1px solid var(--color-border-strong);
  }
}
</style>
