<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { api } from '@/services/api';
import { useAuthStore, type Panel } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import { parseApiError } from '@/composables/useApiError';
import { required } from '@/composables/validators';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';
import Skeleton from '@/components/Skeleton.vue';
import TextField from '@/components/Forms/TextField.vue';
import ChangePasswordForm from '@/components/Forms/ChangePasswordForm.vue';
import ChangePinForm from '@/components/Forms/ChangePinForm.vue';
import ConfirmModal from '@/components/Modals/ConfirmModal.vue';
import AddPhoneCard from '@/components/Account/AddPhoneCard.vue';
import PhoneVerificationCard from '@/components/Account/PhoneVerificationCard.vue';
import KycCard from '@/components/Account/KycCard.vue';
import UserAvatar from '@/components/Account/UserAvatar.vue';
import type { User } from '@/types/models';

const MAX_AVATAR_BYTES = 2 * 1024 * 1024;
const AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

const props = defineProps<{
    panel: Panel;
}>();

const authStore = useAuthStore();
const toast = useToastStore();

// Staff on a temporary password can only change it; every other admin endpoint refuses them
const forcedPasswordChange = computed(() => props.panel === 'admin' && authStore.mustChangePassword);

const profile = ref<User | null>(null);
const loading = ref(true);
const uploading = ref(false);
const showRemoveAvatar = ref(false);
const removingAvatar = ref(false);
const avatarInput = ref<HTMLInputElement | null>(null);

const { handleSubmit, isSubmitting, resetForm, setErrors } = useForm<{ name: string; address: string }>({
    validationSchema: { name: required('Name') },
    initialValues: { name: '', address: '' },
});

const applyProfile = (user: User): void => {
    profile.value = user;
    resetForm({ values: { name: user.name, address: user.address ?? '' } });
    authStore.updateUser({
        name: user.name,
        avatar_url: user.avatar_url ?? null,
        phone_number: user.phone_number,
        is_verified: user.is_verified,
    });
};

const fetchProfile = async (): Promise<void> => {
    loading.value = true;
    try {
        const res = await api.get('/users/me');
        applyProfile(res.data.data.user as User);
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load your profile.').message);
    } finally {
        loading.value = false;
    }
};

const savingReceipts = ref(false);

const toggleReceiptEmails = async (): Promise<void> => {
    if (!profile.value) return;
    const enabled = !(profile.value.receipt_emails_enabled ?? true);
    savingReceipts.value = true;
    try {
        const res = await api.patch('/users/me', { receipt_emails_enabled: enabled });
        applyProfile(res.data.data.user as User);
        toast.success(enabled ? 'Email receipts turned on.' : 'Email receipts turned off. SMS receipts continue.');
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to update your receipt settings.').message);
    } finally {
        savingReceipts.value = false;
    }
};

const saveProfile = handleSubmit(async (values) => {
    try {
        const res = await api.patch('/users/me', { name: values.name.trim(), address: values.address.trim() || null });
        applyProfile(res.data.data.user as User);
        toast.success('Profile updated.');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to update your profile.');
        setErrors(fieldErrors);
        toast.error(message);
    }
});

const chooseAvatar = (): void => {
    avatarInput.value?.click();
};

const uploadAvatar = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (!file) {
        return;
    }
    if (!AVATAR_TYPES.includes(file.type)) {
        toast.error('Choose a JPG, PNG or WebP image.');
        return;
    }
    if (file.size > MAX_AVATAR_BYTES) {
        toast.error('The photo must not be larger than 2 MB.');
        return;
    }

    const formData = new FormData();
    formData.append('avatar', file);
    uploading.value = true;
    try {
        const res = await api.post('/users/me/avatar', formData, { headers: { 'Content-Type': 'multipart/form-data' } });
        applyProfile(res.data.data.user as User);
        toast.success('Profile photo updated.');
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to upload the photo.');
        toast.error(fieldErrors.avatar ?? message);
    } finally {
        uploading.value = false;
    }
};

const removeAvatar = async (): Promise<void> => {
    removingAvatar.value = true;
    try {
        const res = await api.delete('/users/me/avatar');
        applyProfile(res.data.data.user as User);
        toast.success('Profile photo removed.');
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to remove the photo.').message);
    } finally {
        removingAvatar.value = false;
        showRemoveAvatar.value = false;
    }
};

const onPasswordChanged = (): void => {
    // The profile is only skipped while a temporary password blocks the panel; continue to it now
    if (!profile.value) {
        router.visit('/admin/dashboard');
    }
};

