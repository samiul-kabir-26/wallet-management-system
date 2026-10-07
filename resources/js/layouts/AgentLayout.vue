<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth';
import Badge from '@/components/Badge.vue';

const authStore = useAuthStore();
const mobileMenuOpen = ref(false);

const navItems = [
    { name: 'Dashboard', href: '/agent/dashboard' },
    { name: 'Transactions', href: '/agent/transactions' },
    { name: 'Bank Withdrawal', href: '/agent/withdrawal' },
];

const handleLogout = async () => {
    await authStore.logout();
};
</script>

<template>
    <div class="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 flex flex-col">
        <!-- Top Navbar -->
        <header class="sticky top-0 z-40 border-b border-zinc-200 bg-white/80 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/80">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-8">
                    <Link href="/agent/dashboard" class="flex items-center gap-2 text-xl font-bold tracking-tight text-amber-600 dark:text-amber-400">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-amber-600 text-white font-bold">
                            A
                        </div>
                        <span class="text-zinc-900 dark:text-zinc-100">WalletMS Agent</span>
                    </Link>

                    <nav class="hidden md:flex items-center gap-1">
                        <Link
                            v-for="item in navItems"
                            :key="item.href"
                            :href="item.href"
                            class="rounded-lg px-3 py-2 text-sm font-medium transition hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                        >
                            {{ item.name }}
                        </Link>
                    </nav>
                </div>

                <div class="hidden md:flex items-center gap-4">
                    <div class="flex items-center gap-2 text-sm">
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">
                            {{ authStore.user?.name ?? 'Agent' }}
                        </span>
                        <Badge variant="warning">Agent</Badge>
                    </div>

                    <button
                        type="button"
                        class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-rose-50 hover:border-rose-200 hover:text-rose-600 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-rose-950/40 dark:hover:text-rose-400"
                        @click="handleLogout"
                    >
                        Sign Out
                    </button>
                </div>

                <!-- Mobile menu button -->
                <div class="md:hidden flex items-center gap-2">
                    <button
                        type="button"
                        class="p-2 rounded-lg text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        @click="mobileMenuOpen = !mobileMenuOpen"
                    >
                        <span class="sr-only">Toggle Menu</span>
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path v-if="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile menu dropdown -->
            <div v-if="mobileMenuOpen" class="md:hidden border-t border-zinc-200 px-4 pt-2 pb-4 space-y-1 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="block rounded-lg px-3 py-2 text-base font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="mobileMenuOpen = false"
                >
                    {{ item.name }}
                </Link>
                <div class="border-t border-zinc-200 pt-3 dark:border-zinc-800 flex items-center justify-between px-3">
                    <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        {{ authStore.user?.name ?? 'Agent' }}
                    </div>
                    <button
                        type="button"
                        class="text-xs font-semibold text-rose-600 dark:text-rose-400"
                        @click="handleLogout"
                    >
                        Sign Out
                    </button>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>
    </div>
</template>
