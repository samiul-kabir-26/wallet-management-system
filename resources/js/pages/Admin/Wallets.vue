<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import Alert from '@/components/Alert.vue';
import type { Pagination as PaginationType, Wallet } from '@/types/models';

useAuthGuard('ADMIN');

const wallets = ref<Wallet[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);
const successMessage = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

// Block/Unblock state
const showBlockModal = ref(false);
const showUnblockModal = ref(false);
const selectedWallet = ref<Wallet | null>(null);
const actionReason = ref('');
const actionLoading = ref(false);

const fetchWallets = async (page = 1) => {
    loading.value = true;
    errorMessage.value = null;
    try {
        const res = await api.get('/wallets/admin/all', {
            params: { page, per_page: 15 },
        });
        wallets.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to fetch wallets.';
    } finally {
        loading.value = false;
    }
};

const openBlockModal = (w: Wallet) => {
    selectedWallet.value = w;
    actionReason.value = 'Suspicious velocity detected';
    showBlockModal.value = true;
};

const openUnblockModal = (w: Wallet) => {
    selectedWallet.value = w;
    actionReason.value = 'Identity verification completed';
    showUnblockModal.value = true;
};

const handleBlock = async () => {
    if (!selectedWallet.value) return;
    actionLoading.value = true;
    errorMessage.value = null;
    try {
        await api.patch(`/wallets/${selectedWallet.value.id}/block`, {
            reason: actionReason.value,
        });
        successMessage.value = `Wallet #${selectedWallet.value.id} successfully blocked.`;
        showBlockModal.value = false;
        await fetchWallets(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to block wallet.';
    } finally {
        actionLoading.value = false;
    }
};

const handleUnblock = async () => {
    if (!selectedWallet.value) return;
    actionLoading.value = true;
    errorMessage.value = null;
    try {
        await api.patch(`/wallets/${selectedWallet.value.id}/unblock`, {
            reason: actionReason.value,
        });
        successMessage.value = `Wallet #${selectedWallet.value.id} successfully unblocked.`;
        showUnblockModal.value = false;
        await fetchWallets(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to unblock wallet.';
    } finally {
        actionLoading.value = false;
    }
};

onMounted(() => {
    fetchWallets();
});
</script>

<template>
    <Head title="Wallet Management - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Wallet Supervision & Security Freeze
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Monitor balances and freeze accounts suspected of fraudulent activity
                </p>
            </div>

            <div v-if="successMessage" class="mb-4">
                <Alert type="success" :message="successMessage" dismissible @close="successMessage = null" />
            </div>
            <div v-if="errorMessage" class="mb-4">
                <Alert type="error" :message="errorMessage" dismissible @close="errorMessage = null" />
            </div>

            <!-- Wallets Table -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div v-if="loading && wallets.length === 0" class="p-12 text-center text-sm text-zinc-500">
                    Loading wallets...
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">Wallet ID</th>
                                <th class="px-6 py-3.5">User</th>
                                <th class="px-6 py-3.5">Balance</th>
                                <th class="px-6 py-3.5">Status</th>
                                <th class="px-6 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="w in wallets" :key="w.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-mono text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                    #{{ w.id }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ w.user?.name ?? `User #${w.user_id}` }}
                                    </div>
                                    <div class="text-xs text-zinc-500">
                                        {{ w.user?.phone_number ?? '' }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-semibold text-zinc-900 dark:text-zinc-100">
                                    ৳{{ Number(w.balance).toFixed(2) }} {{ w.currency }}
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="w.is_blocked ? 'danger' : 'success'">
                                        {{ w.is_blocked ? 'BLOCKED' : 'ACTIVE' }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button
                                        v-if="!w.is_blocked"
                                        type="button"
                                        class="rounded-md bg-rose-50 border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-400"
                                        @click="openBlockModal(w)"
                                    >
                                        Freeze Wallet
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="rounded-md bg-emerald-50 border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-600 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-400"
                                        @click="openUnblockModal(w)"
                                    >
                                        Unfreeze
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :pagination="pagination" :loading="loading" @change-page="fetchWallets" />
            </div>

            <!-- Block Modal -->
            <Modal :show="showBlockModal" title="Security Freeze Wallet" @close="showBlockModal = false">
                <form class="space-y-4" @submit.prevent="handleBlock">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Freeze Wallet <span class="font-semibold text-zinc-900 dark:text-zinc-100">#{{ selectedWallet?.id }}</span>. All transfers, cash-ins, and cash-outs on this account will be prohibited immediately.
                    </p>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Reason for Freeze</label>
                        <input
                            v-model="actionReason"
                            type="text"
                            required
                            placeholder="e.g. Velocity anomaly / Chargeback risk"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="showBlockModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="actionLoading"
                            class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
                        >
                            {{ actionLoading ? 'Freezing...' : 'Confirm Freeze' }}
                        </button>
                    </div>
                </form>
            </Modal>

            <!-- Unblock Modal -->
            <Modal :show="showUnblockModal" title="Unfreeze Wallet" @close="showUnblockModal = false">
                <form class="space-y-4" @submit.prevent="handleUnblock">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Restore active state for Wallet <span class="font-semibold text-zinc-900 dark:text-zinc-100">#{{ selectedWallet?.id }}</span>.
                    </p>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Reason for Unfreeze</label>
                        <input
                            v-model="actionReason"
                            type="text"
                            required
                            placeholder="e.g. Customer KYC cleared"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="showUnblockModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="actionLoading"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                        >
                            {{ actionLoading ? 'Unfreezing...' : 'Confirm Unfreeze' }}
                        </button>
                    </div>
                </form>
            </Modal>
        </div>
    </AdminLayout>
</template>
