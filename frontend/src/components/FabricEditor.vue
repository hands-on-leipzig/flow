<script setup lang="ts">
import {Canvas, Rect, Textbox, FabricImage, Triangle, Circle} from 'fabric'
import {onBeforeUnmount, onMounted, reactive, shallowRef, watch, ref} from 'vue';
import SvgIcon from '@jamescoyle/vue-icon';
import {mdiFormatText, mdiRectangle, mdiImageArea} from '@mdi/js';
import {Slide} from "@/models/slide";
import axios from "axios";

// Ideen und TODOS
// Resize
// Border korrekt, Layouting allgemein
// Undo / Redo
// Toolbox: Farbe, Font
// Bilder einfügen

// Custom controls
// Copy and Paste
// Auto-Save (kein speichern-button)
// Einfügen-Menü für Biler
// Form-Art wechseln.

const DEFAULT_WIDTH = 800;
const DEFAULT_HEIGHT = 450;

const props = defineProps<{
  slide: Slide
}>();

watch(props.slide, (newSlide) => {
  paintSlide(newSlide);
});

const canvasEl = shallowRef(null);
let canvas: Canvas;

const standardImages = [
  {title: 'Hands on Technology', path: 'flow/hot.png'},
  {title: 'Hands on Technology', path: 'flow/hot_outline.png'},
  {title: 'Unearthed', path: 'flow/season_unearthed+fll_h.png'},
  {title: 'Unearthed', path: 'flow/season_unearthed_v.png'},
  {title: 'Unearthed', path: 'flow/season_unearthed_wordmark.png'},
  {title: 'First LEGO League', path: 'flow/first+fll_h.png'},
  {title: 'First LEGO League', path: 'flow/first+fll_v.png'},
  {title: 'First', path: 'flow/first_h.png'},
  {title: 'First', path: 'flow/first_v.png'},
  {title: 'First', path: 'flow/first_v.png'},
  {title: 'FLl Challenge', path: 'flow/fll_challenge_h.png'},
  {title: 'FLl Challenge', path: 'flow/fll_challenge_v.png'},
  {title: 'FLl Explore', path: 'flow/fll_explore_h.png'},
  {title: 'FLl Explore', path: 'flow/fll_explore_hs.png'},
  {title: 'FLl Explore', path: 'flow/fll_explore_v.png'},
];
const availableImages = ref(standardImages);

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

  if (props.slide) {
    paintSlide(props.slide);
    // addImage();
  }

  // Toolbar
  canvas.on('selection:created', updateToolbar);
  canvas.on('selection:updated', updateToolbar);
  canvas.on('selection:cleared', updateToolbar);

  // Auto-save bei Änderungen
  canvas.on('object:modified', tryAutoSave);
  canvas.on('object:added', tryAutoSave);
  canvas.on('object:removed', tryAutoSave);

  // Löschen
  window.addEventListener('keydown', keyListener);
});

onMounted(loadFont);
onMounted(loadImages);
onBeforeUnmount(() => {
  saveJson();
  window.removeEventListener('keydown', keyListener);
});

function keyListener(e: KeyboardEvent) {
  if ((e.key === 'Delete' || e.key === 'Backspace') && canvas?.getActiveObject()) {
    const activeObj = canvas.getActiveObject();
    if (activeObj.get('type') === 'textbox' && activeObj.isEditing) {
      // Sonderfall: Textfeld wird gerade bearbeitet.
      return;
    }
    e.preventDefault();

    if (activeObj.get('type') === 'activeselection') {
      activeObj.forEachObject(canvas.remove.bind(canvas));
      canvas.discardActiveObject();
    } else {
      canvas.remove(activeObj);
    }
    updateToolbar();
    canvas.requestRenderAll();
  }
}

