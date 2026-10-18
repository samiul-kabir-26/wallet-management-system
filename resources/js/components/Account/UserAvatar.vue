<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        name?: string | null;
        src?: string | null;
        size?: 'sm' | 'lg';
    }>(),
    { name: '', src: null, size: 'sm' },
);

const initials = computed(() =>
    (props.name ?? '')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('') || '?',
);

const sizeClasses = computed(() => (props.size === 'lg' ? 'h-20 w-20 text-2xl' : 'h-8 w-8 text-xs'));
</script>

<template>
    <img
        v-if="src"
        :src="src"
        :alt="`${name ?? 'User'} profile photo`"
        class="shrink-0 rounded-full object-cover ring-2 ring-white dark:ring-zinc-900"
        :class="sizeClasses"
    />
    <span
        v-else
        class="inline-flex shrink-0 items-center justify-center rounded-full bg-indigo-100 font-semibold text-indigo-700 ring-2 ring-white dark:bg-indigo-900/60 dark:text-indigo-200 dark:ring-zinc-900"
        :class="sizeClasses"
        aria-hidden="true"
    >
        {{ initials }}
    </span>
</template>
