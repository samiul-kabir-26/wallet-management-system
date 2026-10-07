import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth';

export type RequiredRole = 'USER' | 'AGENT' | 'ADMIN';

export function useAuthGuard(requiredRole?: RequiredRole) {
    const authStore = useAuthStore();
    const isReady = ref(false);

    onMounted(() => {
        if (!authStore.isAuthenticated) {
            router.visit('/login');
            return;
        }

        if (requiredRole) {
            let hasPermission = false;
            if (requiredRole === 'ADMIN' && authStore.isAdmin) {
                hasPermission = true;
            } else if (requiredRole === 'AGENT' && authStore.isAgent) {
                hasPermission = true;
            } else if (requiredRole === 'USER' && authStore.isUser) {
                hasPermission = true;
            }

            if (!hasPermission) {
                // Redirect user to their own proper dashboard
                if (authStore.isAdmin) {
                    router.visit('/admin/dashboard');
                } else if (authStore.isAgent) {
                    router.visit('/agent/dashboard');
                } else {
                    router.visit('/user/dashboard');
                }
                return;
            }
        }

        isReady.value = true;
    });

    return {
        authStore,
        isReady,
    };
}
