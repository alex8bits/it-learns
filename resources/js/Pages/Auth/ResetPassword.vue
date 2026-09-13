<template>
    <GuestLayout>
        <form @submit.prevent="submit">
            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Новый пароль</h1>

            <Input
                v-model="form.email"
                label="Email"
                name="email"
                type="email"
                autocomplete="email"
                required
                :error="form.errors.email"
            />

            <Input
                v-model="form.password"
                label="Новый пароль"
                name="password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            />

            <Input
                v-model="form.password_confirmation"
                label="Подтверждение"
                name="password_confirmation"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />

            <Button class="w-full" :processing="form.processing">Сохранить пароль</Button>
        </form>
    </GuestLayout>
</template>

<script setup>
import Button from '../../Components/Button.vue';
import Input from '../../Components/Input.vue';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

// Reset token travels inside the form data; Inertia submits it in the POST body.
const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => form.post('/reset-password');
</script>
