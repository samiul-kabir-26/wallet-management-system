<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        type?: 'success' | 'error' | 'warning' | 'info';
        message?: string;
        dismissible?: boolean;
    }>(),
    {
        type: 'info',
        message: '',
        dismissible: false,
    },
);

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const classes = computed(() => {
    switch (props.type) {
        case 'success':
            return 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800';
        case 'error':
            return 'bg-rose-50 text-rose-800 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800';
        case 'warning':
            return 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800';
        case 'info':
        default:
            return 'bg-sky-50 text-sky-800 border-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:border-sky-800';
    }
});
</script>

<template>
    <div
        v-if="message || $slots.default"
        class="flex items-center justify-between rounded-lg border p-4 text-sm font-medium shadow-xs"
        :class="classes"
    >
        <div class="flex items-center gap-2">
            <slot>{{ message }}</slot>
        </div>
        <button
            v-if="dismissible"
            type="button"
            class="ml-3 inline-flex text-current opacity-70 transition hover:opacity-100"
            @click="emit('close')"
        >
            <span class="sr-only">Close</span>
            ✕
        </button>
    </div>
</template>
