import {SlideContent} from "./slideContent";
import {ImageSlideContent} from "./imageSlideContent";
import {RobotGameSlideContent} from "./robotGameSlideContent";
import {UrlSlideContent} from "./urlSlideContent";
import {PhotoSlideContent} from "./photoSlideContent";
import {FabricSlideContent} from "./fabricSlideContent";
import {PublicPlanSlideContent} from "./publicPlanSlideContent";

export class Slide {

    public id: number;
    public name: string;
    public type: string;
    public content: SlideContent;

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
                return new ImageSlideContent(content.imageUrl);
            case "RobotGameSlideContent":
                return new RobotGameSlideContent();
            case "UrlSlideContent":
                return new UrlSlideContent(content.url);
            case "PhotoSlideContent":
                return new PhotoSlideContent();
            case "FabricSlideContent":
                return new FabricSlideContent(content.json);
            case "PublicPlanSlideContent":
                return new PublicPlanSlideContent(content.planId, content.hours, content.role);
            default:
                console.error("Unknown slide content type: " + data.type);
                return null;
        }
    }
}
