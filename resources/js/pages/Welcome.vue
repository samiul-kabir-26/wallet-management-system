<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useAuthStore } from '@/stores/auth';

const authStore = useAuthStore();
</script>

<template>
    <Head title="Wallet Management System">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <div class="min-h-screen bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100 flex flex-col justify-between">
        <!-- Header -->
        <header class="border-b border-zinc-200 bg-white/80 px-6 py-4 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-900/80">
            <div class="mx-auto flex max-w-7xl items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 font-bold text-white shadow-md">
                        ৳
                    </div>
                    <span class="text-xl font-bold tracking-tight">WalletMS</span>
                </div>

                <div class="flex items-center gap-3">
                    <template v-if="authStore.isAuthenticated">
                        <Link
                            v-if="authStore.isAdmin"
                            href="/admin/dashboard"
                            class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-rose-500"
                        >
                            Go to Admin Dashboard →
                        </Link>
                        <Link
                            v-else-if="authStore.isAgent"
                            href="/agent/dashboard"
                            class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-amber-500"
                        >
                            Go to Agent Dashboard →
                        </Link>
                        <Link
                            v-else
                            href="/user/dashboard"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500"
                        >
                            Go to Customer Dashboard →
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            href="/login"
                            class="rounded-lg px-4 py-2 text-sm font-semibold text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100"
                        >
                            Sign In
                        </Link>
                        <Link
                            href="/register"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500"
                        >
                            Get Started
                        </Link>
                    </template>
                </div>
            </div>
        </header>

        <!-- Hero Section -->
        <main class="mx-auto max-w-5xl px-6 py-16 text-center">
            <div class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/50 dark:text-indigo-300 mb-6">
                <span>Enterprise Fintech Platform</span>
            </div>

            <h1 class="text-4xl font-extrabold tracking-tight sm:text-6xl text-zinc-900 dark:text-zinc-100">
                Next-Generation Digital Wallet Management
            </h1>

            <p class="mx-auto mt-6 max-w-2xl text-base text-zinc-600 sm:text-lg dark:text-zinc-400">
                Secure, concurrent transactions with rigorous regulatory caps, multi-track authorization, and instant settlement across Customer, Agent, and Administrator portals.
            </p>

            <div class="mt-10 flex flex-wrap justify-center gap-4">
                <Link
                    href="/login"
                    class="rounded-xl bg-indigo-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-600/25 hover:bg-indigo-500 transition"
                >
                    Launch Web Portal →
                </Link>
                <Link
                    href="/register"
                    class="rounded-xl border border-zinc-300 bg-white px-6 py-3.5 text-sm font-semibold text-zinc-800 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800 transition"
                >
                    Create Customer / Agent Account
                </Link>
            </div>

            <!-- Portal Cards -->
            <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-6 text-left">
                <!-- Customer Card -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-400 font-bold mb-4">
                        👤
                    </div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Customer Portal</h3>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Top-up funds, send peer-to-peer transfers, deposit or withdraw via agents, with live cap monitoring (10k daily / 50k monthly).
                    </p>
                    <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                        <Link href="/login" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                            Customer Login →
                        </Link>
                    </div>
                </div>

                <!-- Agent Card -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400 font-bold mb-4">
                        🏪
                    </div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Agent Portal</h3>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Serve customer cash-in and cash-out requests, earn commissions on fees, and execute secure bank balance withdrawals.
                    </p>
                    <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                        <Link href="/login" class="text-xs font-semibold text-amber-600 hover:text-amber-500 dark:text-amber-400">
                            Agent Login →
                        </Link>
                    </div>
                </div>

                <!-- Admin Card -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-rose-100 text-rose-600 dark:bg-rose-950 dark:text-rose-400 font-bold mb-4">
                        🛡️
                    </div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Admin Console</h3>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Supervise accounts, approve agents with custom commission rates, freeze wallets, inspect global ledgers, and manage fee rates.
                    </p>
                    <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                        <Link href="/login" class="text-xs font-semibold text-rose-600 hover:text-rose-500 dark:text-rose-400">
                            Staff Sign In →
                        </Link>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-zinc-200 bg-white py-6 text-center text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto max-w-7xl px-4">
                Wallet Management System — Production Ready Laravel 12 + Inertia.js v3 + Vue 3 SPA
            </div>
        </footer>
    </div>
</template>
