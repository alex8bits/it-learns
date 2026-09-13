<template>
    <GuestLayout>
        <form @submit.prevent="submit">
            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Подтверждение пароля</h1>

            <Input
                v-model="form.password"
                label="Пароль"
                name="password"
                type="password"
                autocomplete="current-password"
                autofocus
                required
                :error="form.errors.password"
            />

            <Button class="w-full" :processing="form.processing">Подтвердить</Button>
        </form>
    </GuestLayout>
</template>

<script setup>
import Button from '../../Components/Button.vue';
import Input from '../../Components/Input.vue';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => form.post('/user/confirm-password');
</script>
