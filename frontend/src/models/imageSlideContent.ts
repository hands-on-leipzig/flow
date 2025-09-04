import {SlideContent} from "./slideContent";

export class ImageSlideContent extends SlideContent {

    public imageUrl: {};

    constructor(data: object) {
        super();
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "ImageSlideContent",
            url: this.imageUrl
        };
    }
}
