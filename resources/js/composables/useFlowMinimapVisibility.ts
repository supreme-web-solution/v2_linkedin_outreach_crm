import { onMounted, ref } from 'vue';

/**
 * Persist canvas overview (minimap) visibility per surface.
 */
export function useFlowMinimapVisibility(storageKey: string, defaultVisible = true) {
    const showMinimap = ref(defaultVisible);

    onMounted(() => {
        if (typeof window === 'undefined') return;
        try {
            const stored = localStorage.getItem(storageKey);
            if (stored === '0') showMinimap.value = false;
            if (stored === '1') showMinimap.value = true;
        } catch {
            // ignore private mode
        }
    });

    function setMinimapVisible(visible: boolean) {
        showMinimap.value = visible;
        try {
            localStorage.setItem(storageKey, visible ? '1' : '0');
        } catch {
            // ignore
        }
    }

    function toggleMinimap() {
        setMinimapVisible(!showMinimap.value);
    }

    return { showMinimap, setMinimapVisible, toggleMinimap };
}
