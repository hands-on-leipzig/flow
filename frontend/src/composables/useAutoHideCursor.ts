import {onMounted, onUnmounted, Ref, watch} from "vue";

export function useAutoHideCursor(targetRef: Ref<HTMLElement>, delay = 3000) {
    let timer;

    function resetTimer() {
        const el = targetRef.value;
        if (!el) return;

        el.classList.remove("hide-cursor");

        clearTimeout(timer);
        timer = setTimeout(() => {
            el.classList.add("hide-cursor");
        }, delay);
    }

    onMounted(addListener);

    watch(targetRef, (newEl, oldEl) => {
        if (oldEl) {
            oldEl.addEventListener("mousemove", resetTimer);
            oldEl.addEventListener("pointerenter", resetTimer);
        }
        if (newEl) {
            addListener();
        }
    });

    function addListener() {
        const el = targetRef.value;
        if (!el) return;

        el.addEventListener("pointermove", resetTimer);
        el.addEventListener("pointerenter", resetTimer);
        resetTimer();
    }

    onUnmounted(() => {
        const el = targetRef.value;
        if (!el) return;

        el.removeEventListener("pointermove", resetTimer);
        el.removeEventListener("pointerenter", resetTimer);
        clearTimeout(timer);
    });
}