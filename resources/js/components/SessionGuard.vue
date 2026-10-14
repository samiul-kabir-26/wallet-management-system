<script setup lang="ts">
import { ref } from 'vue';
import Modal from '@/components/Modal.vue';
import ConfirmModal from '@/components/Modals/ConfirmModal.vue';
import { useIdleLogout } from '@/composables/useIdleLogout';
import { useAuthStore } from '@/stores/auth';

/**
 * Idle auto-logout warning plus the "Log out?" confirmation, shared by all three panel layouts.
 * Layouts call the exposed confirmLogout() from their sign-out buttons.
 */
const authStore = useAuthStore();
const { secondsLeft, isWarning, staySignedIn } = useIdleLogout();

const showLogoutConfirm = ref(false);
const loggingOut = ref(false);

const confirmLogout = (): void => {
    showLogoutConfirm.value = true;
};

const logout = async (): Promise<void> => {
    loggingOut.value = true;
    try {
        await authStore.logout();
    } finally {
        loggingOut.value = false;
        showLogoutConfirm.value = false;
    }
};

defineExpose({ confirmLogout });
</script>

<template>
    <div>
        <ConfirmModal
            :open="showLogoutConfirm"
            title="Log out?"
            confirm-label="Log out"
            :loading="loggingOut"
            @confirm="logout"
            @cancel="showLogoutConfirm = false"
        >
            You will need to sign in again to continue.
        </ConfirmModal>

        <Modal :show="isWarning" title="Are you still there?" @close="staySignedIn">
            <div class="space-y-4">
                <p class="text-sm text-zinc-700 dark:text-zinc-300">
                    For your security you will be signed out in
                    <span class="font-semibold text-rose-600 dark:text-rose-400">{{ secondsLeft }}s</span>
                    because of inactivity.
                </p>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="logout"
                    >
                        Log out now
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
                        @click="staySignedIn"
                    >
                        Stay signed in
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
