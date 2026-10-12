<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { api } from '@/services/api';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import ConfirmModal from '@/components/Modals/ConfirmModal.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { PermissionCatalogItem, RoleModel } from '@/types/models';

useAuthGuard('ADMIN', 'roles.view');

const authStore = useAuthStore();
const toast = useToastStore();

const roles = ref<RoleModel[]>([]);
const catalog = ref<Record<string, PermissionCatalogItem[]>>({});
const allPermissions = ref<PermissionCatalogItem[]>([]);
const loading = ref<boolean>(true);

// Modals
const showCreateModal = ref<boolean>(false);
const showEditModal = ref<boolean>(false);
const showDeleteModal = ref<boolean>(false);

// Create form state
const createName = ref<string>('');
const createDescription = ref<string>('');
const createSelectedPermissions = ref<Set<string>>(new Set());
const createSubmitting = ref<boolean>(false);
const createNameError = ref<string | null>(null);

// Edit form state
const selectedRole = ref<RoleModel | null>(null);
const editInitialPermissions = ref<Set<string>>(new Set());
const editSelectedPermissions = ref<Set<string>>(new Set());
const editSubmitting = ref<boolean>(false);

// Delete state
const deleteTarget = ref<RoleModel | null>(null);
const deleteSubmitting = ref<boolean>(false);

const fetchData = async () => {
    loading.value = true;
    try {
        const [rolesRes, permsRes] = await Promise.all([
            api.get('/roles'),
            api.get('/permissions'),
        ]);

        roles.value = rolesRes.data.data?.roles ?? [];
        catalog.value = permsRes.data.data?.grouped ?? {};
        allPermissions.value = permsRes.data.data?.permissions ?? [];
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        toast.error(apiError.response?.data?.message || 'Failed to load roles and permissions.');
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchData();
});

// Helper to determine if a role can be edited/deleted by the current user
const canEditRole = (role: RoleModel): boolean => {
    if (!authStore.can('roles.manage')) {
        return false;
    }
    if (role.is_system) {
        return false;
    }
    if (authStore.roles.includes(role.name)) {
        return false; // R4: cannot edit a role you currently hold
    }
    return true;
};

// Create Modal actions
const openCreateModal = () => {
    createName.value = '';
    createDescription.value = '';
    createSelectedPermissions.value = new Set();
    createNameError.value = null;
    showCreateModal.value = true;
};

const validateCreateName = (): boolean => {
    const val = createName.value.trim();
    if (!val) {
        createNameError.value = 'Role name is required.';
        return false;
    }
    if (!/^[A-Z0-9_-]{2,50}$/.test(val)) {
        createNameError.value = 'Name must be 2-50 uppercase letters, numbers, hyphens, or underscores.';
        return false;
    }
    createNameError.value = null;
    return true;
};

const handleCreateRole = async () => {
    if (!validateCreateName()) {
        return;
    }

    createSubmitting.value = true;
    try {
        await api.post('/roles', {
            name: createName.value.trim().toUpperCase(),
            description: createDescription.value.trim() || null,
            permissions: Array.from(createSelectedPermissions.value).filter((name) => !isSuperAdminOnly(name)),
        });

        toast.success(`Role '${createName.value.trim().toUpperCase()}' created successfully!`);
        showCreateModal.value = false;
        await fetchData();
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
        const msg = apiError.response?.data?.message || 'Failed to create role.';
        toast.error(msg);
    } finally {
        createSubmitting.value = false;
    }
};

// Edit / Manage Permissions Modal
const openEditModal = async (role: RoleModel) => {
    try {
        // Fetch detailed role permissions
        const res = await api.get(`/roles/${role.id}`);
        const roleData: RoleModel = res.data.data?.role ?? role;
        selectedRole.value = roleData;

        const permNames = (roleData.permissions ?? [])
            .map((p) => p.name)
            .filter((name) => !isSuperAdminOnly(name));
        editInitialPermissions.value = new Set(permNames);
        editSelectedPermissions.value = new Set(permNames);
        showEditModal.value = true;
    } catch (err: unknown) {
        const apiError = err as { response?: { data?: { message?: string } } };
        toast.error(apiError.response?.data?.message || 'Failed to fetch role details.');
    }
};

