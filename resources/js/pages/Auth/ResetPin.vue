<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { parseApiError } from '@/composables/useApiError';
import { pin, pinConfirmation } from '@/composables/validators';
import AuthLayout from '@/layouts/AuthLayout.vue';
import Alert from '@/components/Alert.vue';
import TextField from '@/components/Forms/TextField.vue';

const props = defineProps<{
    userId: number;
}>();

// The emailed link carries the API route's signature; it is posted back unchanged
const signedQuery = typeof window !== 'undefined' ? window.location.search : '';

const done = ref(false);
const linkError = ref<string | null>(null);

const { handleSubmit, isSubmitting, setErrors } = useForm<{ pin: string; pin_confirmation: string }>({
    validationSchema: { pin, pin_confirmation: pinConfirmation('pin') },
    initialValues: { pin: '', pin_confirmation: '' },
});

const onSubmit = handleSubmit(async (values) => {
    try {
        await api.post(`/auth/reset-pin/${props.userId}${signedQuery}`, values);
        done.value = true;
    } catch (err: unknown) {
        const status = (err as { response?: { status?: number } }).response?.status;
        const { message, fieldErrors } = parseApiError(err, 'Could not reset your PIN.');
        if (status === 422) {
            setErrors(fieldErrors);
            return;
        }
        // Expired, already used, or the PIN changed since the link was issued
        linkError.value = `${message} Please contact customer care for a new link.`;
    }
});
</script>

<template>
    <Head title="Set a New PIN" />
    <AuthLayout title="Set a new PIN" subtitle="Choose a PIN only you know. Staff will never ask for it.">
        <div v-if="done" class="space-y-4">
            <Alert type="success" message="Your PIN has been changed. Sign in with your phone number and the new PIN." />
            <Link href="/login" class="block text-center text-sm font-semibold text-indigo-400 hover:underline">Go to sign in</Link>
        </div>

        <div v-else-if="linkError" class="space-y-4">
            <Alert type="warning" :message="linkError" />
            <Link href="/login" class="block text-center text-sm font-semibold text-indigo-400 hover:underline">Go to sign in</Link>
        </div>

        <form v-else class="space-y-4" novalidate @submit.prevent="onSubmit">
            <TextField name="pin" label="New PIN (5-10 digits)" type="password" inputmode="numeric" autocomplete="new-password" />
            <TextField name="pin_confirmation" label="Confirm new PIN" type="password" inputmode="numeric" autocomplete="new-password" />
            <button
                type="submit"
                :disabled="isSubmitting"
                class="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
            >
                {{ isSubmitting ? 'Saving...' : 'Set PIN' }}
            </button>
        </form>
    </AuthLayout>
</template>
