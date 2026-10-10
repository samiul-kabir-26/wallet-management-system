<script setup lang="ts">
import { toRef } from 'vue';
import { useField } from 'vee-validate';

const props = withDefaults(
    defineProps<{
        name: string;
        label: string;
        type?: string;
        placeholder?: string;
        step?: string;
        min?: string | number;
        autocomplete?: string;
        inputmode?: 'numeric' | 'decimal' | 'text' | 'tel' | 'email';
        serverError?: string;
    }>(),
    { type: 'text' },
);

const { value, errorMessage, handleBlur } = useField<string | number>(toRef(props, 'name'));
</script>

<template>
    <div>
        <label :for="name" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ label }}</label>
        <input
            :id="name"
            v-model="value"
            :type="type"
            :placeholder="placeholder"
            :step="step"
            :min="min"
            :autocomplete="autocomplete"
            :inputmode="inputmode"
            :aria-invalid="!!(errorMessage || serverError)"
            class="mt-1 block w-full rounded-lg border px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:outline-none focus:ring-1 dark:bg-zinc-800 dark:text-zinc-100"
            :class="
                errorMessage || serverError
                    ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500 dark:border-rose-700'
                    : 'border-zinc-300 focus:border-indigo-500 focus:ring-indigo-500 dark:border-zinc-700'
            "
            @blur="handleBlur"
        />
        <p v-if="errorMessage || serverError" class="mt-1 text-xs text-rose-600 dark:text-rose-400">
            {{ errorMessage || serverError }}
        </p>
    </div>
</template>
