<script setup lang="ts">
import { useToastStore } from '@/stores/toast';
import Alert from '@/components/Alert.vue';

const toastStore = useToastStore();
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6"
        aria-live="polite"
    >
        <TransitionGroup name="toast">
            <div v-for="toast in toastStore.toasts" :key="toast.id" class="pointer-events-auto w-full max-w-sm">
                <Alert :type="toast.type" :message="toast.message" dismissible @close="toastStore.dismiss(toast.id)" />
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 0.2s ease;
}
.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>
