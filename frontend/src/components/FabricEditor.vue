<script setup lang="ts">
import {Canvas, Rect, Textbox} from 'fabric'
import {onMounted, onBeforeUnmount, reactive, shallowRef} from 'vue';
import SvgIcon from '@jamescoyle/vue-icon';
import { mdiFormatText, mdiRectangle, mdiContentSave } from '@mdi/js';

// Ideen und TODOS
// Resize
// Border korrekt, Layouting allgemein
// Undo / Redo
// Toolbox: Farbe, Font
// Bilder einfügen

const DEFAULT_WIDTH = 800;
const DEFAULT_HEIGHT = 450;
const ASPECT_RATIO = DEFAULT_WIDTH / DEFAULT_HEIGHT;

const canvasEl = shallowRef(null);
let canvas;

const toolbarState = reactive({
  type: 'none', // 'none', 'text', 'shape', 'image'
  object: undefined,
});

onMounted(() => {
  canvas = new Canvas(canvasEl.value, {
    width: DEFAULT_WIDTH,
    height: DEFAULT_HEIGHT,
    backgroundColor: '#ffffff'
  });

  canvas.on('selection:created', updateToolbar);

  canvas.on('selection:updated', updateToolbar);

  canvas.on('selection:cleared', updateToolbar);
});

onBeforeUnmount(() => {
  if (canvasEl.value) {
    // canvasEl.value.dispose();
    canvasEl.value = null;
  }
});

function resizeCanvas() {
  const container = canvasEl.parentNode;

  // Fit canvas width to container width
  const containerWidth = container.offsetWidth;
  const canvasWidth = containerWidth;
  const canvasHeight = containerWidth / ASPECT_RATIO;

  canvas.setWidth(canvasWidth);
  canvas.setHeight(canvasHeight);

  const scaleX = canvasWidth / DEFAULT_WIDTH;
  const scaleY = canvasHeight / DEFAULT_HEIGHT;
  const scale = Math.min(scaleX, scaleY);

  canvasEl.value.setZoom(scale);
}

function addRect() {
  if (!canvas) return;
  const rect = new Rect({
    left: 100,
    top: 100,
    fill: '#add8e6',
    width: 100,
    height: 100
  });
  canvas.add(rect);
  canvas.requestRenderAll();
}

function addText() {
  if (!canvasEl.value) return;
  const text = new Textbox("FLOW", {
    left: 100,
    top: 100,
    fontFamily: 'Poppins-Regular',
    fontSize: 24,
    fill: '#000000',
    width: 200,
    editable: true
  });
  canvas.add(text);
  canvas.setActiveObject(text);
  canvas.requestRenderAll();
}

function updateToolbar() {
  const activeObject = canvas.getActiveObject();
  // Update toolbar based on selection
  if (activeObject) {
    console.log('Update toolbar for selection:', activeObject.get('type'));
    // Example: Enable/disable buttons based on selection properties
    if (activeObject.get('type') === 'textbox') {
      toolbarState.type = 'text';
      toolbarState.object = activeObject;
    } else if (activeObject.get('type') === 'rect') {
      toolbarState.type = 'shape';
      toolbarState.object = activeObject;
      console.log(activeObject);
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
  triggerRender();
}

function makeItalic() {
  if (toolbarState.object.fontStyle === 'italic') {
    toolbarState.object.set({fontStyle: 'normal'});
  } else {
    toolbarState.object.set({fontStyle: "italic"});
  }
  triggerRender();
}

function makeUnderline() {
  toolbarState.object.set({underline: !toolbarState.object.underline});
  triggerRender();
}

function onFillChange(color) {
  toolbarState.object.set({fill: color});
  triggerRender();
}

function triggerRender() {
  canvas.requestRenderAll();
}

function saveJson() {
  const json = canvas.toJSON();
  console.log(JSON.stringify(json));
}
</script>

<template>
  <div class="inline-block p-4">
    <button @click="addRect"
            class="px-3 py-1 rounded bg-blue-500  hover:bg-blue-600">
      <svg-icon type="mdi" :path="mdiRectangle"></svg-icon>
    </button>
    <button @click="addText" class="px-3 py-1 rounded bg-blue-500  hover:bg-blue-600 ml-2">
      <svg-icon type="mdi" :path="mdiFormatText"></svg-icon>
    </button>
    <button @click="saveJson" class="px-3 py-1 rounded btn-primary ml-2 bg-green-500 hover:bg-green-600">
      <svg-icon type="mdi" :path="mdiContentSave"></svg-icon>
    </button>
    <div v-if="toolbarState.type === 'text'" class="inline-block ml-4">
      <!-- Text property toolbar -->
      Font Size:
      <input type="number" v-model.number="toolbarState.object.fontSize" v-on:change="triggerRender"
             class="w-16 px-1 py-0.5 border border-gray-300 rounded ml-2" />
      <button v-on:click="makeBold" class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2 font-bold"
              :class="{ 'bg-gray-400': toolbarState.object.fontWeight === 'bold' }">B
      </button>
      <button v-on:click="makeItalic" class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2 font-italic"
              :class="{ 'bg-gray-400': toolbarState.object.fontStyle === 'italic' }">I
      </button>
      <button v-on:click="makeUnderline" class="px-2 py-1 rounded bg-gray-200 hover:bg-gray-300 ml-2"
              :class="{ 'bg-gray-400': toolbarState.object.underline }">U</button>
      <input type="color" class="px-2 py-1 rounded ml-2" :value="toolbarState.object.fill" @input="onFillChange($event.target.value)"/>
    </div>
    <div v-else-if="toolbarState.type === 'shape'" class="inline-block ml-4">
      <!-- Shape Toolbar -->
      <input type="color" class="px-2 py-1 rounded ml-2" :value="toolbarState.object.fill" @input="onFillChange($event.target.value)"/>
    </div>
    <canvas ref="canvasEl" class="border border-grey rounded"></canvas>
  </div>
</template>

<style scoped>

</style>