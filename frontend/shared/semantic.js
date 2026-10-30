import icons from '../theme/icons.json';
import semantic from '../theme/semantic.json';

export const iconId = (name) => icons[name] || name;

export function sem(group, value) {
  const m = semantic[group]?.[value];
  return { color: m?.color || 'secondary', icon: iconId(m?.icon || 'circle-dashed'), label: m?.label || String(value || '').replace(/_/g, ' ') };
}

export const spriteUrl = () => document.querySelector('meta[name="icon-sprite"]')?.content || '/assets/icons.svg';
