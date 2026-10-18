<script setup lang="ts">
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { password, required, type Rule } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';

const emit = defineEmits<{
    (e: 'changed'): void;
}>();

const authStore = useAuthStore();
const toast = useToastStore();

const matchesNewPassword: Rule = (value, ctx) =>
    String(value ?? '') === String(ctx?.form.new_password ?? '') ? true : 'The passwords do not match.';

const { handleSubmit, isSubmitting, resetForm, setErrors } = useForm<{
    current_password: string;
    new_password: string;
    new_password_confirmation: string;
}>({
    validationSchema: {
        current_password: required('Current password'),
        new_password: password(8),
        new_password_confirmation: matchesNewPassword,
    },
    initialValues: { current_password: '', new_password: '', new_password_confirmation: '' },
});

const onSubmit = handleSubmit(async (values) => {
    try {
        const res = await api.post('/auth/change-password', values);
        const data = res.data.data;
        // The server signs out every session and issues a fresh admin token for this one
        authStore.setAuth(data.token, data.user, data.idle_timeout_minutes, 'admin');
        resetForm();
        toast.success('Password changed. Other sessions have been signed out.');
        emit('changed');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to change password.');
        if (message.includes('current password')) {
            fieldErrors.current_password = message;
        }
        setErrors(fieldErrors);
        toast.error(message);
    }
});
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
        <TextField name="current_password" label="Current password" type="password" autocomplete="current-password" />
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <TextField name="new_password" label="New password" type="password" autocomplete="new-password" />
            <TextField name="new_password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" />
        </div>
        <div class="flex justify-end">
            <button
                type="submit"
                :disabled="isSubmitting"
                class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ isSubmitting ? 'Saving...' : 'Change Password' }}
            </button>
        </div>
    </form>
</template>
