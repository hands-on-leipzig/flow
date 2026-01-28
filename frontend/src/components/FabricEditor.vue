<script setup lang="ts">
import {Canvas, Rect, Textbox, FabricImage, Triangle, Circle, ActiveSelection, util} from 'fabric'
import {onBeforeUnmount, onMounted, reactive, shallowRef, watch, ref, computed} from 'vue';
import SvgIcon from '@jamescoyle/vue-icon';
import {
  mdiFormatText,
  mdiRectangle,
  mdiImageArea,
  mdiQrcodePlus,
  mdiArrangeSendBackward,
  mdiArrangeBringForward
} from '@mdi/js';
import {Slide} from "@/models/slide";
import axios from "axios";
import {imageUrl} from '@/utils/images'
import {useEventStore} from "@/stores/event";

// Ideen und TODOS
// Resize
// Border korrekt, Layouting allgemein
// Undo / Redo
// Custom controls

const DEFAULT_WIDTH = 800;
const DEFAULT_HEIGHT = 450;

const eventStore = useEventStore();
const event = computed(() => eventStore.selectedEvent);

const qrWifiUrl = computed(() => {
  return event.value?.wifi_qrcode ? `data:image/png;base64,${event.value.wifi_qrcode}` : '';
});

const props = defineProps<{
  slide: Slide
}>();

const emit = defineEmits<{
  change: []
}>();

watch(props.slide, (newSlide) => {
  paintSlide(newSlide);
});

const canvasEl = shallowRef(null);
let canvas: Canvas;

const standardImages = [
  {title: 'Hands on Technology', url: imageUrl('flow/hot.png')},
  {title: 'Hands on Technology', url: imageUrl('flow/hot_outline.png')},
  {title: 'Unearthed', url: imageUrl('flow/season_unearthed+fll_h.png')},
  {title: 'Unearthed', url: imageUrl('flow/season_unearthed_v.png')},
  {title: 'Unearthed', url: imageUrl('flow/season_unearthed_wordmark.png')},
  {title: 'FIRST LEGO League', url: imageUrl('flow/first+fll_h.png')},
  {title: 'FIRST LEGO League', url: imageUrl('flow/first+fll_v.png')},
  {title: 'FIRST', url: imageUrl('flow/first_h.png')},
  {title: 'FIRST', url: imageUrl('flow/first_v.png')},
  {title: 'FIRST LEGO League Challenge', url: imageUrl('flow/fll_challenge_h.png')},
  {title: 'FIRST LEGO League Challenge', url: imageUrl('flow/fll_challenge_v.png')},
  {title: 'FIRST LEGO League Explore', url: imageUrl('flow/fll_explore_h.png')},
  {title: 'FIRST LEGO League Explore', url: imageUrl('flow/fll_explore_hs.png')},
  {title: 'FIRST LEGO League Explore', url: imageUrl('flow/fll_explore_v.png')},
];
const availableImages = ref(standardImages);
const availableQrCodes = ref([]);

// Format program name with italic FIRST
const formatProgramTitle = (title?: string) => {
  return title?.replace(/FIRST/g, '<span class="italic">FIRST</span>')
}

const defaultObjectProperties = {
  transparentCorners: true,
  cornerColor: '#4e4d4d',
};

const toolbarState = reactive({
  type: 'none', // 'none', 'text', 'shape', 'image'
  object: undefined,
});

onMounted(() => {
  canvas = new Canvas(canvasEl.value, {
    width: DEFAULT_WIDTH,
    height: DEFAULT_HEIGHT,
    backgroundColor: '#ffffff',
  });
  canvas.preserveObjectStacking = true;

  if (props.slide) {
    paintSlide(props.slide);
  }

  // Toolbar
  canvas.on('selection:created', updateToolbar);
  canvas.on('selection:updated', updateToolbar);
  canvas.on('selection:cleared', updateToolbar);

  // Debounce change events to avoid too frequent updates
  let changeTimeout: NodeJS.Timeout | null = null;
  const emitChange = () => {
    if (changeTimeout) {
      clearTimeout(changeTimeout);
    }
    changeTimeout = setTimeout(() => {
      const json = JSON.stringify(canvas.toJSON());
      if (props.slide) {
        props.slide.content.background = json;
        emit('change');
      }
    }, 300); // Small delay to batch rapid changes
  };

  // Emit change events for debounced auto-save in parent
  canvas.on('object:modified', emitChange);
  canvas.on('object:added', emitChange);
  canvas.on('object:removed', emitChange);

  // Löschen
  window.addEventListener('keydown', keyListener);
});

