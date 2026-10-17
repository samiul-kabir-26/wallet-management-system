<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { password, required, type Rule } from '@/composables/validators';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Alert from '@/components/Alert.vue';
import TextField from '@/components/Forms/TextField.vue';

const props = defineProps<{
    userId: number;
}>();

const authStore = useAuthStore();
const toast = useToastStore();

// The emailed link carries the API route's signature; it is posted back unchanged
const signedQuery = typeof window !== 'undefined' ? window.location.search : '';

const step = ref<'verify' | 'choose'>('verify');
const linkError = ref<string | null>(null);
// Restricted token that can only change the password, plus the temporary password it was issued for
let passwordChangeToken = '';
let temporaryPassword = '';

const matchesNewPassword: Rule = (value, ctx) =>
    String(value ?? '') === String(ctx?.form.new_password ?? '') ? true : 'The passwords do not match.';

// One form for both steps: TextField binds to the nearest form, so a second useForm would capture its fields
const { handleSubmit, isSubmitting, setErrors } = useForm<{
    temporary_password: string;
    new_password: string;
    new_password_confirmation: string;
}>({
    validationSchema: computed(() =>
        step.value === 'verify'
            ? { temporary_password: required('Temporary password') }
            : { new_password: password(8), new_password_confirmation: matchesNewPassword },
    ),
    initialValues: { temporary_password: '', new_password: '', new_password_confirmation: '' },
});

const verify = handleSubmit(async (values) => {
    try {
        const res = await api.post(`/auth/accept-invite/${props.userId}${signedQuery}`, { password: values.temporary_password });
        passwordChangeToken = res.data.data.token;
        temporaryPassword = values.temporary_password;
        step.value = 'choose';
    } catch (err: unknown) {
        const status = (err as { response?: { status?: number } }).response?.status;
        if (status === 403) {
            linkError.value = 'This invitation link has expired or is not valid. Sign in through the Staff / Admin tab with your email and temporary password instead.';
            return;
        }
        setErrors({ temporary_password: parseApiError(err, 'Could not accept the invitation.').message });
    }
});

const choose = handleSubmit(async (values) => {
    try {
        const res = await api.post(
            '/auth/change-password',
            {
                current_password: temporaryPassword,
                new_password: values.new_password,
                new_password_confirmation: values.new_password_confirmation,
            },
            { headers: { Authorization: `Bearer ${passwordChangeToken}` } },
        );
        const data = res.data.data;
        authStore.setAuth(data.token, data.user, data.idle_timeout_minutes, 'admin');
        toast.success('Welcome! Your password is set.');
        router.visit('/admin/dashboard');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Could not set your password.');
        setErrors(fieldErrors);
        toast.error(message);
    }
});
</script>

<template>
    <Head title="Accept Invitation" />
    <AuthLayout title="Accept your staff invitation" subtitle="Confirm the temporary password from your email, then choose your own">
        <div v-if="linkError" class="space-y-4">
            <Alert type="warning" :message="linkError" />
            <Link href="/login" class="block text-center text-sm font-semibold text-indigo-400 hover:underline">Go to sign in</Link>
        </div>

        <form v-else-if="step === 'verify'" class="space-y-4" novalidate @submit.prevent="verify">
            <TextField name="temporary_password" label="Temporary password" type="password" autocomplete="current-password" />
            <button
                type="submit"
                :disabled="isSubmitting"
                class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ isSubmitting ? 'Checking...' : 'Continue' }}
            </button>
        </form>

        <form v-else class="space-y-4" novalidate @submit.prevent="choose">
            <TextField name="new_password" label="New password" type="password" autocomplete="new-password" />
            <TextField name="new_password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" />
            <button
                type="submit"
                :disabled="isSubmitting"
                class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ isSubmitting ? 'Saving...' : 'Set Password & Sign In' }}
            </button>
        </form>
    </AuthLayout>
</template>
