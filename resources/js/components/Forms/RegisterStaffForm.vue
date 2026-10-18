<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { email, password, required } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';
import type { RoleModel } from '@/types/models';

const emit = defineEmits<{
    (e: 'registered'): void;
    (e: 'cancel'): void;
}>();

const toast = useToastStore();
const role = ref<string>('ADMIN');
const availableRoles = ref<RoleModel[]>([]);
const rolesLoading = ref<boolean>(true);

onMounted(async () => {
    try {
        const res = await api.get('/roles', { params: { panel: 'admin', per_page: 100 } });
        // SUPER_ADMIN is never granted through these forms; the API rejects it as well
        availableRoles.value = ((res.data.data?.items ?? []) as RoleModel[]).filter((r) => r.name !== 'SUPER_ADMIN');
        if (availableRoles.value.length > 0 && !availableRoles.value.some((r) => r.name === role.value)) {
            role.value = availableRoles.value[0].name;
        }
    } catch {
        // Fallback default
        availableRoles.value = [{ id: 0, name: 'ADMIN', description: 'Administrator', panel: 'admin', is_system: true }];
    } finally {
        rolesLoading.value = false;
    }
});

const submitting = ref(false);

const { handleSubmit, setErrors, resetForm } = useForm<{ name: string; email: string; password: string }>({
    validationSchema: {
        name: required('Full name'),
        email,
        password: password(8),
    },
    initialValues: { name: '', email: '', password: '' },
});

const onSubmit = handleSubmit(async (values) => {
    submitting.value = true;
    try {
        await api.post('/users/register', {
            name: values.name,
            email: values.email,
            password: values.password,
            password_confirmation: values.password,
            role: role.value,
        });
        toast.success(`Successfully registered ${role.value} account for ${values.name}!`);
        resetForm();
        emit('registered');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to register staff.');
        const mapped: Record<string, string> = {};
        if (fieldErrors.name) mapped.name = fieldErrors.name;
        if (fieldErrors.email) mapped.email = fieldErrors.email;
        if (fieldErrors.password) mapped.password = fieldErrors.password;
        setErrors(mapped);
        toast.error(message);
    } finally {
        submitting.value = false;
    }
});
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
        <TextField name="name" label="Full Name" placeholder="John Administrator" />
        <TextField name="email" label="Email Address" type="email" inputmode="email" placeholder="john@wallet.local" />
        <TextField name="password" label="Password" type="password" autocomplete="new-password" placeholder="••••••••" />
        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            Staff sign in with email, password and an emailed code. A phone number and PIN are only needed for customer or agent wallets.
        </p>

        <div>
            <label for="staff-role" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Role</label>
            <select
                id="staff-role"
                v-model="role"
                :disabled="rolesLoading"
                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 disabled:opacity-50"
            >
                <option v-for="r in availableRoles" :key="r.id" :value="r.name">
                    {{ r.name }}{{ r.description ? ` (${r.description})` : '' }}
                </option>
            </select>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <button
                type="button"
                class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                @click="emit('cancel')"
            >
                Cancel
            </button>
            <button
                type="submit"
                :disabled="submitting"
                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ submitting ? 'Registering...' : 'Create Account' }}
            </button>
        </div>
    </form>
</template>
