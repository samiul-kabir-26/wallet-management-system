<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Alert from '@/components/Alert.vue';

const { authStore } = useAuthGuard('ADMIN');

const totalUsers = ref<number | null>(null);
const totalWallets = ref<number | null>(null);
const totalTransactions = ref<number | null>(null);
const feeRate = ref<number | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const fetchOverview = async () => {
    loading.value = true;
    error.value = null;
    try {
        const [usersRes, walletsRes, txRes, settingsRes] = await Promise.all([
            api.get('/users/all-users', { params: { per_page: 1 } }),
            api.get('/wallets/admin/all', { params: { per_page: 1 } }),
            api.get('/transactions/admin/all', { params: { per_page: 1 } }),
            api.get('/system-settings'),
        ]);

        totalUsers.value = usersRes.data.data?.pagination?.total ?? 0;
        totalWallets.value = walletsRes.data.data?.pagination?.total ?? 0;
        totalTransactions.value = txRes.data.data?.pagination?.total ?? 0;
        feeRate.value = settingsRes.data.data?.settings?.transaction_fee_rate ?? 0.05;
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        error.value = apiError.response?.data?.message || 'Failed to load system overview.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchOverview();
});
</script>

<template>
    <Head title="Admin Dashboard - WalletMS" />
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Administrative Overview
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    System health, customer activity, and financial configurations
                </p>
            </div>

            <div v-if="error" class="mb-4">
                <Alert type="error" :message="error" />
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Total Users -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Registered Accounts</span>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-3xl font-extrabold text-zinc-900 dark:text-zinc-100">
                            {{ totalUsers ?? '...' }}
                        </span>
                    </div>
                    <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 text-xs">
                        <Link href="/admin/users" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                            Manage Users & Staff →
                        </Link>
                    </div>
                </div>

                <!-- Total Wallets -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Platform Wallets</span>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-3xl font-extrabold text-zinc-900 dark:text-zinc-100">
                            {{ totalWallets ?? '...' }}
                        </span>
                    </div>
                    <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 text-xs">
                        <Link href="/admin/wallets" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                            Manage Wallets & Freeze →
                        </Link>
                    </div>
                </div>

                <!-- Total Transactions -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Total Transactions</span>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-3xl font-extrabold text-zinc-900 dark:text-zinc-100">
                            {{ totalTransactions ?? '...' }}
                        </span>
                    </div>
                    <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 text-xs">
                        <Link href="/admin/transactions" class="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                            Audit Ledger →
                        </Link>
                    </div>
                </div>

                <!-- Fee Rate -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">System Fee Rate</span>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-3xl font-extrabold text-rose-600 dark:text-rose-400">
                            {{ feeRate !== null ? (feeRate * 100).toFixed(1) + '%' : '...' }}
                        </span>
                    </div>
                    <div class="mt-4 pt-3 border-t border-zinc-100 dark:border-zinc-800 text-xs">
                        <Link href="/admin/settings" class="font-semibold text-rose-600 hover:text-rose-500 dark:text-rose-400">
                            Configure Settings →
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Quick Management Shortcuts -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100 mb-4">Operations & Quick Actions</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <Link
                        href="/admin/agents"
                        class="rounded-xl border border-zinc-200 p-4 hover:border-indigo-500 hover:bg-zinc-50 transition dark:border-zinc-800 dark:hover:bg-zinc-800"
                    >
                        <div class="text-lg">🤝</div>
                        <div class="font-semibold text-sm mt-2">Agent Verification</div>
                        <p class="text-xs text-zinc-500 mt-1">Approve pending agents and configure individual commission rates.</p>
                    </Link>

                    <Link
                        href="/admin/wallets"
                        class="rounded-xl border border-zinc-200 p-4 hover:border-indigo-500 hover:bg-zinc-50 transition dark:border-zinc-800 dark:hover:bg-zinc-800"
                    >
                        <div class="text-lg">🧊</div>
                        <div class="font-semibold text-sm mt-2">Security Freeze</div>
                        <p class="text-xs text-zinc-500 mt-1">Freeze or unfreeze customer wallets suspected of velocity abuse.</p>
                    </Link>

                    <Link
                        href="/admin/users"
                        class="rounded-xl border border-zinc-200 p-4 hover:border-indigo-500 hover:bg-zinc-50 transition dark:border-zinc-800 dark:hover:bg-zinc-800"
                    >
                        <div class="text-lg">🛡️</div>
                        <div class="font-semibold text-sm mt-2">Staff Invitation</div>
                        <p class="text-xs text-zinc-500 mt-1">Elevate existing users to administrative roles with signed invites.</p>
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
