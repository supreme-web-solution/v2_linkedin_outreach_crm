import { onBeforeUnmount, ref } from 'vue';

const POSITION_KEY = 'command-center-widget-position';

type WidgetPosition = {
    right: number;
    bottom: number;
};

const EDGE_MARGIN = 20;

const DEFAULT_POSITION: WidgetPosition = { right: EDGE_MARGIN, bottom: EDGE_MARGIN };

function clamp(value: number, min: number, max: number): number {
    return Math.min(Math.max(value, min), max);
}

function loadPosition(): WidgetPosition {
    if (typeof window === 'undefined') {
        return { ...DEFAULT_POSITION };
    }

    try {
        const raw = localStorage.getItem(POSITION_KEY);
        if (!raw) {
            return { ...DEFAULT_POSITION };
        }

        const parsed = JSON.parse(raw) as Partial<WidgetPosition & { left?: number }>;

        if (typeof parsed.right === 'number' && typeof parsed.bottom === 'number') {
            return {
                right: parsed.right === 24 ? EDGE_MARGIN : parsed.right,
                bottom: parsed.bottom >= 80 ? EDGE_MARGIN : parsed.bottom,
            };
        }

        // Migrate older left-based saves to bottom-right default.
        if (typeof parsed.left === 'number' && typeof parsed.bottom === 'number') {
            return { ...DEFAULT_POSITION };
        }
    } catch {
        // ignore invalid storage
    }

    return { ...DEFAULT_POSITION };
}

function savePosition(position: WidgetPosition) {
    try {
        localStorage.setItem(POSITION_KEY, JSON.stringify(position));
    } catch {
        // ignore quota / privacy mode
    }
}

export function useDraggableWidget(launcherSize = 56) {
    const position = ref<WidgetPosition>(loadPosition());
    const dragging = ref(false);

    let startX = 0;
    let startY = 0;
    let originRight = 0;
    let originBottom = 0;
    let moved = false;

    function clampToViewport(right: number, bottom: number): WidgetPosition {
        const maxRight = Math.max(EDGE_MARGIN, window.innerWidth - launcherSize - 8);
        const maxBottom = Math.max(EDGE_MARGIN, window.innerHeight - launcherSize - 8);

        return {
            right: clamp(right, EDGE_MARGIN, maxRight),
            bottom: clamp(bottom, EDGE_MARGIN, maxBottom),
        };
    }

    function onPointerMove(event: PointerEvent) {
        if (!dragging.value) return;

        const deltaX = event.clientX - startX;
        const deltaY = startY - event.clientY;

        if (Math.abs(deltaX) > 6 || Math.abs(deltaY) > 6) {
            moved = true;
        }

        position.value = clampToViewport(originRight - deltaX, originBottom + deltaY);
    }

    function onPointerUp() {
        if (!dragging.value) return;

        dragging.value = false;
        savePosition(position.value);

        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
    }

    function onLauncherPointerDown(event: PointerEvent) {
        if (event.button !== 0) return;

        dragging.value = true;
        moved = false;
        startX = event.clientX;
        startY = event.clientY;
        originRight = position.value.right;
        originBottom = position.value.bottom;

        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);

        event.preventDefault();
    }

    function didDrag(): boolean {
        return moved;
    }

    function resetDragFlag() {
        moved = false;
    }

    onBeforeUnmount(() => {
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
    });

    return {
        position,
        dragging,
        onLauncherPointerDown,
        didDrag,
        resetDragFlag,
    };
}
