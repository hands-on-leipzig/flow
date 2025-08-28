<script setup>
import {ref, onMounted, reactive, onBeforeUnmount} from 'vue';
import Konva from "konva";

const boxSize = reactive({width: 640, height: 360}); // 16:9 Standard

const stageConfig = reactive({
  width: boxSize.width,
  height: boxSize.height
});

function updateStageSize() {
  stageConfig.width = boxSize.width;
  stageConfig.height = boxSize.height;
}

let resizing = false;
let startX, startY, startWidth, startHeight;

function startResize(e) {
  resizing = true;
  startX = e.clientX;
  startY = e.clientY;
  startWidth = boxSize.width;
  startHeight = boxSize.height;
  window.addEventListener('mousemove', doResize);
  window.addEventListener('mouseup', stopResize);
}

function doResize(e) {
  if (!resizing) return;
  let dx = e.clientX - startX;
  let dy = e.clientY - startY;

  // Wähle die größere Änderung für das Resizing
  let delta = Math.abs(dx) > Math.abs(dy) ? dx : dy;
  let newWidth = Math.max(320, startWidth + delta);
  let newHeight = Math.round(newWidth * 9 / 16);

  // Alternativ: Höhe direkt anpassen und Breite berechnen
  // let newHeight = Math.max(180, startHeight + dy);
  // let newWidth = Math.round(newHeight * 16 / 9);

  boxSize.width = newWidth;
  boxSize.height = newHeight;
  updateStageSize();
}

function stopResize() {
  resizing = false;
  window.removeEventListener('mousemove', doResize);
  window.removeEventListener('mouseup', stopResize);
}

Konva._fixTextRendering = true;

const text = ref('Some text here');
const textWidth = ref(200);
const isEditing = ref(false);
const textNode = ref(null);
const transformerNode = ref(null);

onMounted(() => {
  updateStageSize();
  transformerNode.value.getNode().nodes([textNode.value.getNode()]);
});

onBeforeUnmount(() => {
  stopResize();
});

const handleTextDblClick = () => {
  const textNodeKonva = textNode.value.getNode();
  const stage = textNodeKonva.getStage();
  const textPosition = textNodeKonva.absolutePosition();
  const stageBox = stage.container().getBoundingClientRect();

  const areaPosition = {
    x: stageBox.left + textPosition.x,
    y: stageBox.top + textPosition.y,
  };

  const textarea = document.createElement('textarea');
  document.body.appendChild(textarea);

  textarea.value = textNodeKonva.text();
  textarea.style.position = 'absolute';
  textarea.style.top = areaPosition.y + 'px';
  textarea.style.left = areaPosition.x + 'px';
  textarea.style.width = textNodeKonva.width() - textNodeKonva.padding() * 2 + 'px';
  textarea.style.height = textNodeKonva.height() - textNodeKonva.padding() * 2 + 5 + 'px';
  textarea.style.fontSize = textNodeKonva.fontSize() + 'px';
  textarea.style.border = 'none';
  textarea.style.padding = '0px';
  textarea.style.margin = '0px';
  textarea.style.overflow = 'hidden';
  textarea.style.background = 'none';
  textarea.style.outline = 'none';
  textarea.style.resize = 'none';
  textarea.style.lineHeight = textNodeKonva.lineHeight();
  textarea.style.fontFamily = textNodeKonva.fontFamily();
  textarea.style.transformOrigin = 'left top';
  textarea.style.textAlign = textNodeKonva.align();
  textarea.style.color = textNodeKonva.fill();

  const rotation = textNodeKonva.rotation();
  let transform = '';
  if (rotation) {
    transform += 'rotateZ(' + rotation + 'deg)';
  }
  textarea.style.transform = transform;

  textarea.style.height = 'auto';
  textarea.style.height = textarea.scrollHeight + 3 + 'px';

  isEditing.value = true;
  textarea.focus();

  function removeTextarea() {
    textarea.parentNode.removeChild(textarea);
    window.removeEventListener('click', handleOutsideClick);
    isEditing.value = false;
  }

  function setTextareaWidth(newWidth) {
    if (!newWidth) {
      newWidth = textNodeKonva.placeholder?.length * textNodeKonva.fontSize();
    }
    textarea.style.width = newWidth + 'px';
  }

  textarea.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      text.value = textarea.value;
      removeTextarea();
    }
    if (e.key === 'Escape') {
      removeTextarea();
    }
  });

  textarea.addEventListener('keydown', function () {
    const scale = textNodeKonva.getAbsoluteScale().x;
    setTextareaWidth(textNodeKonva.width() * scale);
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + textNodeKonva.fontSize() + 'px';
  });

  function handleOutsideClick(e) {
    if (e.target !== textarea) {
      text.value = textarea.value;
      removeTextarea();
    }
  }

  setTimeout(() => {
    window.addEventListener('click', handleOutsideClick);
    window.addEventListener('touchstart', handleOutsideClick);
  });
};

const handleTransform = (e) => {
  const node = textNode.value.getNode();
  textWidth.value = node.width() * node.scaleX();
  node.setAttrs({
    width: node.width() * node.scaleX(),
    scaleX: 1,
  });
};

</script>

<template>
  <div class="h-screen w-full d-flex justify-content-center align-items-center">
  <div
      id="canvasBox"
      ref="canvasBox"
      class="position-relative border rounded bg-light mx-auto"
      :style="{
    width: boxSize.width + 'px',
    height: boxSize.height + 'px',
    aspectRatio: '16 / 9',
    minWidth: '320px',
    minHeight: '180px',
    maxWidth: '100%',
    maxHeight: '80vh',
    overflow: 'hidden'
    }">
    <v-stage :config="stageConfig">
      <v-layer>
        <v-text
            ref="textNode"
            :config="{
          text: text,
          x: 50,
          y: 80,
          fontSize: 20,
          draggable: true,
          width: textWidth,
          visible: !isEditing,
        }"
            @dblclick="handleTextDblClick"
            @dbltap="handleTextDblClick"
            @transform="handleTransform"
        />
        <v-transformer
            v-if="!isEditing"
            ref="transformerNode"
            :config="{
          enabledAnchors: ['middle-left', 'middle-right'],
          boundBoxFunc: (oldBox, newBox) => {
            newBox.width = Math.max(30, newBox.width);
            return newBox;
          },
        }"
        />
      </v-layer>
    </v-stage>
    <div
        class="position-absolute"
        style="right: 0; bottom: 0; width: 24px; height: 24px; cursor: se-resize; z-index: 10;"
        @mousedown="startResize"
    >
      <svg width="24" height="24" fill="gray">
        <rect x="8" y="16" width="8" height="2"/>
        <rect x="12" y="12" width="4" height="2"/>
      </svg>
    </div>
  </div>
  </div>
</template>
