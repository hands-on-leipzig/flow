import {Slide} from "../../model/slide.js";
import {ImageSlideContent} from "../../model/imageSlideContent.js";
import {ref, watch} from "vue";
import type {Ref} from "vue";

const listeners = [];
const websocket = new WebSocket('ws://' + location.hostname +  ':3042/ws');

export const slides: Ref<Slide[]> = ref([]);

websocket.onopen = () => {
  console.log('Connected to websocket');
}

websocket.onmessage = event => {
  console.log('Received message:', event.data);
  if (event.data.startsWith('Request')) { // Special for echo.websocket.org, remove this
    return;
  }
  const msg = JSON.parse(event.data);
  handleEvent(msg);
  listeners.forEach(listener => listener(msg));
}

// TODO add error handling, reconnecting, etc

websocket.onerror = error => {
  console.error('WebSocket error:', error);
}

watch(slides, (newSlides, oldSlides) => {
  // TODO: Don't trigger update on setSlides from the server (how?)
  websocket.send(JSON.stringify({ type: 'saveSlides', slides: newSlides }));
}, {deep: true});

export function registerClient() {
  // Register the client to the server
  // Here we should send some device information for the server to identify the client
  websocket.addEventListener('open', () => {
    websocket.send(JSON.stringify({ type: 'register', clientId: navigator.userAgent }));
  });
}
export function sendMessage(data: any) {
    if (websocket.readyState === WebSocket.OPEN) {
        websocket.send(JSON.stringify(data));
        console.log("Message sent:", data);
    } else {
        console.warn("WebSocket is not open. Current state:", websocket.readyState);
    }
}


export function addListener(listener) {
  listeners.push(listener);
}

function handleEvent(event) {
  switch (event.type) {
    case 'setSlides':
        // Use custom deserialize function for slides
        console.log('Received slides:', event.slides);
        const newSlides = Slide.fromArray(event.slides);
        console.log('Setting slides:', newSlides);
        slides.value = newSlides;
        break;
  }
}