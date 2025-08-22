import {SlideContent} from "./slideContent";

export class UrlSlideContent extends SlideContent {

    public url: string;

    constructor(url: string,) {
        super();
        this.url = url;
    }

    public toJSON(): object {
        return {
            type: "UrlSlideContent",
            url: this.url
        };
    }
}
