import {SlideContent} from "./slideContent";

export class UrlSlideContent extends SlideContent {

    public url: string;

    constructor(data: object)  {
        super();
        Object.assign(this, data);
    }

    public toJSON(): object {
        return {
            type: "UrlSlideContent",
            background: this.background,
            url: this.url
        };
    }
}
