import {SlideContent} from "./slideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class FabricSlideContent extends SlideContent {

    constructor(data: object) {
        super();
        Object.assign(this, data);
        this.showSeasonLogo = readShowSeasonLogo(data, 'FabricSlideContent');
    }

    public toJSON(): object {
        return {
            type: "FabricSlideContent",
            background: this.background,
            showSeasonLogo: this.showSeasonLogo,
        };
    }
}
