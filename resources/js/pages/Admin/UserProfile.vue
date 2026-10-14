<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';
import Pagination from '@/components/Pagination.vue';
import Skeleton from '@/components/Skeleton.vue';
import ConfirmModal from '@/components/Modals/ConfirmModal.vue';
import TransactionLedger from '@/components/Admin/TransactionLedger.vue';
import type { AuditLogRecord, LoginActivityRecord, Pagination as PaginationType, UserProfile } from '@/types/models';

const props = defineProps<{ userId: number }>();

useAuthGuard('ADMIN', 'users.view');

const authStore = useAuthStore();
const toast = useToastStore();

const profile = ref<UserProfile | null>(null);
const loading = ref(true);
const errorMessage = ref<string | null>(null);

type Tab = 'transactions' | 'logins' | 'audit';
const tabs = computed(() =>
    [
        { key: 'transactions' as Tab, label: 'Transactions', visible: authStore.can('transactions.view_any') },
        { key: 'logins' as Tab, label: 'Login Records', visible: authStore.can('users.view_activity') },
        { key: 'audit' as Tab, label: 'Audit Trail', visible: authStore.can('users.view_activity') },
    ].filter((tab) => tab.visible),
);
const activeTab = ref<Tab | null>(null);

const logins = ref<LoginActivityRecord[]>([]);
const loginsPagination = ref<PaginationType | null>(null);
const audits = ref<AuditLogRecord[]>([]);
const auditsPagination = ref<PaginationType | null>(null);
const activityLoading = ref(false);

const showVerifyConfirm = ref(false);
const verifying = ref(false);

const canVerifyPhone = computed(
    () =>
        !!profile.value &&
        authStore.can('users.verify_phone') &&
        !profile.value.is_verified &&
        !!profile.value.phone_number &&
        profile.value.id !== authStore.user?.id,
);

const fetchProfile = async () => {
    loading.value = true;
    errorMessage.value = null;
    try {
        const res = await api.get(`/users/${props.userId}`);
        profile.value = res.data.data?.user ?? null;
    } catch (err: unknown) {
        errorMessage.value = parseApiError(err, 'Failed to load this user.').message;
    } finally {
        loading.value = false;
    }
};

const fetchLogins = async (page = 1) => {
    activityLoading.value = true;
    try {
        const res = await api.get(`/users/${props.userId}/login-activity`, { params: { page, per_page: 15 } });
        logins.value = res.data.data?.items ?? [];
        loginsPagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load login records.').message);
    } finally {
        activityLoading.value = false;
    }
};

const fetchAudits = async (page = 1) => {
    activityLoading.value = true;
    try {
        const res = await api.get(`/users/${props.userId}/audit-trail`, { params: { page, per_page: 15 } });
        audits.value = res.data.data?.items ?? [];
        auditsPagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load the audit trail.').message);
    } finally {
        activityLoading.value = false;
    }
};

watch(activeTab, (tab) => {
    if (tab === 'logins') fetchLogins();
    if (tab === 'audit') fetchAudits();
});

const verifyPhone = async () => {
    verifying.value = true;
    try {
        const res = await api.patch(`/users/${props.userId}/verify-phone`);
        profile.value = { ...profile.value, ...res.data.data?.user };
        toast.success('Phone number marked as verified.');
        showVerifyConfirm.value = false;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to verify the phone number.').message);
    } finally {
        verifying.value = false;
    }
};

const money = (value: number | string | undefined | null): string => `৳${Number(value ?? 0).toFixed(2)}`;
const when = (value: string): string => new Date(value).toLocaleString();
const label = (value: string): string => value.replaceAll('_', ' ');
const describeMetadata = (metadata: Record<string, unknown> | null): string =>
    metadata
        ? Object.entries(metadata)
              .filter(([, value]) => value !== null && value !== '' && typeof value !== 'object')
              .map(([key, value]) => `${label(key)}: ${value}`)
              .join(' · ')
        : '';

onMounted(async () => {
    await fetchProfile();
    activeTab.value = tabs.value[0]?.key ?? null;
});
</script>