onMounted(() => {
    if (forcedPasswordChange.value) {
        loading.value = false;
        return;
    }
    void fetchProfile();
});
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">My Account</h1>
            <p class="text-sm text-zinc-500">Your profile, phone verification and sign-in security.</p>
        </div>

        <Alert
            v-if="forcedPasswordChange"
            type="warning"
            message="Your account was created with a temporary password. Choose your own password to continue to the admin panel."
        />

        <template v-if="!forcedPasswordChange">
            <Skeleton v-if="loading" :rows="4" height="h-10" />

            <template v-else-if="profile">
                <!-- Profile -->
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                        <div class="flex flex-col items-center gap-3">
                            <UserAvatar :name="profile.name" :src="profile.avatar_url" size="lg" />
                            <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="uploadAvatar" />
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    :disabled="uploading"
                                    class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                    @click="chooseAvatar"
                                >
                                    {{ uploading ? 'Uploading...' : profile.avatar_url ? 'Change photo' : 'Upload photo' }}
                                </button>
                                <button
                                    v-if="profile.avatar_url"
                                    type="button"
                                    class="rounded-lg px-2 py-1.5 text-xs font-medium text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                    @click="showRemoveAvatar = true"
                                >
                                    Remove
                                </button>
                            </div>
                            <p class="text-[11px] text-zinc-400">JPG, PNG or WebP, up to 2 MB</p>
                        </div>

                        <form class="flex-1 space-y-4" novalidate @submit.prevent="saveProfile">
                            <TextField name="name" label="Full name" autocomplete="name" />
                            <TextField name="address" label="Address (optional)" autocomplete="street-address" />

                            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Email</dt>
                                    <dd class="mt-1 rounded-lg bg-zinc-50 px-3 py-2 text-sm text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
                                        {{ profile.email ?? 'Not set' }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="flex items-center gap-2 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                        Phone number
                                        <Badge v-if="profile.phone_number" :variant="profile.is_verified ? 'success' : 'warning'">
                                            {{ profile.is_verified ? 'Verified' : 'Unverified' }}
                                        </Badge>
                                    </dt>
                                    <dd class="mt-1 rounded-lg bg-zinc-50 px-3 py-2 text-sm text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
                                        {{ profile.phone_number ?? 'Not set' }}
                                    </dd>
                                </div>
                            </dl>
                            <p class="text-[11px] text-zinc-400">
                                Email and phone number identify your account and cannot be changed here. Contact support if they are wrong.
                            </p>

                            <div class="flex justify-end border-t border-zinc-100 pt-4 dark:border-zinc-800">
                                <button
                                    type="submit"
                                    :disabled="isSubmitting"
                                    class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
                                >
                                    {{ isSubmitting ? 'Saving...' : 'Save Profile' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- KYC: customers verify their identity to raise their limits -->
                <KycCard v-if="panel === 'user'" />

                <!-- Transaction receipts: SMS always, email copy optional -->
                <div
                    v-if="panel !== 'admin'"
                    class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Transaction receipts</p>
                            <p class="text-xs text-zinc-500">
                                Every transfer, cash-in and cash-out is confirmed by SMS. This cannot be turned off, so you always hear about money
                                leaving your wallet.
                            </p>
                            <p v-if="!profile.email" class="text-xs text-zinc-400">
                                Add an email address through customer care to also get receipts by email.
                            </p>
                        </div>
                        <label v-if="profile.email" class="flex shrink-0 cursor-pointer items-center gap-2 text-xs font-medium text-zinc-700 dark:text-zinc-300">
                            <input
                                type="checkbox"
                                class="size-4 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500"
                                :checked="profile.receipt_emails_enabled ?? true"
                                :disabled="savingReceipts"
                                @change="toggleReceiptEmails"
                            />
                            Email me a copy
                        </label>
                    </div>
                </div>

                <!-- Phone number: add one (staff accounts start without) or verify the existing one -->
                <AddPhoneCard v-if="!profile.phone_number" @added="applyProfile" />
                <PhoneVerificationCard
                    v-if="profile.phone_number && !profile.is_verified"
                    :phone-number="profile.phone_number"
                    @verified="applyProfile"
                />
            </template>
        </template>

        <!-- Security -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-4 space-y-1">
                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                    {{ panel === 'admin' ? 'Change password' : 'Change PIN' }}
                </p>
                <p class="text-xs text-zinc-500">
                    {{
                        panel === 'admin'
                            ? 'Used with the emailed code to sign in to the admin panel.'
                            : 'Forgot your PIN? Contact customer care to receive a secure reset link.'
                    }}
                </p>
            </div>
            <ChangePasswordForm v-if="panel === 'admin'" @changed="onPasswordChanged" />
            <ChangePinForm v-else />
        </div>

        <ConfirmModal
            :open="showRemoveAvatar"
            title="Remove profile photo?"
            confirm-label="Remove"
            :loading="removingAvatar"
            @confirm="removeAvatar"
            @cancel="showRemoveAvatar = false"
        >
            Your initials will be shown instead.
        </ConfirmModal>
    </div>
</template>