const unsavedChangesCount = computed<number>(() => {
    if (!selectedRole.value) return 0;
    let diff = 0;
    for (const p of editSelectedPermissions.value) {
        if (!editInitialPermissions.value.has(p)) diff++;
    }
    for (const p of editInitialPermissions.value) {
        if (!editSelectedPermissions.value.has(p)) diff++;
    }
    return diff;
});

const handleSavePermissions = async () => {
    if (!selectedRole.value) return;

    editSubmitting.value = true;
    try {
        await api.put(`/roles/${selectedRole.value.id}/permissions`, {
            permissions: Array.from(editSelectedPermissions.value).filter((name) => !isSuperAdminOnly(name)),
        });

        toast.success(`Permissions for role '${selectedRole.value.name}' updated successfully!`);
        showEditModal.value = false;
        await fetchData();
        await authStore.fetchMe();
    } catch (err: unknown) {
        const apiError = err as { response?: { status?: number; data?: { message?: string } } };
        if (apiError.response?.status === 403) {
            toast.error(apiError.response.data?.message || 'Permission escalation denied: You cannot grant permissions you do not possess or modify your own role.');
        } else {
            toast.error(apiError.response?.data?.message || 'Failed to update role permissions.');
        }
    } finally {
        editSubmitting.value = false;
    }
};

// Delete actions
const openDeleteModal = (role: RoleModel) => {
    deleteTarget.value = role;
    showDeleteModal.value = true;
};

const handleDeleteRole = async () => {
    if (!deleteTarget.value) return;

    deleteSubmitting.value = true;
    try {
        await api.delete(`/roles/${deleteTarget.value.id}`);
        toast.success(`Role '${deleteTarget.value.name}' deleted successfully.`);
        showDeleteModal.value = false;
        await fetchData();
    } catch (err: unknown) {
        const apiError = err as { response?: { status?: number; data?: { message?: string } } };
        if (apiError.response?.status === 409) {
            toast.error(apiError.response.data?.message || 'Cannot delete role: The role is currently assigned to active users.');
        } else {
            toast.error(apiError.response?.data?.message || 'Failed to delete role.');
        }
    } finally {
        deleteSubmitting.value = false;
    }
};

// Super Admin only / non-delegable permissions helper
const isSuperAdminOnly = (permName: string): boolean => {
    const item = allPermissions.value.find((p) => p.name === permName);
    return item?.is_super_admin_only ?? false;
};

const assignablePermissionsCount = computed<number>(() => {
    return allPermissions.value.filter((p) => !isSuperAdminOnly(p.name)).length;
});

const canGrantPermission = (permName: string): boolean => {
    if (isSuperAdminOnly(permName)) {
        return false;
    }
    return authStore.can(permName);
};

const getPermissionTooltip = (permName: string): string => {
    if (isSuperAdminOnly(permName)) {
        return 'Implicit for Super Admin. This permission cannot be assigned to roles.';
    }
    if (!authStore.can(permName)) {
        return 'You cannot grant a permission you do not have.';
    }
    return '';
};

// Group toggle helpers
const isGroupAllSelected = (groupItems: PermissionCatalogItem[], selectedSet: Set<string>): boolean => {
    const grantable = groupItems.filter((item) => canGrantPermission(item.name));
    return grantable.length > 0 && grantable.every((item) => selectedSet.has(item.name));
};

const isGroupPartiallySelected = (groupItems: PermissionCatalogItem[], selectedSet: Set<string>): boolean => {
    const grantable = groupItems.filter((item) => canGrantPermission(item.name));
    const selectedCount = grantable.filter((item) => selectedSet.has(item.name)).length;
    return selectedCount > 0 && selectedCount < grantable.length;
};