onMounted(loadFont);
onMounted(loadImages);
onBeforeUnmount(() => {
  // Save immediately on unmount - parent component will handle it
  const json = JSON.stringify(canvas.toJSON());
  if (props.slide) {
    props.slide.content.background = json;
    emit('change');
  }
  window.removeEventListener('keydown', keyListener);
});

function keyListener(e: KeyboardEvent) {

  // Don't interfere with typing in form fields
  const target = e.target;
  const isInput =
      target.tagName === "INPUT" ||
      target.tagName === "TEXTAREA" ||
      target.isContentEditable;

  if (isInput) return;

  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v') {
    if (isEditingText()) {
      return;
    }
    e.preventDefault();
    paste();
    return;
  }

  const activeObj = canvas.getActiveObject();
  if (!activeObj) return;

  if (isEditingText()) {
    return;
  }

  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
    e.preventDefault();
    copy();
    return;
  }

  if ((e.key === 'Delete' || e.key === 'Backspace') && canvas?.getActiveObject()) {
    e.preventDefault();

    if (activeObj.get('type') === 'activeselection') {
      activeObj.forEachObject(canvas.remove.bind(canvas));
      canvas.discardActiveObject();
    } else {
      canvas.remove(activeObj);
    }
    updateToolbar();
    canvas.requestRenderAll();
    return;
  }
}

function isEditingText() {
  const activeObj = canvas.getActiveObject();
  return activeObj?.get('type') === 'textbox' && activeObj.isEditing;
}

async function loadImages() {
  const {data} = await axios.get('/logos');
  availableImages.value = [...data, ...standardImages];
}

async function loadQRCodeImages() {
  // Load both QR codes (public plan and WiFi)
  try {
    const publishData = await axios.get(`/publish/link/${event.value.id}`)
    const qr = publishData.data?.qrcode ?? null;

    const codes = [{title: 'Zeitplan', content: qr}];
    if (qrWifiUrl.value) {
      codes.push({title: 'WiFi', content: qrWifiUrl.value});
    }
    availableQrCodes.value = codes;
  } catch (e) {
    console.error('Fehler beim Laden von Publish-Daten:', e);
    return;
  }
}

function paintSlide(slide: Slide) {
  if (!canvas || !slide || !slide.content.background) return;
  canvas.clear();
  canvas.loadFromJSON(slide.content.background).then(() => {
    applyDefaultControls();
    canvas.requestRenderAll();
  });
}

function applyDefaultControls() {
  canvas.getObjects().forEach(obj => {
    obj.set(defaultObjectProperties);
  });
}

function addRect() {
  if (!canvas) return;
  const rect = new Rect({
    left: 100,
    top: 100,
    fill: '#add8e6',
    width: 100,
    height: 100,
    ...defaultObjectProperties
  });
  canvas.add(rect);
  canvas.requestRenderAll();
}

const showImageModal = ref(false);
const imageModalContent = ref([]);

function openImageModal(images) {
  showImageModal.value = true;
  imageModalContent.value = images;
}

function closeImageModal() {
  showImageModal.value = false;
  imageModalContent.value = [];
}

async function insertImageFromUrl(url) {
  closeImageModal();
  if (!canvas) return;
  const img = await FabricImage.fromURL(url);
  insertImage(img);
}

