<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        variant?: 'success' | 'danger' | 'warning' | 'info' | 'neutral';
        size?: 'sm' | 'md';
    }>(),
    {
        variant: 'neutral',
        size: 'sm',
    },
);

const classes = computed(() => {
    const sizeClasses =
        props.size === 'sm' ? 'px-2.5 py-0.5 text-xs' : 'px-3 py-1 text-sm';

    let colorClasses = '';
    switch (props.variant) {
        case 'success':
            colorClasses =
                'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300';
            break;
        case 'danger':
            colorClasses =
                'bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300';
            break;
        case 'warning':
            colorClasses =
                'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300';
            break;
        case 'info':
            colorClasses =
                'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-300';
            break;
        case 'neutral':
        default:
            colorClasses =
                'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-300';
            break;
    }

    return `${sizeClasses} ${colorClasses} inline-flex items-center font-medium rounded-full`;
});
</script>

<template>
    <span :class="classes">
        <slot />
    </span>
</template>
