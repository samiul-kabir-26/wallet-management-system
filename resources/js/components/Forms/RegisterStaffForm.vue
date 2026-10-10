<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { email, password, phone, required } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';

const emit = defineEmits<{
    (e: 'registered'): void;
    (e: 'cancel'): void;
}>();

const toast = useToastStore();
const role = ref<'ADMIN' | 'MODERATOR' | 'USER'>('ADMIN');
const submitting = ref(false);

const { handleSubmit, setErrors, resetForm } = useForm<{ name: string; email: string; phone: string; password: string }>({
    validationSchema: {
        name: required('Full name'),
        email,
        phone,
        password: password(8),
    },
    initialValues: { name: '', email: '', phone: '', password: '' },
});

const onSubmit = handleSubmit(async (values) => {
    submitting.value = true;
    try {
        await api.post('/users/register', {
            name: values.name,
            email: values.email,
            phone_number: values.phone,
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
        if (fieldErrors.phone_number) mapped.phone = fieldErrors.phone_number;
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
        <TextField name="phone" label="Phone Number (BD)" type="tel" inputmode="tel" placeholder="01799887766" />
        <TextField name="password" label="Password" type="password" autocomplete="new-password" placeholder="••••••••" />

        <div>
            <label for="staff-role" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Role</label>
            <select
                id="staff-role"
                v-model="role"
                class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
            >
                <option value="ADMIN">ADMIN</option>
                <option value="MODERATOR">MODERATOR</option>
                <option value="USER">USER</option>
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
