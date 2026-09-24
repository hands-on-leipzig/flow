import {FabricImage, type Canvas, type StaticCanvas} from 'fabric';
import {ref} from 'vue';
import type {SeasonLogoPlacement} from '@/models/seasonLogo';

const DESIGN_WIDTH = 800;
const DESIGN_HEIGHT = 450;

const covers = new WeakMap<object, SeasonLogoPlacement>();
const imageSizes = new WeakMap<object, {width: number; height: number}>();
const coverVersion = ref(0);

export function coveredPlacement(owner: object): SeasonLogoPlacement | null {
    coverVersion.value;
    return covers.get(owner) ?? null;
}

export function rememberBackgroundSize(owner: object, width: number, height: number) {
    if (!(width > 0) || !(height > 0)) {
        return;
    }
    imageSizes.set(owner, {width, height});
    coverVersion.value += 1;
}

/**
 * Season-logo anchor in screen pixels. The background covers the frame:
 * scale to width when the screen is wider than the image, otherwise scale to height.
 */
export function screenBackgroundPlacement(
    owner: object,
    frameWidth: number,
    frameHeight: number,
): SeasonLogoPlacement | null {
    coverVersion.value;
    const size = imageSizes.get(owner);
    if (!size || frameWidth <= 0 || frameHeight <= 0) {
        return null;
    }
    const scale = Math.max(frameWidth / size.width, frameHeight / size.height);
    const imageTop = (frameHeight - size.height * scale) / 2;
    const designZoom = Math.min(frameWidth / DESIGN_WIDTH, frameHeight / DESIGN_HEIGHT);
    return {
        centerX: frameWidth / 2,
        top: Math.max(imageTop, 0) + 5 * designZoom,
        scaleX: scale,
        scaleY: scale,
    };
}

/** Scale the slide background image so it covers the 800×450 canvas. Returns the season-logo anchor on that image. */
export function coverSlideBackground(canvas: Canvas | StaticCanvas | null, owner?: object): SeasonLogoPlacement | null {
    const image = canvas?.backgroundImage;
    if (!(image instanceof FabricImage)) {
        return null;
    }
    const {width, height} = image.getOriginalSize();
    if (!(width > 0) || !(height > 0)) {
        return null;
    }
    const scale = Math.max(DESIGN_WIDTH / width, DESIGN_HEIGHT / height);
    const drawnWidth = width * scale;
    const drawnHeight = height * scale;
    const top = (DESIGN_HEIGHT - drawnHeight) / 2;
    image.set({
        cropX: 0,
        cropY: 0,
        width,
        height,
        scaleX: scale,
        scaleY: scale,
        angle: 0,
        originX: 'left',
        originY: 'top',
        left: (DESIGN_WIDTH - drawnWidth) / 2,
        top,
    });
    canvas.requestRenderAll();
    const place: SeasonLogoPlacement = {
        centerX: DESIGN_WIDTH / 2,
        top: top + 5,
        scaleX: scale,
        scaleY: scale,
    };
    if (owner) {
        covers.set(owner, place);
        coverVersion.value += 1;
    }
    return place;
}
