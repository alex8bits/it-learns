<template>
    <GuestLayout>
        <form @submit.prevent="submit">
            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Регистрация</h1>

            <Input
                v-model="form.name"
                label="Имя"
                name="name"
                type="text"
                maxlength="255"
                autocomplete="name"
                required
                :error="form.errors.name"
            />

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
                label="Пароль"
                name="password"
                :type="passwordType"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            >
                <template #suffix>
                    <button
                        type="button"
                        class="px-2 py-1.5 text-sm text-gray-600 border border-gray-300 bg-gray-50 rounded hover:bg-gray-100 select-none"
                        aria-label="Показать пароль"
                        @mousedown.prevent="showPasswords"
                        @mouseup="hidePasswords"
                        @mouseleave="hidePasswords"
                        @touchstart.prevent="showPasswords"
                        @touchend="hidePasswords"
                        @touchcancel="hidePasswords"
                        @blur="hidePasswords"
                    >👁</button>
                </template>
            </Input>

            <Input
                v-model="form.password_confirmation"
                label="Подтверждение пароля"
                name="password_confirmation"
                :type="passwordConfirmationType"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            >
                <template #suffix>
                    <button
                        type="button"
                        class="px-2 py-1.5 text-sm text-gray-600 border border-gray-300 bg-gray-50 rounded hover:bg-gray-100 select-none"
                        aria-label="Показать пароль"
                        @mousedown.prevent="showPasswords"
                        @mouseup="hidePasswords"
                        @mouseleave="hidePasswords"
                        @touchstart.prevent="showPasswords"
                        @touchend="hidePasswords"
                        @touchcancel="hidePasswords"
                        @blur="hidePasswords"
                    >👁</button>
                </template>
            </Input>

            <Button class="w-full" :processing="form.processing">
                {{ form.processing ? 'Отправка...' : 'Зарегистрироваться' }}
            </Button>
        </form>
    </GuestLayout>
</template>

<script setup>
import Button from '../../Components/Button.vue';
import Input from '../../Components/Input.vue';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const passwordType = ref('password');
const passwordConfirmationType = ref('password');

const showPasswords = () => {
    passwordType.value = 'text';
    passwordConfirmationType.value = 'text';
};

const hidePasswords = () => {
    passwordType.value = 'password';
    passwordConfirmationType.value = 'password';
};

const submit = () => form.post('/register');
</script>
