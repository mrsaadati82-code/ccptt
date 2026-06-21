/**
 * Force-applies CSS properties via setProperty(..., 'important') so that
 * NOTHING from the host WordPress admin theme or any 3rd-party plugin
 * stylesheet can override our visual design.
 */
import { useLayoutEffect, useRef } from 'react';

export type ForceStyles = Record<string, string | number | undefined | null>;

function toKebab(s: string): string {
  return s.replace(/[A-Z]/g, m => '-' + m.toLowerCase());
}

export function applyImportant(el: HTMLElement | null, styles: ForceStyles) {
  if (!el) return;
  for (const k of Object.keys(styles)) {
    const v = styles[k];
    if (v == null || v === '') {
      el.style.removeProperty(toKebab(k));
    } else {
      el.style.setProperty(toKebab(k), String(v), 'important');
    }
  }
}

export function useForceStyle<T extends HTMLElement>(styles: ForceStyles) {
  const ref = useRef<T | null>(null);
  useLayoutEffect(() => {
    applyImportant(ref.current, styles);
    const el = ref.current;
    if (!el) return;
    const reapply = () => applyImportant(ref.current, styles);
    el.addEventListener('focus', reapply);
    el.addEventListener('blur', reapply);
    el.addEventListener('input', reapply);
    return () => {
      el.removeEventListener('focus', reapply);
      el.removeEventListener('blur', reapply);
      el.removeEventListener('input', reapply);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(styles)]);
  return ref;
}

export const INPUT_BASE: ForceStyles = {
  width: '100%',
  padding: '0.65rem 1rem',
  borderRadius: '0.9rem',
  border: '1.5px solid #e2e8f0',
  backgroundColor: '#ffffff',
  backgroundImage: 'none',
  color: '#0f172a',
  fontSize: '0.8125rem',
  lineHeight: '1.6',
  fontFamily: "'Dana','Vazirmatn',Tahoma,sans-serif",
  fontWeight: '500',
  textAlign: 'right',
  direction: 'rtl',
  unicodeBidi: 'plaintext',
  appearance: 'none',
  WebkitAppearance: 'none',
  MozAppearance: 'textfield',
  boxShadow: '0 1px 2px rgba(15,23,42,0.04)',
  outline: '0',
  height: 'auto',
  minHeight: 'auto',
  maxWidth: '100%',
  margin: '0',
  display: 'block',
  textShadow: 'none',
  letterSpacing: 'normal',
};

export const INPUT_NUM: ForceStyles = {
  ...INPUT_BASE,
  textAlign: 'right',
  direction: 'rtl',
  fontFeatureSettings: '"tnum" 1',
  fontVariantNumeric: 'tabular-nums',
  paddingLeft: '4.5rem',
  paddingRight: '1rem',
};

export const SELECT_BASE: ForceStyles = {
  ...INPUT_BASE,
  paddingLeft: '2.5rem',
  cursor: 'pointer',
  backgroundImage:
    "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%236366f1'%3E%3Cpath fill-rule='evenodd' d='M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 111.08 1.04l-4.25 4.39a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z' clip-rule='evenodd'/%3E%3C/svg%3E\")",
  backgroundRepeat: 'no-repeat',
  backgroundPosition: 'left 0.75rem center',
  backgroundSize: '1rem',
};

export const TEXTAREA_BASE: ForceStyles = {
  ...INPUT_BASE,
  minHeight: '80px',
  lineHeight: '1.8',
  resize: 'vertical',
};

export const DP_TRIGGER_BASE: ForceStyles = {
  ...INPUT_BASE,
  cursor: 'pointer',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'space-between',
  gap: '0.5rem',
};
