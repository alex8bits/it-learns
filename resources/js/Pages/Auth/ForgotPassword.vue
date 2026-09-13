<template>
    <GuestLayout>
        <form @submit.prevent="submit">
            <h1 class="text-2xl font-semibold text-gray-900 mb-4">Восстановление пароля</h1>

            <div v-if="status" class="mb-3 p-2 bg-green-50 text-green-700 text-sm rounded">
                {{ status }}
            </div>

            <p class="text-sm text-gray-600 mb-3">
                Введите email — мы пришлём ссылку для восстановления пароля.
            </p>

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

            <Button class="w-full" :processing="form.processing">Прислать ссылку</Button>

            <p class="mt-3 text-sm text-gray-600 text-center">
                <Link href="/login" class="text-blue-600">Вернуться ко входу</Link>
            </p>
        </form>
    </GuestLayout>
</template>

<script setup>
import Button from '../../Components/Button.vue';
import Input from '../../Components/Input.vue';
import GuestLayout from '../../Layouts/GuestLayout.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const form = useForm({
    email: '',
});

const page = usePage();
const status = computed(() => page.props.status);

const submit = () => form.post('/forgot-password');
</script>
