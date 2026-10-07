<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { api } from '@/services/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Alert from '@/components/Alert.vue';
import type { User } from '@/types/models';

useAuthGuard('ADMIN');

const agents = ref<User[]>([]);
const loading = ref(true);
const successMessage = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

// Approve Agent modal
const showApproveModal = ref(false);
const selectedAgent = ref<User | null>(null);
const commissionRate = ref<number>(0.015); // Default 1.5%
const approveLoading = ref(false);

const fetchAgents = async () => {
    loading.value = true;
    errorMessage.value = null;
    try {
        const res = await api.get('/users/all-users', {
            params: { per_page: 50 },
        });
        const allUsers: User[] = res.data.data?.items ?? [];
        agents.value = allUsers.filter((u) => u.roles.includes('AGENT'));
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to fetch agents.';
    } finally {
        loading.value = false;
    }
};

const openApproveModal = (agent: User) => {
    selectedAgent.value = agent;
    commissionRate.value = 0.015;
    showApproveModal.value = true;
};

const handleApproveAgent = async () => {
    if (!selectedAgent.value) return;
    approveLoading.value = true;
    errorMessage.value = null;
    try {
        await api.patch(`/users/${selectedAgent.value.id}/approve-agent`, {
            commission_rate: Number(commissionRate.value),
        });
        successMessage.value = `Agent ${selectedAgent.value.name} successfully approved!`;
        showApproveModal.value = false;
        await fetchAgents();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to approve agent.';
    } finally {
        approveLoading.value = false;
    }
};

const handleSuspendAgent = async (agent: User) => {
    if (!confirm(`Are you sure you want to suspend agent ${agent.name}?`)) return;
    loading.value = true;
    errorMessage.value = null;
    try {
        await api.patch(`/users/${agent.id}/suspend-agent`);
        successMessage.value = `Agent ${agent.name} has been suspended.`;
        await fetchAgents();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        errorMessage.value = apiError.response?.data?.message || 'Failed to suspend agent.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchAgents();
});
</script>

<template>
    <Head title="Agent Approvals - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                    Agent Verification & Management
                </h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Review pending agent applications, configure commission rates, or suspend profiles
                </p>
            </div>

            <div v-if="successMessage" class="mb-4">
                <Alert type="success" :message="successMessage" dismissible @close="successMessage = null" />
            </div>
            <div v-if="errorMessage" class="mb-4">
                <Alert type="error" :message="errorMessage" dismissible @close="errorMessage = null" />
            </div>

            <!-- Agents Table -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div v-if="loading && agents.length === 0" class="p-12 text-center text-sm text-zinc-500">
                    Loading agent directory...
                </div>

                <div v-else-if="agents.length === 0" class="p-12 text-center text-sm text-zinc-500">
                    No agents currently registered in the system.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">Agent</th>
                                <th class="px-6 py-3.5">Phone Number</th>
                                <th class="px-6 py-3.5">Agent Status</th>
                                <th class="px-6 py-3.5">Commission Rate</th>
                                <th class="px-6 py-3.5">Total Commission</th>
                                <th class="px-6 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="a in agents" :key="a.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ a.name }}
                                </td>
                                <td class="px-6 py-4 text-xs font-mono text-zinc-600 dark:text-zinc-400">
                                    {{ a.phone_number }}
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="a.agent_info?.status === 'APPROVED' ? 'success' : a.agent_info?.status === 'SUSPENDED' ? 'danger' : 'warning'">
                                        {{ a.agent_info?.status ?? 'PENDING' }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 text-xs font-semibold text-amber-600 dark:text-amber-400">
                                    {{ a.agent_info?.commission_rate ? (Number(a.agent_info.commission_rate) * 100).toFixed(1) + '%' : 'Default' }}
                                </td>
                                <td class="px-6 py-4 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                    ৳{{ Number(a.agent_info?.total_commission ?? 0).toFixed(2) }}
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button
                                        v-if="a.agent_info?.status !== 'APPROVED'"
                                        type="button"
                                        class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500 shadow-xs"
                                        @click="openApproveModal(a)"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        v-if="a.agent_info?.status === 'APPROVED'"
                                        type="button"
                                        class="rounded-md bg-rose-50 border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-400"
                                        @click="handleSuspendAgent(a)"
                                    >
                                        Suspend
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Approve Agent Modal -->
            <Modal :show="showApproveModal" title="Approve Financial Agent" @close="showApproveModal = false">
                <form class="space-y-4" @submit.prevent="handleApproveAgent">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Approve <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ selectedAgent?.name }}</span> and assign their cash-out commission rate.
                    </p>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Commission Rate (Decimal format, e.g. 0.015 = 1.5%)</label>
                        <input
                            v-model="commissionRate"
                            type="number"
                            step="0.001"
                            min="0"
                            max="0.5"
                            required
                            placeholder="0.015"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                        <p class="mt-1 text-xs text-zinc-500">
                            Effective rate: {{ (Number(commissionRate) * 100).toFixed(1) }}% of cash-out system fee.
                        </p>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="showApproveModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="approveLoading"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                        >
                            {{ approveLoading ? 'Approving...' : 'Confirm Approval' }}
                        </button>
                    </div>
                </form>
            </Modal>
        </div>
    </AdminLayout>
</template>
