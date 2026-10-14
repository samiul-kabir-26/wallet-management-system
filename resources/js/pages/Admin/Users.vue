<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { useAuthStore } from '@/stores/auth';
import { api } from '@/services/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import Alert from '@/components/Alert.vue';
import Skeleton from '@/components/Skeleton.vue';
import RegisterStaffForm from '@/components/Forms/RegisterStaffForm.vue';
import { useToastStore } from '@/stores/toast';
import type { Pagination as PaginationType, RoleModel, User } from '@/types/models';

useAuthGuard('ADMIN', 'users.view_any');

const authStore = useAuthStore();
const users = ref<User[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);
const toast = useToastStore();

// Register Staff modal
const showRegisterModal = ref(false);

// Elevate User modal
const showElevateModal = ref(false);
const elevateUser = ref<User | null>(null);
const elevateRole = ref<string>('ADMIN');
const elevateEmail = ref<string>('');
const elevateError = ref<string | null>(null);
const availableAdminRoles = ref<RoleModel[]>([]);
const elevateLoading = ref(false);
const elevateSuccess = ref(false);

const fetchAdminRoles = async () => {
    try {
        const res = await api.get('/roles', { params: { panel: 'admin', per_page: 100 } });
        availableAdminRoles.value = res.data.data?.items ?? [];
        if (availableAdminRoles.value.length > 0 && !availableAdminRoles.value.some((r) => r.name === elevateRole.value)) {
            elevateRole.value = availableAdminRoles.value[0].name;
        }
    } catch {
        availableAdminRoles.value = [{ id: 0, name: 'ADMIN', description: 'Administrator', panel: 'admin', is_system: true }];
    }
};

const fetchUsers = async (page = 1) => {
    loading.value = true;
    try {
        const res = await api.get('/users/all-users', {
            params: { page, per_page: 15 },
        });
        users.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        toast.error(apiError.response?.data?.message || 'Failed to fetch users.');
    } finally {
        loading.value = false;
    }
};

const handleStaffRegistered = async () => {
    showRegisterModal.value = false;
    await fetchUsers(1);
};

const openElevateModal = (u: User) => {
    elevateUser.value = u;
    elevateEmail.value = u.email ?? '';
    elevateError.value = null;
    elevateSuccess.value = false;
    showElevateModal.value = true;
};