function insertImage(img) {
  img.set({left: 100, top: 100, ...defaultObjectProperties});

  const maxWidth = canvas.width * 0.5;
  const maxHeight = canvas.height * 0.5;

  if (img.width > maxWidth || img.height > maxHeight) {
    const scale = Math.min(maxWidth / img.width, maxHeight / img.height);
    img.scale(scale);
  }

  canvas.add(img);
  canvas.setActiveObject(img);
  canvas.requestRenderAll();
}

function prepareImageModal() {
  openImageModal(availableImages.value);
}

async function prepareQrCodeModal() {
  await loadQRCodeImages();
  if (availableQrCodes.value?.length > 1) {
    openImageModal(availableQrCodes.value);
  } else if (availableQrCodes.value?.length == 1) {
    await addQRCode(availableQrCodes.value[0].content);
  }
}

async function addQRCode(qr) {
  if (!canvas) return;

  const image = await FabricImage.fromObject({src: qr});
  insertImage(image);
}

function addText() {
  if (!canvasEl.value) return;
  const text = new Textbox("FLOW", {
    left: 100,
    top: 100,
    fontFamily: 'Uniform',
    fontSize: 24,
    fill: '#000000',
    width: 200,
    editable: true,
    ...defaultObjectProperties
  });
  canvas.add(text);
  canvas.setActiveObject(text);
  canvas.requestRenderAll();
}

function loadFont() {
  const font = new FontFace('Uniform', 'url(/fonts/Uniform-Regular.otf)');
  font.load().then(() => {
    if (canvas) {
      canvas.requestRenderAll();
    }
  }).catch((e) => {
    console.error('Font loading failed', e);
  });
}

function updateToolbar() {
  const activeObject = canvas.getActiveObject();
  // Update toolbar based on selection
  if (activeObject) {
    // Example: Enable/disable buttons based on selection properties
    const type = activeObject.get('type');
    if (type === 'textbox') {
      toolbarState.type = 'text';
    } else if (type === 'rect' || type === 'circle' || type === 'triangle') {
      toolbarState.type = 'shape';
    } else {
      toolbarState.type = 'none';
    }
    toolbarState.object = activeObject;
  } else {
    toolbarState.type = 'none';
    toolbarState.object = undefined;
  }
}

function makeBold() {
  if (toolbarState.object.fontWeight === 'bold') {
    toolbarState.object.set({fontWeight: 'normal'});
  } else {
    toolbarState.object.set({fontWeight: "bold"});
  }
  canvas.requestRenderAll();
}

function makeItalic() {
  if (toolbarState.object.fontStyle === 'italic') {
    toolbarState.object.set({fontStyle: 'normal'});
  } else {
    toolbarState.object.set({fontStyle: "italic"});
  }
  canvas.requestRenderAll();
}

function makeUnderline() {
  toolbarState.object.set({underline: !toolbarState.object.underline});
  canvas.requestRenderAll();
}

function onFillChange(color) {
  toolbarState.object.set({fill: color});
  canvas.requestRenderAll();
}

function onShapeTypeChange(type: string) {
  const obj = toolbarState.object;
  if (!obj) return;
  const props = {
    left: obj.left,
    top: obj.top,
    fill: obj.fill,
    stroke: obj.stroke,
    strokeWidth: obj.strokeWidth,
    scaleX: obj.scaleX,
    scaleY: obj.scaleY,
    angle: obj.angle,
    ...defaultObjectProperties
  };
  let newObj;
  if (type === 'rect') {
    newObj = new Rect({width: obj.width, height: obj.height, ...props});
  } else if (type === 'circle') {
    newObj = new Circle({radius: Math.min(obj.width, obj.height) / 2, ...props});
  } else if (type === 'triangle') {
    newObj = new Triangle({width: obj.width, height: obj.height, ...props});
  }
  canvas.remove(canvas.getActiveObject());
  canvas.add(newObj);
  canvas.setActiveObject(newObj);
  toolbarState.object = newObj;
  canvas.requestRenderAll();
}

function onStrokeChange(color: string) {
  toolbarState.object.set({stroke: color});
  canvas.requestRenderAll();
}

function onStrokeWidthChange(width: string) {
  toolbarState.object.set({strokeWidth: parseInt(width)});
  canvas.requestRenderAll();
}

