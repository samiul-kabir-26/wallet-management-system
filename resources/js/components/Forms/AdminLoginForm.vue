<script setup lang="ts">
import { useForm } from 'vee-validate';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { required } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';

const emit = defineEmits<{
    (e: 'otp-sent', identifier: string): void;
}>();

const authStore = useAuthStore();
const toast = useToastStore();

const { handleSubmit } = useForm<{ identifier: string; password: string }>({
    validationSchema: {
        identifier: required('Email or phone'),
        password: required('Password'),
    },
    initialValues: { identifier: '', password: '' },
});

const onSubmit = handleSubmit(async (values) => {
    try {
        const msg = await authStore.adminLogin(values.identifier, values.password);
        toast.success(msg);
        emit('otp-sent', values.identifier);
    } catch (err: unknown) {
        toast.error((err as Error).message);
    }
});
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
        <TextField name="identifier" label="Admin Email or Phone" autocomplete="username" placeholder="admin@wallet.local" />
        <TextField name="password" label="Password" type="password" autocomplete="current-password" placeholder="••••••••" />

        <button
            type="submit"
            :disabled="authStore.loading"
            class="w-full rounded-lg bg-rose-600 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-rose-500 focus:outline-none disabled:opacity-50"
        >
            {{ authStore.loading ? 'Verifying credentials...' : 'Next: Send 2FA Code' }}
        </button>
    </form>
</template>
