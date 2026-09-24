import {SlideContent} from "./slideContent";
import {readShowSeasonLogo} from "./seasonLogo";

export class UrlSlideContent extends SlideContent {

    public url: string;

    constructor(data: object)  {
        super();
        Object.assign(this, data);
        this.showSeasonLogo = readShowSeasonLogo(data, 'UrlSlideContent');
    }

    public toJSON(): object {
        return {
            type: "UrlSlideContent",
            background: this.background,
            url: this.url,
            showSeasonLogo: this.showSeasonLogo,
        };
    }
}
