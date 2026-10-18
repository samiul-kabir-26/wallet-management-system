import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth';

const ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'] as const;
const WARNING_SECONDS = 30;
const CHECK_INTERVAL_MS = 1000;
// The server only counts API calls as activity, so tell it about real use at most this often
const KEEP_ALIVE_INTERVAL_MS = 60_000;

/**
 * Signs the user out after a period without interaction, using the timeout the server issued
 * for this panel. Activity is shared through localStorage between tabs of the same panel only,
 * so a busy agent tab never keeps an idle admin tab signed in, and signing out in one tab of a
 * panel signs out its other tabs.
 */
export function useIdleLogout() {
    const authStore = useAuthStore();
    const secondsLeft = ref<number | null>(null);
    let timer: ReturnType<typeof setInterval> | null = null;
    let lastKeepAlive = Date.now();
    let loggingOut = false;

    // Read only while signed in to a panel, so the key is always set when used
    const activityKey = (): string => authStore.activityStorageKey ?? 'last_activity_at';
    // Last activity seen by this tab; used when storage is unavailable or the shared value is missing
    let localLastActivity = Date.now();

    const readLastActivity = (): number => {
        try {
            const stored = Number(localStorage.getItem(activityKey()));
            return Number.isFinite(stored) && stored > 0 ? stored : localLastActivity;
        } catch {
            return localLastActivity;
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
        localLastActivity = now;
        try {
            localStorage.setItem(activityKey(), String(now));
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

    // Another tab of this panel signed out (or was signed out): follow it instead of waiting for a 401
    const onStorage = (event: StorageEvent): void => {
        if (event.key === authStore.tokenStorageKey && event.newValue === null && authStore.token && !loggingOut) {
            loggingOut = true;
            authStore.clearAuth();
            router.visit('/login');
        }
    };

    onMounted(() => {
        try {
            const stored = Number(localStorage.getItem(activityKey()));
            if (Number.isFinite(stored) && stored > 0) {
                localLastActivity = stored;
            } else {
                recordActivity();
            }
        } catch {
            // Storage unavailable: start the timer from now
        }
        ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, markActive, { passive: true }));
        window.addEventListener('storage', onStorage);
        timer = setInterval(check, CHECK_INTERVAL_MS);
        check();
    });

    onBeforeUnmount(() => {
        ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, markActive));
        window.removeEventListener('storage', onStorage);
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
