export const SEASON_LOGO_WIDTH = 652;
export const SEASON_LOGO_HEIGHT = 217;

const SEASON_LOGO_DEFAULT: Record<string, boolean> = {
    RobotGameSlideContent: true,
    PublicPlanSlideContent: true,
    PublicPlanNextSlideContent: true,
    PublicPlanNextEventSlideContent: true,
    TeamsMapSlideContent: true,
    UrlSlideContent: false,
    FabricSlideContent: false,
    TeamsTableSlideContent: false,
    ImageSlideContent: false,
};

export function readShowSeasonLogo(data: object, type: string): boolean {
    if (Object.prototype.hasOwnProperty.call(data, 'showSeasonLogo')) {
        const value = (data as {showSeasonLogo?: unknown}).showSeasonLogo;
        return value === true || value === 1 || value === '1';
    }
    return SEASON_LOGO_DEFAULT[type] === true;
}

const CANVAS_WIDTH = 800;

export type SeasonLogoPlacement = {
    centerX: number;
    top: number;
    scaleX: number;
    scaleY: number;
};

/** Logo anchor in 800×450 canvas pixels: centered on the background image, 5px below its top. */
export function seasonLogoPlacement(background: unknown): SeasonLogoPlacement {
    const canvas: SeasonLogoPlacement = {centerX: CANVAS_WIDTH / 2, top: 5, scaleX: 1, scaleY: 1};
    const image = readBackgroundImage(background);
    if (!image) {
        return canvas;
    }
    const scaleX = positiveScale(image.scaleX);
    const scaleY = positiveScale(image.scaleY);
    const width = positiveLength(image.width) * scaleX;
    const height = positiveLength(image.height) * scaleY;
    if (width === 0 || height === 0) {
        return {...canvas, scaleX, scaleY};
    }
    const originOffsetX = originOffset(image.originX, width, 'right');
    const originOffsetY = originOffset(image.originY, height, 'bottom');
    const dx = width / 2 - originOffsetX;
    const dy = 5 - originOffsetY;
    const angle = finiteNumber(image.angle, 0) * Math.PI / 180;
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    return {
        centerX: finiteNumber(image.left, 0) + dx * cos - dy * sin,
        top: finiteNumber(image.top, 0) + dx * sin + dy * cos,
        scaleX,
        scaleY,
    };
}

type BackgroundImageFields = {
    left?: unknown;
    top?: unknown;
    width?: unknown;
    height?: unknown;
    scaleX?: unknown;
    scaleY?: unknown;
    originX?: unknown;
    originY?: unknown;
    angle?: unknown;
};

function readBackgroundImage(background: unknown): BackgroundImageFields | null {
    let value = background;
    if (typeof value === 'string') {
        try {
            value = JSON.parse(value);
        } catch {
            return null;
        }
    }
    if (!value || typeof value !== 'object') {
        return null;
    }
    const image = (value as {backgroundImage?: unknown}).backgroundImage;
    if (!image || typeof image !== 'object') {
        return null;
    }
    return image as BackgroundImageFields;
}

function originOffset(origin: unknown, size: number, far: 'right' | 'bottom'): number {
    const value = origin === 'left' || origin === 'top' || origin === 'center' || origin === far
        ? origin
        : 'center';
    if (value === 'center') {
        return size / 2;
    }
    if (value === far) {
        return size;
    }
    return 0;
}

function positiveLength(value: unknown): number {
    const length = Number(value);
    return Number.isFinite(length) && length > 0 ? length : 0;
}

function finiteNumber(value: unknown, fallback: number): number {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function positiveScale(value: unknown): number {
    const scale = Number(value);
    return Number.isFinite(scale) && scale > 0 ? scale : 1;
}
