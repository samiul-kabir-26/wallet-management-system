<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth';
import Badge from '@/components/Badge.vue';

const authStore = useAuthStore();
const sidebarOpen = ref(false);

const navItems = [
    { name: 'Dashboard', href: '/admin/dashboard', icon: '📊' },
    { name: 'Users & Staff', href: '/admin/users', icon: '👥' },
    { name: 'Agent Approvals', href: '/admin/agents', icon: '🤝' },
    { name: 'Wallets & Freeze', href: '/admin/wallets', icon: '💳' },
    { name: 'Platform Transactions', href: '/admin/transactions', icon: '📜' },
    { name: 'System Settings', href: '/admin/settings', icon: '⚙️' },
];

const handleLogout = async () => {
    await authStore.logout();
};
</script>

<template>
    <div class="min-h-screen bg-zinc-50 dark:bg-zinc-950 text-zinc-900 dark:text-zinc-100 flex">
        <!-- Desktop Sidebar -->
        <aside class="hidden lg:flex lg:w-64 lg:flex-col border-r border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex h-16 items-center gap-2 px-6 border-b border-zinc-200 dark:border-zinc-800">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-rose-600 text-white font-bold">
                    🛡️
                </div>
                <div class="flex flex-col">
                    <span class="font-bold tracking-tight text-zinc-900 dark:text-zinc-100">WalletMS Admin</span>
                    <span class="text-[10px] uppercase font-semibold text-zinc-400 tracking-wider">Management Console</span>
                </div>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-4">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-zinc-100"
                >
                    <span class="text-base">{{ item.icon }}</span>
                    <span>{{ item.name }}</span>
                </Link>
            </nav>

            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                <div class="flex items-center justify-between">
                    <div class="flex flex-col">
                        <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                            {{ authStore.user?.name ?? 'Admin' }}
                        </span>
                        <span class="text-[10px] text-zinc-400 truncate max-w-[120px]">
                            {{ authStore.user?.email ?? authStore.user?.phone_number ?? '' }}
                        </span>
                    </div>
                    <Badge variant="danger">{{ authStore.roles[0] ?? 'ADMIN' }}</Badge>
                </div>
            </div>
        </aside>

        <!-- Main Body Area -->
        <div class="flex flex-1 flex-col overflow-hidden">
            <!-- Topbar -->
            <header class="flex h-16 items-center justify-between border-b border-zinc-200 bg-white/80 px-4 sm:px-6 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/80">
                <div class="flex items-center gap-3 lg:hidden">
                    <button
                        type="button"
                        class="p-2 rounded-lg text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
                        @click="sidebarOpen = !sidebarOpen"
                    >
                        <span class="sr-only">Toggle Sidebar</span>
                        ☰
                    </button>
                    <span class="font-bold text-zinc-900 dark:text-zinc-100">WalletMS Admin</span>
                </div>

                <div class="hidden lg:block">
                    <span class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">Administrative Control Panel</span>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-semibold text-zinc-600 transition hover:bg-rose-50 hover:border-rose-200 hover:text-rose-600 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-rose-950/40 dark:hover:text-rose-400"
                        @click="handleLogout"
                    >
                        Sign Out
                    </button>
                </div>
            </header>

            <!-- Mobile Drawer -->
            <div
                v-if="sidebarOpen"
                class="fixed inset-0 z-50 flex lg:hidden bg-black/60 backdrop-blur-xs"
                @click.self="sidebarOpen = false"
            >
                <div class="w-64 bg-white p-4 shadow-xl dark:bg-zinc-900 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between pb-4 border-b border-zinc-200 dark:border-zinc-800">
                            <span class="font-bold text-zinc-900 dark:text-zinc-100">Admin Console</span>
                            <button type="button" @click="sidebarOpen = false">✕</button>
                        </div>
                        <nav class="space-y-1 mt-4">
                            <Link
                                v-for="item in navItems"
                                :key="item.href"
                                :href="item.href"
                                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                @click="sidebarOpen = false"
                            >
                                <span>{{ item.icon }}</span>
                                <span>{{ item.name }}</span>
                            </Link>
                        </nav>
                    </div>

                    <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800">
                        <button
                            type="button"
                            class="w-full text-left text-sm font-semibold text-rose-600 dark:text-rose-400"
                            @click="handleLogout"
                        >
                            Sign Out
                        </button>
                    </div>
                </div>
            </div>

            <!-- Content Area -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
