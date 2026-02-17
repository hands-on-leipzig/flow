import { ref, onMounted, onUnmounted, watch } from 'vue';
import type { Ref } from 'vue';

export interface UseScaleToFitOptions {
  safetyMargin?: number;
  maxScale?: number;
  minScale?: number;
  /** When to recompute: observe only container, or container + content. Default 'both'. */
  observe?: 'container' | 'both';
}

/**
 * Composable that scales content to fit inside a container. Returns a scale factor
 * to apply via transform: scale(scaleFactor). Uses a safety margin so content
 * stays inside bounds on non-standard sizes.
 *
 * Used to scale the slide content to fit inside the slide container,
 * so it works on different screen sizes and with different content sizes.
 *
 * @example
 * const containerRef = ref<HTMLElement | null>(null);
 * const contentRef = ref<HTMLElement | null>(null);
 * const { scaleFactor, updateScale } = useScaleToFit(containerRef, contentRef, { safetyMargin: 0.02 });
 * // In template: :style="{ transform: `scale(${scaleFactor})` }"
 * // Call updateScale() when data changes (e.g. in a watch) so scale recomputes when content appears.
 */
export function useScaleToFit(
  containerRef: Ref<HTMLElement | null>,
  contentRef: Ref<HTMLElement | null>,
  options: UseScaleToFitOptions = {}
) {
  const {
    safetyMargin = 0.02,
    maxScale = 1,
    minScale = 0.01,
    observe: observeOption = 'both',
  } = options;

  const scaleFactor = ref(1);
  let resizeObserver: ResizeObserver | null = null;

  function updateScale() {
    requestAnimationFrame(() => {
      const container = containerRef.value;
      const content = contentRef.value;
      if (!container || !content) {
        scaleFactor.value = 1;
        return;
      }
      const cw = container.clientWidth;
      const ch = container.clientHeight;
      const contentW = content.offsetWidth;
      const contentH = content.offsetHeight;

      if (contentW <= 0 || contentH <= 0) {
        scaleFactor.value = 1;
        return;
      }
      const margin = 1 - safetyMargin;
      const availableW = cw * margin;
      const availableH = ch * margin;
      let s = Math.min(availableW / contentW, availableH / contentH, maxScale);
      s = Math.max(minScale, Math.min(maxScale, s));
      scaleFactor.value = s;
    });
  }

  onMounted(() => {
    updateScale();
    resizeObserver = new ResizeObserver(() => updateScale());
    if (containerRef.value) {
      resizeObserver.observe(containerRef.value);
    }
    if (observeOption === 'both' && contentRef.value) {
      resizeObserver.observe(contentRef.value);
    }
  });

  watch(
    contentRef,
    (newEl, oldEl) => {
      if (observeOption !== 'both' || !resizeObserver) return;
      if (oldEl) resizeObserver.unobserve(oldEl);
      if (newEl) resizeObserver.observe(newEl);
    },
    { immediate: true }
  );

  onUnmounted(() => {
    resizeObserver?.disconnect();
    resizeObserver = null;
  });

  return { scaleFactor, updateScale };
}