function bringToFront() {
  const object = canvas.getActiveObject();
  if (object && canvas) {
    canvas.bringObjectToFront(object);
    canvas.requestRenderAll();
  }
}

function sendToBack() {
  const object = canvas.getActiveObject();
  if (object && canvas) {
    canvas.sendObjectToBack(object);
    canvas.requestRenderAll();
  }
}

function saveJson() {
  const json = JSON.stringify(canvas.toJSON());
  if (props.slide) {
    props.slide.content.background = json;
    const content = JSON.stringify(props.slide.content.toJSON());
    axios.put(`slides/${props.slide.id}`, {
      content: content
    }).then(response => {
      console.log('Slide saved:', response.data);
    }).catch(error => {
      console.error('Error saving slide:', error);
    });
  }
}

async function copy() {
  const activeObjects = canvas.getActiveObjects();
  if (!activeObjects?.length) return;

  const bbox = canvas.getActiveObject().getBoundingRect();
  const data = {
    bbox: {left: bbox.left, top: bbox.top},
    objects: activeObjects.map(obj => obj.toObject()),
  };

  localStorage.setItem("fabric-clipboard", JSON.stringify(data));
}

async function paste() {
  try {
    const json = localStorage.getItem("fabric-clipboard");
    if (!json) return;
    const objectData = JSON.parse(json);
    const objects = await util.enlivenObjects(objectData.objects);
    const multiple = objects.length > 1;
    for (const object of objects) {
      const left = 10 + (multiple ? objectData.bbox?.left : 0) + (object?.left || 0);
      const top = 10 + (multiple ? objectData.bbox?.top : 0) + (object.top || 0);
      object.set({
        left,
        top,
        ...defaultObjectProperties
      });
      canvas.add(object);
    }

    if (objects.length === 1) {
      canvas.setActiveObject(objects[0]);
    } else {
      const sel = new ActiveSelection(objects, {canvas});
      canvas.setActiveObject(sel);
      sel.setCoords();
    }

    canvas.requestRenderAll();

  } catch (e) {
    // do nothing (paste with non valid JSON)
    console.error(e);
  }
}

</script>

