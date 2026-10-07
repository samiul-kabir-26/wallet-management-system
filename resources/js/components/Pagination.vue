<script setup lang="ts">
import type { Pagination } from '@/types/models';

defineProps<{
    pagination?: Pagination | null;
    loading?: boolean;
}>();

const emit = defineEmits<{
    (e: 'change-page', page: number): void;
}>();
</script>

<template>
    <div
        v-if="pagination && pagination.total > 0"
        class="flex items-center justify-between border-t border-zinc-200 px-4 py-3 sm:px-6 dark:border-zinc-800"
    >
        <div class="flex flex-1 justify-between sm:hidden">
            <button
                type="button"
                :disabled="pagination.current_page <= 1 || loading"
                class="relative inline-flex items-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                @click="emit('change-page', pagination.current_page - 1)"
            >
                Previous
            </button>
            <button
                type="button"
                :disabled="pagination.current_page >= pagination.last_page || loading"
                class="relative ml-3 inline-flex items-center rounded-md border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                @click="emit('change-page', pagination.current_page + 1)"
            >
                Next
            </button>
        </div>
        <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-zinc-700 dark:text-zinc-400">
                    Showing
                    <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ pagination.from ?? 0 }}</span>
                    to
                    <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ pagination.to ?? 0 }}</span>
                    of
                    <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ pagination.total }}</span>
                    results
                </p>
            </div>
            <div>
                <nav class="isolate inline-flex -space-x-px rounded-md shadow-xs" aria-label="Pagination">
                    <button
                        type="button"
                        :disabled="pagination.current_page <= 1 || loading"
                        class="relative inline-flex items-center rounded-l-md px-3 py-2 text-zinc-400 ring-1 ring-zinc-300 ring-inset hover:bg-zinc-50 focus:z-20 disabled:opacity-40 dark:ring-zinc-700 dark:hover:bg-zinc-800"
                        @click="emit('change-page', pagination.current_page - 1)"
                    >
                        <span>Previous</span>
                    </button>
                    <span
                        class="relative inline-flex items-center px-4 py-2 text-sm font-semibold text-zinc-900 ring-1 ring-zinc-300 ring-inset dark:text-zinc-200 dark:ring-zinc-700"
                    >
                        Page {{ pagination.current_page }} of {{ pagination.last_page }}
                    </span>
                    <button
                        type="button"
                        :disabled="pagination.current_page >= pagination.last_page || loading"
                        class="relative inline-flex items-center rounded-r-md px-3 py-2 text-zinc-400 ring-1 ring-zinc-300 ring-inset hover:bg-zinc-50 focus:z-20 disabled:opacity-40 dark:ring-zinc-700 dark:hover:bg-zinc-800"
                        @click="emit('change-page', pagination.current_page + 1)"
                    >
                        <span>Next</span>
                    </button>
                </nav>
            </div>
        </div>
    </div>
</template>
