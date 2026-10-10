<script setup lang="ts">
import Modal from '@/components/Modal.vue';

defineProps<{
    open: boolean;
    title: string;
    confirmLabel?: string;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'confirm'): void;
    (e: 'cancel'): void;
}>();
</script>

<template>
    <Modal :show="open" :title="title" @close="emit('cancel')">
        <div class="space-y-4">
            <div class="text-sm text-zinc-700 dark:text-zinc-300">
                <slot />
            </div>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    :disabled="loading"
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="emit('cancel')"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    :disabled="loading"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                    @click="emit('confirm')"
                >
                    {{ loading ? 'Processing...' : (confirmLabel ?? 'Confirm') }}
                </button>
            </div>
        </div>
    </Modal>
</template>