<template>
    <Head :title="`${profile?.name ?? 'User'} - WalletMS Admin`" />
    <AdminLayout>
        <div class="space-y-6">
            <Link href="/admin/wallets" class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                ← Back to wallets
            </Link>

            <Alert v-if="errorMessage" type="error" :message="errorMessage" />

            <div v-if="loading" class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <Skeleton :rows="6" height="h-6" />
            </div>

            <template v-else-if="profile">
                <!-- Header -->
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">{{ profile.name }}</h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <Badge v-for="role in profile.roles" :key="role" variant="info">{{ role }}</Badge>
                            <Badge :variant="profile.is_active === 'ACTIVE' ? 'success' : 'danger'">{{ profile.is_active }}</Badge>
                            <Badge :variant="profile.is_verified ? 'success' : 'warning'">
                                {{ profile.is_verified ? 'Phone verified' : 'Phone not verified' }}
                            </Badge>
                        </div>
                    </div>
                    <button
                        v-if="canVerifyPhone"
                        type="button"
                        class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-emerald-500"
                        @click="showVerifyConfirm = true"
                    >
                        Mark Phone Verified
                    </button>
                </div>

                <!-- Details -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Profile</h2>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-zinc-500">User ID</dt><dd class="font-mono">#{{ profile.id }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-zinc-500">Phone</dt><dd>{{ profile.phone_number ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-zinc-500">Email</dt><dd class="truncate">{{ profile.email ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-zinc-500">Address</dt><dd class="text-right">{{ profile.address ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-zinc-500">Joined</dt><dd>{{ profile.created_at ? when(profile.created_at) : '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Wallets</h2>
                        <p v-if="!profile.wallets?.length" class="mt-4 text-sm text-zinc-500">No wallet (staff-only account).</p>
                        <ul v-else class="mt-4 space-y-3">
                            <li v-for="wallet in profile.wallets" :key="wallet.id" class="flex items-center justify-between text-sm">
                                <div>
                                    <div class="font-medium">#{{ wallet.id }} · {{ wallet.kind ?? 'USER' }}</div>
                                    <Badge :variant="wallet.is_blocked ? 'danger' : 'success'">{{ wallet.is_blocked ? 'FROZEN' : 'ACTIVE' }}</Badge>
                                </div>
                                <div class="text-right font-semibold">{{ money(wallet.balance) }}</div>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Limits &amp; Agent</h2>
                        <dl class="mt-4 space-y-3 text-sm">
                            <template v-if="profile.caps">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Daily cap</dt><dd>{{ money(profile.caps.daily_cap) }}</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Monthly cap</dt><dd>{{ money(profile.caps.monthly_cap) }}</dd>
                                </div>
                            </template>
                            <template v-if="profile.agent_info">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Agent status</dt><dd><Badge variant="warning">{{ profile.agent_info.status }}</Badge></dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Commission rate</dt>
                                    <dd>{{ (Number(profile.agent_info.commission_rate) * 100).toFixed(2) }}%</dd>
                                </div>
                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Total commission</dt><dd>{{ money(profile.agent_info.total_commission) }}</dd>
                                </div>
                            </template>
                            <p v-if="!profile.caps && !profile.agent_info" class="text-zinc-500">No limits or agent profile.</p>
                        </dl>
                    </div>
                </div>

                <!-- Activity tabs -->
                <div v-if="tabs.length" class="space-y-4">
                    <div class="flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-zinc-800 w-fit max-w-full overflow-x-auto">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            type="button"
                            class="whitespace-nowrap rounded-lg px-4 py-2 text-xs font-semibold transition"
                            :class="
                                activeTab === tab.key
                                    ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-900 dark:text-zinc-100'
                                    : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400'
                            "
                            @click="activeTab = tab.key"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <TransactionLedger v-if="activeTab === 'transactions'" :party-id="profile.id" />

                    <div
                        v-else-if="activeTab === 'logins'"
                        class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden"
                    >
                        <div v-if="activityLoading && logins.length === 0" class="p-6"><Skeleton :rows="5" height="h-6" /></div>
                        <div v-else-if="logins.length === 0" class="p-12 text-center text-sm text-zinc-500">No login attempts recorded yet.</div>
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                                    <tr>
                                        <th class="px-6 py-3.5">When</th>
                                        <th class="px-6 py-3.5">Panel</th>
                                        <th class="px-6 py-3.5">Result</th>
                                        <th class="px-6 py-3.5">IP Address</th>
                                        <th class="px-6 py-3.5">Device</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    <tr v-for="login in logins" :key="login.id">
                                        <td class="px-6 py-4 text-xs text-zinc-500 whitespace-nowrap">{{ when(login.created_at) }}</td>
                                        <td class="px-6 py-4 text-xs uppercase">{{ login.panel }}</td>
                                        <td class="px-6 py-4">
                                            <Badge :variant="login.status === 'SUCCESS' ? 'success' : 'danger'">{{ login.status }}</Badge>
                                            <div v-if="login.failure_reason" class="mt-1 text-xs text-zinc-500">{{ login.failure_reason }}</div>
                                        </td>
                                        <td class="px-6 py-4 font-mono text-xs">{{ login.ip_address ?? '—' }}</td>
                                        <td class="px-6 py-4 max-w-xs truncate text-xs text-zinc-500" :title="login.user_agent ?? ''">
                                            {{ login.user_agent ?? '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <Pagination :pagination="loginsPagination" :loading="activityLoading" @change-page="fetchLogins" />
                    </div>

                    <div
                        v-else-if="activeTab === 'audit'"
                        class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden"
                    >
                        <div v-if="activityLoading && audits.length === 0" class="p-6"><Skeleton :rows="5" height="h-6" /></div>
                        <div v-else-if="audits.length === 0" class="p-12 text-center text-sm text-zinc-500">No audit entries for this account.</div>
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                                    <tr>
                                        <th class="px-6 py-3.5">When</th>
                                        <th class="px-6 py-3.5">Action</th>
                                        <th class="px-6 py-3.5">Performed By</th>
                                        <th class="px-6 py-3.5">Details</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    <tr v-for="entry in audits" :key="entry.id">
                                        <td class="px-6 py-4 text-xs text-zinc-500 whitespace-nowrap">{{ when(entry.created_at) }}</td>
                                        <td class="px-6 py-4"><Badge variant="neutral">{{ label(entry.action) }}</Badge></td>
                                        <td class="px-6 py-4 text-xs">
                                            {{ entry.actor ? `${entry.actor.name} (#${entry.actor.id})` : 'System' }}
                                            <span v-if="entry.actor?.id === profile.id" class="text-zinc-400">· self</span>
                                        </td>
                                        <td class="px-6 py-4 text-xs text-zinc-500">{{ describeMetadata(entry.metadata) || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <Pagination :pagination="auditsPagination" :loading="activityLoading" @change-page="fetchAudits" />
                    </div>
                </div>
            </template>
        </div>

        <ConfirmModal
            :open="showVerifyConfirm"
            title="Mark phone as verified?"
            confirm-label="Mark Verified"
            :loading="verifying"
            @confirm="verifyPhone"
            @cancel="showVerifyConfirm = false"
        >
            Confirm that you have checked <strong>{{ profile?.phone_number }}</strong> belongs to {{ profile?.name }} (for example by
            calling the number or checking their NID). This is recorded in the audit trail under your name.
        </ConfirmModal>
    </AdminLayout>
</template>
