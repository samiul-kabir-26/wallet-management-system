<script setup lang="ts">
defineProps<{
    show: boolean;
    title?: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();
</script>

<template>
    <div
        v-if="show"
        class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/60 p-4 transition-opacity backdrop-blur-xs"
        @click.self="emit('close')"
    >
        <div
            class="relative w-full max-w-lg transform overflow-hidden rounded-xl bg-white p-6 text-left shadow-2xl transition-all dark:bg-zinc-900 dark:border dark:border-zinc-800"
        >
            <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                <h3 v-if="title" class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                    {{ title }}
                </h3>
                <button
                    type="button"
                    class="rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    @click="emit('close')"
                >
                    <span class="sr-only">Close</span>
                    ✕
                </button>
            </div>

            <div class="mt-4">
                <slot />
            </div>

            <div v-if="$slots.footer" class="mt-6 flex justify-end gap-3 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>