async function loadImages() {
  const {data} = await axios.get('/logos');
  availableImages.value = [...data, ...standardImages];
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

function openImageModal() {
  showImageModal.value = true;
}

function closeImageModal() {
  showImageModal.value = false;
}

async function insertImage(image) {
  closeImageModal();
  if (!canvas) return;
  const img = await FabricImage.fromURL((image.url ?? '') + '/' + image.path);
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
      toolbarState.object = activeObject;
    } else if (type === 'rect' || type === 'circle' || type === 'triangle') {
      toolbarState.type = 'shape';
      toolbarState.object = activeObject;
    } else {
      toolbarState.type = 'none';
    }
  } else {
    toolbarState.type = 'none';
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

let lastSave = Date.now();
const SAVE_INTERVAL = 15 * 1000;

function tryAutoSave() {
  const now = Date.now();
  if (now - lastSave > SAVE_INTERVAL) {
    lastSave = now;
    saveJson();
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
</script>

<template>
  <div class="inline-block pt-4">
    <div class="flex items-start gap-x-2">
      <button @click="addRect"
              class="px-3 rounded bg-blue-500 hover:bg-blue-600 h-10 w-12 mb-1">
        <svg-icon type="mdi" :path="mdiRectangle"></svg-icon>
      </button>
      <button @click="addText" class="px-3 rounded bg-blue-500 hover:bg-blue-600 ml-2 h-10 w-12 mb-1">
        <svg-icon type="mdi" :path="mdiFormatText"></svg-icon>
      </button>
      <button @click="openImageModal" class="px-3 rounded bg-blue-500 hover:bg-blue-600 ml-2 h-10 w-12 mb-1">
        <svg-icon type="mdi" :path="mdiImageArea"></svg-icon>
      </button>
      <div v-if="toolbarState.type === 'text'" class="ml-4 mb-1 flex items-center gap-x-2">
        <!-- Text property toolbar -->
        <input type="number" v-model.number="toolbarState.object.fontSize" v-on:change="triggerRender"
               class="w-16 pr-1 border border-gray-300 rounded ml-2 h-10"/>
        <button v-on:click="makeBold" class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2 font-bold h-10 w-12"
                :class="{ 'bg-gray-400': toolbarState.object.fontWeight === 'bold' }">B
        </button>
        <button v-on:click="makeItalic" class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2 font-italic h-10 w-12"
                :class="{ 'bg-gray-400': toolbarState.object.fontStyle === 'italic' }">I
        </button>
        <button v-on:click="makeUnderline" class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2 h-10 w-12"
                :class="{ 'bg-gray-400': toolbarState.object.underline }">U
        </button>
        <input type="color" class="px-2 rounded ml-2 h-10 w-12" :value="toolbarState.object.fill"
               @input="onFillChange($event.target.value)"/>
      </div>
      <div v-else-if="toolbarState.type === 'shape'" class="ml-4 flex items-start gap-x-2">
        <!-- Shape Toolbar -->
        <select v-model="toolbarState.object.type" @change="onShapeTypeChange($event.target.value)"
                class="px-2 rounded ml-2 h-10">
          <option value="rect">Rechteck</option>
          <option value="circle">Kreis</option>
          <option value="triangle">Dreieck</option>
        </select>
        <!-- Fill Color -->
        <input type="color" class="px-2 rounded ml-2 mb-1 h-10 w-12" :value="toolbarState.object.fill"
               @input="onFillChange($event.target.value)"/>
        <!-- Border Color -->
        <input type="color" class="px-2 rounded ml-2 h-10 w-12" :value="toolbarState.object.stroke"
               @input="onStrokeChange($event.target.value)"/>
        <!-- Border Size -->
        <input type="number" min="0" class="w-16 px-1 border border-gray-300 rounded ml-2 h-10 w-12"
               :value="toolbarState.object.strokeWidth"
               @input="onStrokeWidthChange($event.target.value)"/>
      </div>
    </div>
    <canvas ref="canvasEl" class="border border-grey rounded"></canvas>

    <!-- Image Auswahl Overlay -->
    <div v-if="showImageModal" class="fixed inset-0 bg-black bg-opacity-40 flex items-center justify-center z-50">
      <div class="bg-white rounded shadow-lg p-6 w-96">
        <h2 class="text-lg font-bold mb-4">Bild auswählen</h2>
        <div class="grid grid-cols-3 gap-4 overflow-y-auto max-h-96">
          <div v-for="img in availableImages" :key="img" class="cursor-pointer">
            <img :src="`${img.url ?? ''}/${img.path}`" :alt="img.title" class="w-24 h-24 object-contain rounded border"
                 @click="insertImage(img)" />
          </div>
        </div>
        <button @click="closeImageModal" class="mt-6 px-4 py-2 rounded bg-gray-300 hover:bg-gray-400 w-full">
          Abbrechen
        </button>
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