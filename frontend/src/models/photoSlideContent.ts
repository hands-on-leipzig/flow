import {SlideContent} from "./slideContent";

export class PhotoSlideContent extends SlideContent {


    constructor() {
        super();
    }

    public toJSON(): object {
        return {
            type: "PhotoSlideContent",
        };
    }
}
