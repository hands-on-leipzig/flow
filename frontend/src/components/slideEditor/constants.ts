import {Gradient} from 'fabric';

// Logical slide size; renderers scale this coordinate space to the display.
export const SLIDE_WIDTH = 800;
export const SLIDE_HEIGHT = 450;

// Custom object properties that must survive toObject()/loadFromJSON().
export const SERIALIZED_PROPS = ['name', 'locked'];

export const ACCENT = '#ff7a00';

export const COLOR_PALETTE = [
  '#000000', '#334155', '#94a3b8', '#e2e8f0', '#ffffff',
  '#ff7a00', '#FFD700', '#ED1C24', '#00A651', '#51BFB4',
  '#0066B3', '#662D91',
];

export const FONT_FAMILIES = [
  {value: 'Uniform', label: 'Uniform (HoT)'},
  {value: 'Arial', label: 'Arial'},
  {value: 'Verdana', label: 'Verdana'},
  {value: 'Georgia', label: 'Georgia'},
  {value: 'Times New Roman', label: 'Times New Roman'},
  {value: 'Courier New', label: 'Courier New'},
  {value: 'Impact', label: 'Impact'},
];

export interface GradientPreset {
  id: string;
  label: string;
  from: string;
  to: string;
}

export const BACKGROUND_GRADIENTS: GradientPreset[] = [
  {id: 'hot', label: 'HoT', from: '#ff7a00', to: '#ffb347'},
  {id: 'challenge', label: 'Challenge', from: '#ED1C24', to: '#7f1d1d'},
  {id: 'explore', label: 'Explore', from: '#00A651', to: '#065f46'},
  {id: 'future', label: 'Future', from: '#51BFB4', to: '#0f766e'},
  {id: 'sky', label: 'Himmel', from: '#0066B3', to: '#51BFB4'},
  {id: 'night', label: 'Nacht', from: '#0f172a', to: '#334155'},
  {id: 'paper', label: 'Hell', from: '#ffffff', to: '#e2e8f0'},
];

export function createGradient(preset: GradientPreset) {
  return new Gradient({
    type: 'linear',
    gradientUnits: 'pixels',
    coords: {x1: 0, y1: 0, x2: SLIDE_WIDTH, y2: SLIDE_HEIGHT},
    colorStops: [
      {offset: 0, color: preset.from},
      {offset: 1, color: preset.to},
    ],
  });
}

export function matchGradient(gradient: Gradient<'linear'>): string | null {
  const stops = gradient.colorStops ?? [];
  const from = stops[0]?.color?.toLowerCase();
  const to = stops[stops.length - 1]?.color?.toLowerCase();
  const preset = BACKGROUND_GRADIENTS.find(p => p.from.toLowerCase() === from && p.to.toLowerCase() === to);
  return preset?.id ?? null;
}

/** Normalizes any CSS color fabric may hold into #rrggbb for <input type="color">. */
export function toHexColor(value: string | null | undefined, fallback = '#000000'): string {
  if (!value) return fallback;
  const v = value.trim();
  if (/^#[0-9a-f]{6}$/i.test(v)) return v.toLowerCase();
  if (/^#[0-9a-f]{3}$/i.test(v)) {
    return ('#' + v.slice(1).split('').map(c => c + c).join('')).toLowerCase();
  }
  const rgb = v.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i);
  if (rgb) {
    return '#' + rgb.slice(1, 4).map(n => Number(n).toString(16).padStart(2, '0')).join('');
  }
  return fallback;
}

export function isTransparent(value: string | null | undefined): boolean {
  if (!value || value === 'transparent') return true;
  const rgba = value.match(/rgba\([^)]*,\s*([0-9.]+)\s*\)/i);
  return !!rgba && Number(rgba[1]) === 0;
}
