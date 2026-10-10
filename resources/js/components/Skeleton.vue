<script setup lang="ts">
withDefaults(
    defineProps<{
        rows?: number;
        height?: string;
    }>(),
    { rows: 1, height: 'h-4' },
);
</script>

<template>
    <div class="space-y-3" aria-busy="true" aria-label="Loading">
        <div
            v-for="n in rows"
            :key="n"
            class="animate-pulse rounded-md bg-zinc-200 dark:bg-zinc-800"
            :class="[height, n === rows && rows > 1 ? 'w-2/3' : 'w-full']"
        />
    </div>
</template>
