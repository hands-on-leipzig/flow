import {SlideContent} from "./slideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class ImageSlideContent extends SlideContent {

    public imageUrl: {};

    constructor(data: object) {
        super();
        Object.assign(this, data);
        this.showSeasonLogo = readShowSeasonLogo(data, 'ImageSlideContent');
    }

    public toJSON(): object {
        return {
            type: "ImageSlideContent",
            url: this.imageUrl,
            showSeasonLogo: this.showSeasonLogo,
        };
    }
}
