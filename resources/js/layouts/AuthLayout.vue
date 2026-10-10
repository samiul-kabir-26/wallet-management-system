<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ToastContainer from '@/components/ToastContainer.vue';

defineProps<{
    title?: string;
    subtitle?: string;
}>();
</script>

<template>
    <div class="flex min-h-screen flex-col justify-center bg-zinc-50 px-4 py-12 sm:px-6 lg:px-8 dark:bg-zinc-950">
        <ToastContainer />
        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <div class="flex justify-center">
                <Link href="/" class="flex items-center gap-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white font-bold shadow-md">
                        ৳
                    </div>
                    <span>WalletMS</span>
                </Link>
            </div>
            <h2 v-if="title" class="mt-6 text-center text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                {{ title }}
            </h2>
            <p v-if="subtitle" class="mt-2 text-center text-sm text-zinc-600 dark:text-zinc-400">
                {{ subtitle }}
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="rounded-2xl border border-zinc-200/80 bg-white p-8 shadow-xl shadow-zinc-200/40 dark:border-zinc-800 dark:bg-zinc-900 dark:shadow-none">
                <slot />
            </div>
        </div>
    </div>
</template>