<template>
  <div class="inline-block pt-4">
    <div class="flex items-start gap-x-2">
      <button @click="addRect" title="Form einfügen"
              class="px-3 rounded bg-blue-500 hover:bg-blue-600 h-10 w-12 mb-1">
        <svg-icon type="mdi" :path="mdiRectangle"></svg-icon>
      </button>
      <button @click="addText" title="Textfeld einfügen"
              class="px-3 rounded bg-blue-500 hover:bg-blue-600 ml-2 h-10 w-12 mb-1">
        <svg-icon type="mdi" :path="mdiFormatText"></svg-icon>
      </button>
      <button @click="prepareImageModal" title="Logo einfügen"
              class="px-3 rounded bg-blue-500 hover:bg-blue-600 ml-2 h-10 w-12 mb-1">
        <svg-icon type="mdi" :path="mdiImageArea"></svg-icon>
      </button>
      <button @click="prepareQrCodeModal" title="QR-Code einfügen"
              class="px-3 rounded bg-blue-500 hover:bg-blue-600 ml-2 h-10 w-12 mb-1">
        <svg-icon type="mdi" :path="mdiQrcodePlus"></svg-icon>
      </button>
      <!-- Allgemeine Toolbar Vordergrund / Hintergrund -->
      <div v-if="toolbarState.object" class="ml-4 flex items-center gap-x-2">
        <button @click="bringToFront"
                class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 h-10 w-12"
                title="In den Vordergrund">
          <svg-icon type="mdi" :path="mdiArrangeBringForward"></svg-icon>
        </button>
        <button @click="sendToBack"
                class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 h-10 w-12"
                title="In den Hintergrund">
          <svg-icon type="mdi" :path="mdiArrangeSendBackward"></svg-icon>
        </button>
      </div>
      <div v-if="toolbarState.type === 'text'" class="ml-4 mb-1 flex items-center gap-x-2">
        <!-- Text property toolbar -->
        <input type="number" title="Textgröße" v-model.number="toolbarState.object.fontSize" v-on:change="triggerRender"
               class="w-16 pr-1 border border-gray-300 rounded ml-2 h-10"/>
        <button v-on:click="makeBold" title="Fett"
                class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2 font-bold h-10 w-12"
                :class="{ 'bg-gray-400': toolbarState.object.fontWeight === 'bold' }">B
        </button>
        <button v-on:click="makeItalic" title="Kursiv"
                class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2 font-italic h-10 w-12"
                :class="{ 'bg-gray-400': toolbarState.object.fontStyle === 'italic' }">I
        </button>
        <button v-on:click="makeUnderline" title="Unterstreichen"
                class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2 h-10 w-12"
                :class="{ 'bg-gray-400': toolbarState.object.underline }">U
        </button>
        <input type="color" title="Schriftfarbe" class="px-2 rounded ml-2 h-10 w-12" :value="toolbarState.object.fill"
               @input="onFillChange($event.target.value)"/>
      </div>
      <div v-else-if="toolbarState.type === 'shape'" class="ml-4 flex items-start gap-x-2">
        <!-- Shape Toolbar -->
        <select v-model="toolbarState.object.type" title="Form ändern" @change="onShapeTypeChange($event.target.value)"
                class="px-2 rounded ml-2 h-10">
          <option value="rect">Rechteck</option>
          <option value="circle">Kreis</option>
          <option value="triangle">Dreieck</option>
        </select>
        <!-- Fill Color -->
        <input type="color" title="Füllfarbe" class="px-2 rounded ml-2 mb-1 h-10 w-12" :value="toolbarState.object.fill"
               @input="onFillChange($event.target.value)"/>
        <!-- Border Color -->
        <input type="color" title="Randfarbe" class="px-2 rounded ml-2 h-10 w-12" :value="toolbarState.object.stroke"
               @input="onStrokeChange($event.target.value)"/>
        <!-- Border Size -->
        <input type="number" title="Randgröße" min="0" class="w-16 px-1 border border-gray-300 rounded ml-2 h-10"
               :value="toolbarState.object.strokeWidth"
               @input="onStrokeWidthChange($event.target.value)"/>
      </div>
    </div>
    <canvas ref="canvasEl" class="border border-grey rounded"></canvas>

    <!-- Image Auswahl Overlay -->
    <div v-if="showImageModal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
      <div class="bg-white rounded shadow-lg p-6 w-1/3">
        <h2 class="text-lg font-bold mb-4">Bild auswählen</h2>
        <div class="grid grid-cols-3 gap-4 overflow-y-auto max-h-96">
          <div v-for="img in imageModalContent" :key="img" class="cursor-pointer">
            <img v-if="!!img.content" :src="img.content" :alt="img.title"
                 class="w-28 h-28 object-contain rounded border"
                 @click="addQRCode(img.content)"/>
            <img v-else :src="img.url" :alt="img.title"
                 class="w-28 h-28 object-contain rounded border"
                 @click="insertImageFromUrl(img.url)"/>
            <div class="text-xs text-center mt-1 w-28"
                 style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"
                 v-html="formatProgramTitle(img.title)"></div>
          </div>
        </div>
        <div class="mt-6 px-4 py-2 grid grid-cols-2">
          <div>
            <router-link to="/logos" class="block px-2">
              <button class="rounded bg-gray-300 hover:bg-gray-400 w-full py-1">Logos verwalten</button>
            </router-link>
          </div>
          <div>
            <button @click="closeImageModal" class="rounded bg-gray-300 hover:bg-gray-400 w-full py-1 px-2">
              Abbrechen
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<style scoped>

@font-face {
  font-family: 'Uniform';
  src: url('/fonts/Uniform-Regular.otf') format('otf');
  font-weight: normal;
  font-style: normal;
}

@font-face {
  font-family: 'Uniform';
  src: url('/fonts/Uniform-Bold.otf') format('otf');
  font-weight: bold;
  font-style: normal;
}

</style>