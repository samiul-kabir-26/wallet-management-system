<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useAuthGuard } from '@/composables/useAuthGuard';
import { parseApiError } from '@/composables/useApiError';
import { api } from '@/services/api';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';
import AdminLayout from '@/layouts/AdminLayout.vue';
import Alert from '@/components/Alert.vue';
import Badge from '@/components/Badge.vue';
import Modal from '@/components/Modal.vue';
import Pagination from '@/components/Pagination.vue';
import Skeleton from '@/components/Skeleton.vue';
import type { KycSubmission, Pagination as PaginationType } from '@/types/models';

useAuthGuard('ADMIN', 'users.manage_kyc');

const authStore = useAuthStore();
const toast = useToastStore();

const status = ref<'PENDING' | 'APPROVED' | 'REJECTED'>('PENDING');
const submissions = ref<KycSubmission[]>([]);
const pagination = ref<PaginationType | null>(null);
const loading = ref(true);

const selected = ref<KycSubmission | null>(null);
const images = ref<{ front: string | null; back: string | null }>({ front: null, back: null });
const decision = ref<'approve' | 'reject' | null>(null);
const note = ref('');
const formError = ref<string | null>(null);
const submitting = ref(false);

const fetchSubmissions = async (page = 1) => {
    loading.value = true;
    try {
        const res = await api.get('/users/kyc-submissions', { params: { status: status.value, page, per_page: 15 } });
        submissions.value = res.data.data?.items ?? [];
        pagination.value = res.data.data?.pagination ?? null;
    } catch (err: unknown) {
        toast.error(parseApiError(err, 'Failed to load submissions.').message);
    } finally {
        loading.value = false;
    }
};

watch(status, () => fetchSubmissions(1));

const releaseImages = () => {
    Object.values(images.value).forEach((url) => url && URL.revokeObjectURL(url));
    images.value = { front: null, back: null };
};

// Images need the bearer token, so fetch them as blobs and show local object URLs
const open = async (submission: KycSubmission) => {
    selected.value = submission;
    decision.value = null;
    note.value = '';
    formError.value = null;
    releaseImages();
    try {
        const [front, back] = await Promise.all(
            (['front', 'back'] as const).map((side) =>
                api.get(`/users/kyc-submissions/${submission.id}/documents/${side}`, { responseType: 'blob' }),
            ),
        );
        images.value = { front: URL.createObjectURL(front.data as Blob), back: URL.createObjectURL(back.data as Blob) };
    } catch (err: unknown) {
        formError.value = parseApiError(err, 'Could not load the document images.').message;
    }
};

const close = () => {
    selected.value = null;
    releaseImages();
};

const isOwn = (submission: KycSubmission): boolean => submission.user?.id === authStore.user?.id;

const submit = async () => {
    if (!selected.value || !decision.value) return;
    if (decision.value === 'reject' && note.value.trim().length < 5) {
        formError.value = 'Give a reason of at least 5 characters; the customer receives it by SMS.';
        return;
    }
    submitting.value = true;
    formError.value = null;
    try {
        const id = selected.value.id;
        if (decision.value === 'approve') {
            await api.post(`/users/kyc-submissions/${id}/approve`, { note: note.value.trim() || null });
            toast.success('Identity verified.');
        } else {
            await api.post(`/users/kyc-submissions/${id}/reject`, { reason: note.value.trim() });
            toast.success('Documents rejected.');
        }
        close();
        await fetchSubmissions(pagination.value?.current_page ?? 1);
    } catch (err: unknown) {
        const { message, fieldErrors } = parseApiError(err, 'Failed to review this submission.');
        formError.value = fieldErrors.reason ?? fieldErrors.note ?? message;
    } finally {
        submitting.value = false;
    }
};

const docLabel = (type: KycSubmission['document_type']) =>
    ({ NID: 'National ID', PASSPORT: 'Passport', DRIVING_LICENSE: 'Driving licence' })[type];

onMounted(() => fetchSubmissions());
onBeforeUnmount(releaseImages);
</script>

