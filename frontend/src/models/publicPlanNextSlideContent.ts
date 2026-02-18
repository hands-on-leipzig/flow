import { SlideContent } from './slideContent';

export class PublicPlanNextSlideContent extends SlideContent {
  public planId: number;
  // Wie viele Minuten nach vorne soll geschaut werden
  public interval: number = 30;
  /** Role: 14 = Allgemein, 6 = Besucher Challenge, 10 = Besucher Explore */
  public role: number = 14;
  // Filter auf einen bestimmten Raum, 0 bedeutet alle Räume
  public room: number = 0;

  constructor(data: object) {
    super();
    Object.assign(this, data);
  }

  public toJSON(): object {
    return {
      type: 'PublicPlanNextSlideContent',
      planId: this.planId,
      interval: this.interval,
      role: this.role,
      room: this.room,
      background: this.background,
    };
  }
}