const toggleGroup = (groupItems: PermissionCatalogItem[], selectedSet: Set<string>) => {
    const grantable = groupItems.filter((item) => canGrantPermission(item.name));
    if (grantable.length === 0) return;
    const allSelected = grantable.every((item) => selectedSet.has(item.name));
    for (const item of grantable) {
        if (allSelected) {
            selectedSet.delete(item.name);
        } else {
            selectedSet.add(item.name);
        }
    }
};

const togglePermission = (permName: string, selectedSet: Set<string>) => {
    if (!canGrantPermission(permName)) return;
    if (selectedSet.has(permName)) {
        selectedSet.delete(permName);
    } else {
        selectedSet.add(permName);
    }
};
</script>

<template>
    <Head title="Roles & Permissions - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                        Roles & Permissions
                    </h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Configure administrative roles and granular code-backed permission access
                    </p>
                </div>
                <button
                    v-if="authStore.can('roles.manage')"
                    type="button"
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 transition"
                    @click="openCreateModal"
                >
                    + Create Role
                </button>
            </div>

            <!-- Table Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div v-if="loading && roles.length === 0" class="p-6">
                    <Skeleton :rows="6" height="h-6" />
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">Role Name</th>
                                <th class="px-6 py-3.5">Description</th>
                                <th class="px-6 py-3.5">Panel</th>
                                <th class="px-6 py-3.5">Type</th>
                                <th class="px-6 py-3.5">Users</th>
                                <th class="px-6 py-3.5">Permissions</th>
                                <th class="px-6 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="r in roles" :key="r.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                                    {{ r.name }}
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-400 max-w-xs truncate">
                                    {{ r.description ?? '—' }}
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="r.panel === 'admin' ? 'danger' : r.panel === 'agent' ? 'warning' : 'info'">
                                        {{ r.panel }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="r.is_system ? 'neutral' : 'success'">
                                        {{ r.is_system ? 'System' : 'Custom' }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                    {{ r.users_count ?? 0 }}
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    <span v-if="r.name === 'SUPER_ADMIN'" class="text-rose-600 dark:text-rose-400 font-semibold">
                                        All (Bypass)
                                    </span>
                                    <span v-else class="font-mono text-zinc-600 dark:text-zinc-400">
                                        {{ r.permissions_count ?? r.permissions?.length ?? 0 }} / {{ assignablePermissionsCount }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <!-- View/Edit Permissions Button -->
                                    <button
                                        type="button"
                                        class="rounded-md bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 transition"
                                        @click="openEditModal(r)"
                                    >
                                        {{ canEditRole(r) ? 'Edit Permissions' : 'View Permissions' }}
                                    </button>

                                    <!-- Delete Button -->
                                    <button
                                        v-if="!r.is_system && authStore.can('roles.manage')"
                                        type="button"
                                        :disabled="!canEditRole(r)"
                                        :title="authStore.roles.includes(r.name) ? 'You cannot delete a role you hold' : 'Delete custom role'"
                                        class="rounded-md border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50 disabled:opacity-40 disabled:hover:bg-transparent dark:border-rose-900 dark:text-rose-400 dark:hover:bg-rose-950/40 transition"
                                        @click="openDeleteModal(r)"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Create Role Modal -->
            <Modal :show="showCreateModal" title="Create Custom Administrative Role" max-width="max-w-3xl" @close="showCreateModal = false">
                <form class="space-y-6" @submit.prevent="handleCreateRole">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                Role Identifier (Uppercase) *
                            </label>
                            <input
                                v-model="createName"
                                type="text"
                                placeholder="e.g. AUDITOR or SUPPORT_L1"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm uppercase text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                            <p v-if="createNameError" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
                                {{ createNameError }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                Description
                            </label>
                            <input
                                v-model="createDescription"
                                type="text"
                                placeholder="Audit logs and view transactions"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                    </div>

                    <!-- Permissions Grid -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-2">
                            <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                Assign Permissions ({{ createSelectedPermissions.size }} selected)
                            </span>
                            <span class="text-xs text-zinc-500">
                                Permissions you lack are disabled (Anti-escalation)
                            </span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-96 overflow-y-auto pr-1">
                            <div
                                v-for="(groupItems, groupName) in catalog"
                                :key="groupName"
                                class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40"
                            >
                                <div class="flex items-center justify-between pb-3 border-b border-zinc-200/80 dark:border-zinc-700/80">
                                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                                        {{ groupName }}
                                    </span>
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                        @click="toggleGroup(groupItems, createSelectedPermissions)"
                                    >
                                        {{ isGroupAllSelected(groupItems, createSelectedPermissions) ? 'Deselect all' : 'Select all' }}
                                    </button>
                                </div>

                                <div class="space-y-2.5 mt-3">
                                    <div
                                        v-for="perm in groupItems"
                                        :key="perm.id"
                                        :class="[
                                            'flex items-start gap-2.5 p-1.5 rounded-lg transition',
                                            canGrantPermission(perm.name) ? 'hover:bg-zinc-100 dark:hover:bg-zinc-700/50 cursor-pointer' : 'opacity-40 cursor-not-allowed',
                                        ]"
                                        :title="getPermissionTooltip(perm.name)"
                                        @click="togglePermission(perm.name, createSelectedPermissions)"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="createSelectedPermissions.has(perm.name)"
                                            :disabled="!canGrantPermission(perm.name)"
                                            class="mt-0.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900"
                                            @click.stop="togglePermission(perm.name, createSelectedPermissions)"
                                        />
                                        <div class="flex flex-col">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                                    {{ perm.name }}
                                                </span>
                                                <span
                                                    v-if="isSuperAdminOnly(perm.name)"
                                                    class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                                                >
                                                    Implicit for Super Admin
                                                </span>
                                            </div>
                                            <span class="text-[11px] text-zinc-500 dark:text-zinc-400 leading-tight">
                                                {{ perm.label }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="showCreateModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="createSubmitting"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {{ createSubmitting ? 'Creating...' : 'Create Role' }}
                        </button>
                    </div>
                </form>
            </Modal>

            <!-- Edit Role / Manage Permissions Modal -->
            <Modal :show="showEditModal" :title="`Manage Permissions: ${selectedRole?.name ?? ''}`" max-width="max-w-3xl" @close="showEditModal = false">
                <div v-if="selectedRole" class="space-y-6">
                    <!-- Warning / Info Banner -->
                    <div
                        v-if="selectedRole.is_system"
                        class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-800 dark:bg-amber-950/40 dark:border-amber-900 dark:text-amber-300"
                    >
                        ⚠️ <strong>System Built-in Role:</strong> Permissions are code-defined defaults. System roles cannot be modified.
                    </div>
                    <div
                        v-else-if="authStore.roles.includes(selectedRole.name)"
                        class="p-3 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-800 dark:bg-amber-950/40 dark:border-amber-900 dark:text-amber-300"
                    >
                        ⚠️ <strong>Self-Modification Prohibited:</strong> You hold the role '{{ selectedRole.name }}'. To prevent privilege lockouts, you cannot edit permissions of a role you currently hold.
                    </div>
                    <div
                        v-else
                        class="p-3 rounded-lg bg-indigo-50 border border-indigo-100 text-xs text-indigo-900 dark:bg-indigo-950/40 dark:border-indigo-900 dark:text-indigo-300 flex items-center justify-between"
                    >
                        <span>
                            ⚠️ Modifying this role will immediately affect access for <strong>{{ selectedRole.users_count ?? 0 }} assigned user(s)</strong>.
                        </span>
                        <span v-if="unsavedChangesCount > 0" class="font-bold text-amber-600 dark:text-amber-400">
                            {{ unsavedChangesCount }} unsaved change(s)
                        </span>
                    </div>

                    <!-- Role Details Summary -->
                    <div class="flex items-center gap-4 text-xs text-zinc-500">
                        <div>Description: <span class="text-zinc-800 dark:text-zinc-200 font-medium">{{ selectedRole.description ?? 'None' }}</span></div>
                        <div>Panel: <span class="font-medium text-zinc-800 dark:text-zinc-200 uppercase">{{ selectedRole.panel }}</span></div>
                        <div>Assigned Users: <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ selectedRole.users_count ?? 0 }}</span></div>
                    </div>

                    <!-- Permissions Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-96 overflow-y-auto pr-1">
                        <div
                            v-for="(groupItems, groupName) in catalog"
                            :key="groupName"
                            class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40"
                        >
                            <div class="flex items-center justify-between pb-3 border-b border-zinc-200/80 dark:border-zinc-700/80">
                                <span class="text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                                    {{ groupName }}
                                </span>
                                <button
                                    v-if="canEditRole(selectedRole)"
                                    type="button"
                                    class="text-xs font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400"
                                    @click="toggleGroup(groupItems, editSelectedPermissions)"
                                >
                                    {{ isGroupAllSelected(groupItems, editSelectedPermissions) ? 'Deselect all' : 'Select all' }}
                                </button>
                            </div>

                            <div class="space-y-2.5 mt-3">
                                <div
                                    v-for="perm in groupItems"
                                    :key="perm.id"
                                    :class="[
                                        'flex items-start gap-2.5 p-1.5 rounded-lg transition',
                                        canEditRole(selectedRole) && canGrantPermission(perm.name)
                                            ? 'hover:bg-zinc-100 dark:hover:bg-zinc-700/50 cursor-pointer'
                                            : 'opacity-50 cursor-not-allowed',
                                    ]"
                                    :title="getPermissionTooltip(perm.name)"
                                    @click="canEditRole(selectedRole) && togglePermission(perm.name, editSelectedPermissions)"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="editSelectedPermissions.has(perm.name)"
                                        :disabled="!canEditRole(selectedRole) || !canGrantPermission(perm.name)"
                                        class="mt-0.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-900 disabled:opacity-50"
                                        @click.stop="canEditRole(selectedRole) && togglePermission(perm.name, editSelectedPermissions)"
                                    />
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                                {{ perm.name }}
                                            </span>
                                            <span
                                                v-if="isSuperAdminOnly(perm.name)"
                                                class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                                            >
                                                Implicit for Super Admin
                                            </span>
                                        </div>
                                        <span class="text-[11px] text-zinc-500 dark:text-zinc-400 leading-tight">
                                            {{ perm.label }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Buttons -->
                    <div class="flex justify-end gap-2 pt-3 border-t border-zinc-100 dark:border-zinc-800">
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            @click="showEditModal = false"
                        >
                            {{ canEditRole(selectedRole) ? 'Cancel' : 'Close' }}
                        </button>
                        <button
                            v-if="canEditRole(selectedRole)"
                            type="button"
                            :disabled="editSubmitting || unsavedChangesCount === 0"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                            @click="handleSavePermissions"
                        >
                            {{ editSubmitting ? 'Saving...' : 'Save Permissions' }}
                        </button>
                    </div>
                </div>
            </Modal>

            <!-- Delete Confirmation Modal -->
            <ConfirmModal
                :open="showDeleteModal"
                :title="`Delete Role: ${deleteTarget?.name ?? ''}`"
                confirm-label="Delete Role"
                :loading="deleteSubmitting"
                @cancel="showDeleteModal = false"
                @confirm="handleDeleteRole"
            >
                Are you sure you want to delete custom role
                <strong>{{ deleteTarget?.name }}</strong>?
                This action cannot be undone. Roles currently assigned to users cannot be deleted.
            </ConfirmModal>
        </div>
    </AdminLayout>
</template>