<template>
    <Head title="KYC Reviews - WalletMS Admin" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">KYC Reviews</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Compare the photos with the details the customer typed. Approving verifies their identity and moves Basic accounts to the
                        Standard tier.
                    </p>
                </div>
                <select
                    v-model="status"
                    aria-label="Status"
                    class="rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                >
                    <option value="PENDING">Waiting for review</option>
                    <option value="APPROVED">Approved</option>
                    <option value="REJECTED">Rejected</option>
                </select>
            </div>

            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div v-if="loading && submissions.length === 0" class="p-6"><Skeleton :rows="5" height="h-6" /></div>
                <div v-else-if="submissions.length === 0" class="p-12 text-center text-sm text-zinc-500">No submissions in this list.</div>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-zinc-800/50 dark:text-zinc-400">
                            <tr>
                                <th class="px-6 py-3.5">ID</th>
                                <th class="px-6 py-3.5">Customer</th>
                                <th class="px-6 py-3.5">Document</th>
                                <th class="px-6 py-3.5">Name / DOB</th>
                                <th class="px-6 py-3.5">Submitted</th>
                                <th class="px-6 py-3.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            <tr v-for="submission in submissions" :key="submission.id" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50">
                                <td class="px-6 py-4 font-mono text-xs font-semibold">#{{ submission.id }}</td>
                                <td class="px-6 py-4">
                                    <Link
                                        v-if="submission.user"
                                        :href="`/admin/users/${submission.user.id}`"
                                        class="font-medium text-zinc-900 hover:text-indigo-600 dark:text-zinc-100 dark:hover:text-indigo-400"
                                    >
                                        {{ submission.user.name }}
                                    </Link>
                                    <div class="text-xs text-zinc-500">{{ submission.user?.phone_number }} · {{ submission.user?.kyc_tier }}</div>
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    <div>{{ docLabel(submission.document_type) }}</div>
                                    <div class="font-mono text-zinc-500">{{ submission.document_number }}</div>
                                </td>
                                <td class="px-6 py-4 text-xs">
                                    <div>{{ submission.full_name }}</div>
                                    <div class="text-zinc-500">{{ submission.date_of_birth }}</div>
                                </td>
                                <td class="px-6 py-4 text-xs text-zinc-500">
                                    {{ new Date(submission.created_at).toLocaleString() }}
                                    <div v-if="submission.reviewed_by" class="mt-1">
                                        <Badge :variant="submission.status === 'APPROVED' ? 'success' : 'danger'">{{ submission.status }}</Badge>
                                        by {{ submission.reviewed_by.name }}
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button
                                        type="button"
                                        class="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300"
                                        @click="open(submission)"
                                    >
                                        {{ submission.status === 'PENDING' ? 'Review' : 'View' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :pagination="pagination" :loading="loading" @change-page="fetchSubmissions" />
            </div>
        </div>

        <Modal :show="selected !== null" :title="selected ? `Submission #${selected.id}` : ''" max-width="max-w-3xl" @close="close">
            <div v-if="selected" class="space-y-4 text-sm">
                <dl class="grid grid-cols-2 gap-2 rounded-lg bg-zinc-50 p-3 text-xs dark:bg-zinc-800/60">
                    <div><dt class="text-zinc-500">Customer</dt><dd>{{ selected.user?.name }} ({{ selected.user?.phone_number }})</dd></div>
                    <div><dt class="text-zinc-500">Document</dt><dd>{{ docLabel(selected.document_type) }} · <span class="font-mono">{{ selected.document_number }}</span></dd></div>
                    <div><dt class="text-zinc-500">Name typed</dt><dd>{{ selected.full_name }}</dd></div>
                    <div><dt class="text-zinc-500">Date of birth</dt><dd>{{ selected.date_of_birth }}</dd></div>
                </dl>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <figure v-for="side in ['front', 'back'] as const" :key="side" class="space-y-1">
                        <img v-if="images[side]" :src="images[side] ?? ''" :alt="`${side} of the document`" class="w-full rounded-lg border border-zinc-200 dark:border-zinc-700" />
                        <Skeleton v-else height="h-40" />
                        <figcaption class="text-xs capitalize text-zinc-500">{{ side }}</figcaption>
                    </figure>
                </div>

                <template v-if="selected.status === 'PENDING'">
                    <p v-if="isOwn(selected)" class="text-xs text-zinc-500">You cannot review your own documents.</p>
                    <template v-else>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold"
                                :class="decision === 'approve' ? 'border-emerald-600 bg-emerald-50 text-emerald-700' : 'border-zinc-300 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300'"
                                @click="decision = 'approve'"
                            >
                                Approve
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold"
                                :class="decision === 'reject' ? 'border-rose-600 bg-rose-50 text-rose-700' : 'border-zinc-300 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300'"
                                @click="decision = 'reject'"
                            >
                                Reject
                            </button>
                        </div>
                        <label v-if="decision" class="block space-y-1">
                            <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                {{ decision === 'approve' ? 'Note (optional)' : 'Reason (sent to the customer by SMS)' }}
                            </span>
                            <textarea
                                v-model="note"
                                rows="2"
                                maxlength="500"
                                class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </label>
                    </template>
                </template>
                <p v-else-if="selected.review_note" class="text-xs text-zinc-500">Review note: “{{ selected.review_note }}”</p>

                <Alert v-if="formError" type="error" :message="formError" />
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        @click="close"
                    >
                        Close
                    </button>
                    <button
                        v-if="decision"
                        type="button"
                        :disabled="submitting"
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        :class="decision === 'approve' ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-rose-600 hover:bg-rose-500'"
                        @click="submit"
                    >
                        {{ submitting ? 'Saving...' : decision === 'approve' ? 'Verify identity' : 'Reject' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