const handleElevateUser = async () => {
    if (!elevateUser.value) return;
    if (!elevateEmail.value.trim()) {
        elevateError.value = 'A valid staff email address is required.';
        return;
    }
    elevateLoading.value = true;
    elevateError.value = null;
    try {
        await api.patch(`/users/${elevateUser.value.id}/grant-admin-access`, {
            role: elevateRole.value,
            email: elevateEmail.value.trim(),
        });

        elevateSuccess.value = true;
        toast.success(`Admin access granted to ${elevateUser.value.name}!`);
        await fetchUsers(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        const errData = apiError.response?.data;
        const msg = errData?.message || (errData?.errors ? Object.values(errData.errors).flat().join(' ') : 'Failed to grant admin access.');
        elevateError.value = msg;
        toast.error(msg);
    } finally {
        elevateLoading.value = false;
    }
};

onMounted(() => {
    fetchUsers();
    fetchAdminRoles();
});
</script>

<template>
    <Head title="User Management - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        User & Staff Directory
                    </h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Manage customers, agents, and administrative permissions
                    </p>
                </div>
                <button
                    v-if="authStore.can('users.create')"
                    type="button"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 transition"
                    @click="showRegisterModal = true"
                >
                    + Register Staff
                </button>
            </div>

            <!-- Table Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div v-if="loading && users.length === 0" class="p-6"><Skeleton :rows="6" height="h-6" /></div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">ID</th>
                                <th class="px-6 py-3.5">Name</th>
                                <th class="px-6 py-3.5">Phone / Email</th>
                                <th class="px-6 py-3.5">Role</th>
                                <th class="px-6 py-3.5">Status</th>
                                <th class="px-6 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="u in users" :key="u.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-mono text-xs text-zinc-500">#{{ u.id }}</td>
                                <td class="px-6 py-4 font-semibold text-zinc-900 dark:text-zinc-100">{{ u.name }}</td>
                                <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-400">
                                    <div>
                                        {{ u.phone_number ?? 'No phone' }}
                                        <span
                                            v-if="u.phone_number"
                                            :class="u.is_verified ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"
                                        >
                                            · {{ u.is_verified ? 'verified' : 'unverified' }}
                                        </span>
                                    </div>
                                    <div class="text-zinc-400">{{ u.email ?? '' }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="u.roles.includes('SUPER_ADMIN') ? 'danger' : u.roles.includes('ADMIN') ? 'danger' : u.roles.includes('AGENT') ? 'warning' : 'info'">
                                        {{ u.roles[0] ?? 'USER' }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="u.is_active === 'ACTIVE' ? 'success' : 'danger'">
                                        {{ u.is_active ?? 'ACTIVE' }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 text-right space-x-3 whitespace-nowrap">
                                    <Link
                                        v-if="authStore.can('users.view')"
                                        :href="`/admin/users/${u.id}`"
                                        class="text-xs font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    >
                                        View Profile
                                    </Link>
                                    <button
                                        v-if="authStore.can('users.grant_admin_access') && !u.roles.includes('ADMIN') && !u.roles.includes('SUPER_ADMIN')"
                                        type="button"
                                        class="text-xs font-semibold text-rose-600 hover:text-rose-500 dark:text-rose-400"
                                        @click="openElevateModal(u)"
                                    >
                                        Elevate to Admin
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :pagination="pagination" :loading="loading" @change-page="fetchUsers" />
            </div>

            <!-- Register Staff Modal -->
            <Modal :show="showRegisterModal" title="Register New Administrative Staff" @close="showRegisterModal = false">
                <RegisterStaffForm @cancel="showRegisterModal = false" @registered="handleStaffRegistered" />
            </Modal>

            <!-- Elevate to Admin Modal (Case B) -->
            <Modal :show="showElevateModal" title="Grant Administrative Access" @close="showElevateModal = false">
                <div v-if="elevateSuccess" class="space-y-4">
                    <Alert type="success" message="Admin invitation created successfully!" />
                    <p class="text-xs text-zinc-600 dark:text-zinc-400">
                        A secure one-time invitation link and temporary credentials have been sent to the user's email inbox (<strong>{{ elevateEmail }}</strong>). The user must accept the invite to set their administrative password.
                    </p>
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                            @click="showElevateModal = false"
                        >
                            Done
                        </button>
                    </div>
                </div>

                <div v-else class="space-y-4">
                    <Alert v-if="elevateError" type="error" :message="elevateError" />

                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Are you sure you want to elevate
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ elevateUser?.name }}</span>
                        ({{ elevateUser?.phone_number }}) to administrative staff?
                    </p>

                    <Alert
                        v-if="elevateUser && !elevateUser.is_verified"
                        type="warning"
                        message="This user's phone number is not verified yet. Open their profile and use 'Mark Phone Verified' after confirming their identity, then grant access."
                    />

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Staff Email Address</label>
                        <input
                            v-model="elevateEmail"
                            type="email"
                            placeholder="staff@wallet.local"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            required
                        />
                        <p class="mt-1 text-xs text-zinc-500">The temporary password and one-time invitation link will be associated with this email address.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Assign Role</label>
                        <select
                            v-model="elevateRole"
                            class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        >
                            <option v-for="r in availableAdminRoles" :key="r.id" :value="r.name">
                                {{ r.name }}{{ r.description ? ` (${r.description})` : '' }}
                            </option>
                        </select>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="showElevateModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="elevateLoading"
                            class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
                            @click="handleElevateUser"
                        >
                            {{ elevateLoading ? 'Generating Invite...' : 'Confirm & Elevate' }}
                        </button>
                    </div>
                </div>
            </Modal>
        </div>
    </AdminLayout>
</template>
