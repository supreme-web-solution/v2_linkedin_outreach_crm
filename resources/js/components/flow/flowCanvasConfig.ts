export const FLOW_CANVAS_DOT_BG = {
    variant: 'dots' as const,
    gap: 20,
    size: 2.4,
    offset: 10,
    patternColor: '#94a3b8',
} as const;

/** Keeps sparse sequences from filling the viewport — zoom out with breathing room. */
export const FLOW_CANVAS_FIT_VIEW = {
    padding: 0.45,
    maxZoom: 0.68,
    minZoom: 0.25,
    duration: 0,
} as const;
