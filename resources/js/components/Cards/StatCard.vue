<script setup lang="ts">
import Skeleton from '@/components/Skeleton.vue';

defineProps<{
    label: string;
    value?: string | number;
    hint?: string;
    loading?: boolean;
}>();
</script>

<template>
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ label }}</p>
        <Skeleton v-if="loading" class="mt-3" height="h-7" />
        <p v-else class="mt-2 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ value }}</p>
        <p v-if="hint && !loading" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ hint }}</p>
    </div>
</template>
