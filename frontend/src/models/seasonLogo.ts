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

export function backgroundImageScale(background: unknown): {scaleX: number; scaleY: number} {
    let value = background;
    if (typeof value === 'string') {
        try {
            value = JSON.parse(value);
        } catch {
            return {scaleX: 1, scaleY: 1};
        }
    }
    if (!value || typeof value !== 'object') {
        return {scaleX: 1, scaleY: 1};
    }
    const image = (value as {backgroundImage?: {scaleX?: unknown; scaleY?: unknown}}).backgroundImage;
    return {
        scaleX: positiveScale(image?.scaleX),
        scaleY: positiveScale(image?.scaleY),
    };
}

function positiveScale(value: unknown): number {
    const scale = Number(value);
    return Number.isFinite(scale) && scale > 0 ? scale : 1;
}
