<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { otp } from '@/composables/validators';
import TextField from '@/components/Forms/TextField.vue';

const props = defineProps<{
    identifier: string;
}>();

const emit = defineEmits<{
    (e: 'cancel'): void;
}>();

const authStore = useAuthStore();
const toast = useToastStore();

const { handleSubmit } = useForm<{ otpCode: string }>({
    validationSchema: { otpCode: otp },
    initialValues: { otpCode: '' },
});

const onSubmit = handleSubmit(async (values) => {
    try {
        await authStore.adminVerifyOtp(props.identifier, values.otpCode);
        router.visit('/admin/dashboard');
    } catch (err: unknown) {
        toast.error((err as Error).message);
    }
});
</script>

<template>
    <form class="space-y-4" novalidate @submit.prevent="onSubmit">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            A 6-digit verification code has been dispatched to
            <span class="font-medium text-zinc-900 dark:text-zinc-200">{{ identifier }}</span
            >.
        </p>

        <TextField name="otpCode" label="Enter 6-Digit OTP" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />

        <div class="flex justify-end gap-2">
            <button
                type="button"
                class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                @click="emit('cancel')"
            >
                Cancel
            </button>
            <button
                type="submit"
                :disabled="authStore.loading"
                class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
            >
                {{ authStore.loading ? 'Verifying...' : 'Confirm & Sign In' }}
            </button>
        </div>
    </form>
</template>
