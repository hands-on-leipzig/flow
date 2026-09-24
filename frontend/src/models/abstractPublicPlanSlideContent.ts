import {SlideContent} from "./slideContent";
import {eventPrograms, programId, type EventWithPrograms} from "@/utils/eventPrograms";

export abstract class AbstractPublicPlanSlideContent extends SlideContent {

    public planId: number;
    // Wie viele Minuten nach vorne soll geschaut werden
    public interval: number = 30;
    // Übergreifend plus the attached program ids to show.
    public joint: boolean = true;
    public programs: number[] = [];
    /**
     * Set only while an older slide still stores `role` and has no `joint` key.
     * 14 means every attached program, 10 means Explore, 6 means Challenge.
     */
    public legacyRole: number | null = null;
    // Filter auf einen bestimmten Raum, 0 bedeutet alle Räume
    public room: number = 0;

    protected constructor(data: object) {
        super();
        const raw = (data ?? {}) as Record<string, unknown>;
        Object.assign(this, data);
        if (Object.prototype.hasOwnProperty.call(raw, 'joint')) {
            this.joint = raw.joint === true || raw.joint === 1 || raw.joint === '1';
            this.programs = Array.isArray(raw.programs)
                ? raw.programs.map((id) => Number(id)).filter((id) => id > 0)
                : [];
            this.legacyRole = null;
            return;
        }

        const role = raw.role == null || raw.role === '' ? 14 : Number(raw.role);
        this.legacyRole = role === 10 || role === 6 ? role : 14;
        this.joint = true;
        this.programs = this.legacyRole === 10 ? [2] : this.legacyRole === 6 ? [3] : [];
    }

    /** Writes the checkbox selection. An unread legacy role stays a role until the editor maps it. */
    protected audienceFields(): { joint: boolean; programs: number[] } | { role: number } {
        if (this.legacyRole != null) {
            return {role: this.legacyRole};
        }

        return {joint: this.joint, programs: this.programs};
    }
}

export function resolvedAudienceSelection(
    content: Pick<AbstractPublicPlanSlideContent, 'joint' | 'programs' | 'legacyRole'>,
    event: EventWithPrograms | null | undefined,
): { joint: boolean; programs: number[] } {
    if (content.legacyRole == null) {
        return {
            joint: !!content.joint,
            programs: (content.programs ?? []).map((id) => Number(id)).filter((id) => id > 0),
        };
    }
    if (content.legacyRole === 10) {
        return {joint: true, programs: [2]};
    }
    if (content.legacyRole === 6) {
        return {joint: true, programs: [3]};
    }
    return {
        joint: true,
        programs: eventPrograms(event).map((program) => programId(program)).filter((id) => id > 0),
    };
}
