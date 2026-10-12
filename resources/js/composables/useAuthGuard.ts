import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import type { PermissionName } from '@/types/permissions';

export type RequiredRole = 'USER' | 'AGENT' | 'ADMIN';

export function useAuthGuard(requiredRole?: RequiredRole, requiredPermission?: PermissionName) {
    const authStore = useAuthStore();
    const isReady = ref(false);

    onMounted(() => {
        if (!authStore.isAuthenticated) {
            router.visit('/login');
            return;
        }

        if (requiredRole) {
            let hasRoleAccess = false;
            if (requiredRole === 'ADMIN' && authStore.isAdmin) {
                hasRoleAccess = true;
            } else if (requiredRole === 'AGENT' && authStore.isAgent) {
                hasRoleAccess = true;
            } else if (requiredRole === 'USER' && authStore.isUser) {
                hasRoleAccess = true;
            }

            if (!hasRoleAccess) {
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

        if (requiredPermission && !authStore.can(requiredPermission)) {
            const toast = useToastStore();
            toast.error(`Access denied: You do not have the '${requiredPermission}' permission.`);
            router.visit('/admin/dashboard');
            return;
        }

        isReady.value = true;
    });

    return {
        authStore,
        isReady,
    };
}
