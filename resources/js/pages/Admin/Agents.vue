<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { useForm } from 'vee-validate';
import { rate } from '@/composables/validators';
import { api } from '@/services/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import TextField from '@/components/Forms/TextField.vue';
import ConfirmModal from '@/components/Modals/ConfirmModal.vue';
import Skeleton from '@/components/Skeleton.vue';
import { useToastStore } from '@/stores/toast';
import type { User } from '@/types/models';

useAuthGuard('ADMIN');

const agents = ref<User[]>([]);
const loading = ref(true);
const toast = useToastStore();

// Approve Agent modal
const showApproveModal = ref(false);
const selectedAgent = ref<User | null>(null);
const approveLoading = ref(false);
const suspendTarget = ref<User | null>(null);
const suspendLoading = ref(false);

const { handleSubmit, setValues, values } = useForm<{ commissionRate: string }>({
    validationSchema: { commissionRate: rate('Commission rate') },
    initialValues: { commissionRate: '0.015' },
});

const fetchAgents = async () => {
    loading.value = true;
    try {
        const res = await api.get('/users/all-users', {
            params: { per_page: 50 },
        });
        const allUsers: User[] = res.data.data?.items ?? [];
        agents.value = allUsers.filter((u) => u.roles.includes('AGENT'));
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        toast.error(apiError.response?.data?.message || 'Failed to fetch agents.');
    } finally {
        loading.value = false;
    }
};

const openApproveModal = (agent: User) => {
    selectedAgent.value = agent;
    setValues({ commissionRate: '0.015' });
    showApproveModal.value = true;
};

const handleApproveAgent = handleSubmit(async (formValues) => {
    if (!selectedAgent.value) return;
    approveLoading.value = true;
    try {
        await api.patch(`/users/${selectedAgent.value.id}/approve-agent`, {
            commission_rate: Number(formValues.commissionRate),
        });
        toast.success(`Agent ${selectedAgent.value.name} successfully approved!`);
        showApproveModal.value = false;
        await fetchAgents();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        toast.error(apiError.response?.data?.message || 'Failed to approve agent.');
    } finally {
        approveLoading.value = false;
    }
});

const handleSuspendAgent = async () => {
    const agent = suspendTarget.value;
    if (!agent) return;
    suspendLoading.value = true;
    try {
        await api.patch(`/users/${agent.id}/suspend-agent`);
        toast.success(`Agent ${agent.name} has been suspended.`);
        suspendTarget.value = null;
        await fetchAgents();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        toast.error(apiError.response?.data?.message || 'Failed to suspend agent.');
    } finally {
        suspendLoading.value = false;
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


            <!-- Agents Table -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div v-if="loading && agents.length === 0" class="p-6"><Skeleton :rows="6" height="h-6" /></div>

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
                                        @click="suspendTarget = a"
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
                <form class="space-y-4" novalidate @submit.prevent="handleApproveAgent">
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Approve <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ selectedAgent?.name }}</span> and assign their cash-out commission rate.
                    </p>

                    <div>
                        <TextField name="commissionRate" label="Commission Rate (Decimal format, e.g. 0.015 = 1.5%)" type="number" step="0.001" min="0" inputmode="decimal" placeholder="0.015" />
                        <p class="mt-1 text-xs text-zinc-500">
                            Effective rate: {{ (Number(values.commissionRate) * 100).toFixed(1) }}% of cash-out system fee.
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

            <ConfirmModal
                :open="!!suspendTarget"
                title="Suspend Agent"
                confirm-label="Suspend"
                :loading="suspendLoading"
                @cancel="suspendTarget = null"
                @confirm="handleSuspendAgent"
            >
                Are you sure you want to suspend agent <strong>{{ suspendTarget?.name }}</strong>? They will no longer be able to take part in transactions.
            </ConfirmModal>
        </div>
    </AdminLayout>
</template>
