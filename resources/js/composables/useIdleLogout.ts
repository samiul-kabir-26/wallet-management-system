import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAuthStore } from '@/stores/auth';

const ACTIVITY_KEY = 'last_activity_at';
const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'] as const;
const WARNING_SECONDS = 30;
const CHECK_INTERVAL_MS = 1000;
// The server only counts API calls as activity, so tell it about real use at most this often
const KEEP_ALIVE_INTERVAL_MS = 60_000;

/**
 * Signs the user out after a period without interaction, using the timeout the server issued
 * for this panel. Activity is shared across tabs through localStorage, so a busy tab keeps
 * idle tabs signed in and signing out in one tab is noticed by the others.
 */
export function useIdleLogout() {
    const authStore = useAuthStore();
    const secondsLeft = ref<number | null>(null);
    let timer: ReturnType<typeof setInterval> | null = null;
    let lastKeepAlive = Date.now();
    let loggingOut = false;

    const readLastActivity = (): number => {
        try {
            const stored = Number(localStorage.getItem(ACTIVITY_KEY));
            return Number.isFinite(stored) && stored > 0 ? stored : Date.now();
        } catch {
            return Date.now();
        }
    };

    const markActive = (): void => {
        // Once the warning is showing, only the explicit "stay signed in" button counts
        if (secondsLeft.value !== null || !authStore.token) {
            return;
        }
        recordActivity();
    };

    const recordActivity = (): void => {
        const now = Date.now();
        try {
            localStorage.setItem(ACTIVITY_KEY, String(now));
        } catch {
            // Storage unavailable: the per-tab check below still works
        }
        if (now - lastKeepAlive >= KEEP_ALIVE_INTERVAL_MS) {
            lastKeepAlive = now;
            void authStore.fetchMe();
        }
    };

    const staySignedIn = (): void => {
        secondsLeft.value = null;
        lastKeepAlive = 0;
        recordActivity();
    };

    const check = (): void => {
        const timeoutMinutes = authStore.idleTimeoutMinutes;
        if (!authStore.token || !timeoutMinutes || loggingOut) {
            secondsLeft.value = null;
            return;
        }

        const remainingMs = readLastActivity() + timeoutMinutes * 60_000 - Date.now();

        if (remainingMs <= 0) {
            loggingOut = true;
            secondsLeft.value = null;
            void authStore.logout('idle');
            return;
        }

        const warnAtMs = Math.min(WARNING_SECONDS * 1000, (timeoutMinutes * 60_000) / 2);
        secondsLeft.value = remainingMs <= warnAtMs ? Math.ceil(remainingMs / 1000) : null;
    };

    onMounted(() => {
        if (!localStorage.getItem(ACTIVITY_KEY)) {
            recordActivity();
        }
        ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, markActive, { passive: true }));
        timer = setInterval(check, CHECK_INTERVAL_MS);
        check();
    });

    onBeforeUnmount(() => {
        ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, markActive));
        if (timer) {
            clearInterval(timer);
        }
    });

    return {
        secondsLeft,
        isWarning: computed(() => secondsLeft.value !== null),
        staySignedIn,
    };
}
