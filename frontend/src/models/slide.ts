import {SlideContent} from "./slideContent";
import {ImageSlideContent} from "./imageSlideContent";
import {RobotGameSlideContent} from "./robotGameSlideContent";
import {UrlSlideContent} from "./urlSlideContent";
import {FabricSlideContent} from "./fabricSlideContent";
import {PublicPlanSlideContent} from "./publicPlanSlideContent";

export class Slide {

    public id: number;
    public name: string;
    public type: string;
    public content: SlideContent;
    public active: number = 1; // 0 or 1
    public transition_time: number = 0; // in seconds, 0 means default slideshow time

    constructor(data: any, content: SlideContent) {
        Object.assign(this, data);
        this.content = content;
    }

    public static fromObject(obj: any): Slide {
        let content: SlideContent;

        // @ts-ignore
        if (obj.content) {
            content = this.getContentFromType(obj);
        }
        return new Slide(obj, content);
    }

    private static getContentFromType(data: any): SlideContent {
        const content = JSON.parse(data.content);
        switch (data.type) {
            case "ImageSlideContent":
                return new ImageSlideContent(content);
            case "RobotGameSlideContent":
                return new RobotGameSlideContent(content);
            case "UrlSlideContent":
                return new UrlSlideContent(content);
            case "FabricSlideContent":
                return new FabricSlideContent(content);
            case "PublicPlanSlideContent":
                return new PublicPlanSlideContent(content);
            default:
                console.error("Unknown slide content type: " + data.type);
                return null;
        }
    }

    public static createNewSlide(type: string): Slide {
        const content = Slide.getContentFromType({type, content: "{}"});
        return new Slide({type}, content);
    }
}
