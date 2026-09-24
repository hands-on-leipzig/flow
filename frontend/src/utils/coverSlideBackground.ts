import {FabricImage, type Canvas, type StaticCanvas} from 'fabric';
import {ref} from 'vue';
import type {SeasonLogoPlacement} from '@/models/seasonLogo';

const DESIGN_WIDTH = 800;
const DESIGN_HEIGHT = 450;

const covers = new WeakMap<object, SeasonLogoPlacement>();
const coverVersion = ref(0);

export function coveredPlacement(owner: object): SeasonLogoPlacement | null {
    coverVersion.value;
    return covers.get(owner) ?? null;
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
