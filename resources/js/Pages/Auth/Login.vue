<template>
    <GuestLayout>
        <form @submit.prevent="submit">
            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Вход</h1>

            <Input
                v-model="form.email"
                label="Email"
                name="email"
                type="email"
                autocomplete="email"
                autofocus
                required
                :error="form.errors.email"
            />

            <Input
                v-model="form.password"
                label="Пароль"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                :error="form.errors.password"
            />

            <Button class="w-full" :processing="form.processing">Войти</Button>

            <p class="mt-3 text-sm text-gray-600 text-center">
                <Link href="/forgot-password" class="text-blue-600">Забыли пароль?</Link> ·
                <Link href="/register" class="text-blue-600">Регистрация</Link>
            </p>
        </form>
    </GuestLayout>
</template>

<script setup>
import Button from '../../Components/Button.vue';
import Input from '../../Components/Input.vue';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
});

const submit = () => form.post('/login');
</script>
