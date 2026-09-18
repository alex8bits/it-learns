<script setup>
import Button from '../../../Components/Button.vue';
import Input from '../../../Components/Input.vue';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

defineProps({ statuses: { type: Array, required: true } });

const form = useForm({
    title: '',
    description: '',
    status: 'Draft',
    sort_order: 0,
    preview_image: null,
});

// Local preview of the picked file; the upload itself happens on submit.
const previewUrl = ref(null);

const onPreviewChange = (event) => {
    const file = event.target.files[0];
    form.preview_image = file ?? null;

    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
    previewUrl.value = file ? URL.createObjectURL(file) : null;
};

onBeforeUnmount(() => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
});

const submit = () => {
    // Inertia switches to FormData automatically because of the File.
    form.post('/admin/courses');
};
</script>

<template>
    <AdminLayout>
        <div class="mb-6">
            <Link href="/admin/courses" class="text-sm text-gray-500 hover:text-gray-700">
                ← К списку
            </Link>
        </div>
        <h1 class="text-2xl font-semibold text-gray-900 mb-6">Новый курс</h1>
        <form
            @submit.prevent="submit"
            class="bg-white rounded-lg border border-gray-200 p-6 max-w-2xl"
        >
            <Input
                v-model="form.title"
                label="Название"
                name="title"
                :error="form.errors.title"
                required
            />
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Описание</span>
                <textarea
                    v-model="form.description"
                    rows="5"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                    :class="form.errors.description ? 'border-red-400' : ''"
                ></textarea>
            </label>
            <p v-if="form.errors.description" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.description }}
            </p>
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Статус</span>
                <select
                    v-model="form.status"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md"
                >
                    <option
                        v-for="option in statuses"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </label>
            <p v-if="form.errors.status" class="mb-3 text-xs text-red-600">
                {{ form.errors.status }}
            </p>
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Порядок в каталоге</span>
                <input
                    v-model="form.sort_order"
                    type="number"
                    min="0"
                    class="mt-1 w-24 px-3 py-2 border border-gray-300 rounded-md"
                    :class="form.errors.sort_order ? 'border-red-400' : ''"
                >
                <span class="block mt-1 text-xs text-gray-500">
                    меньше — выше в каталоге; 0 — по дате
                </span>
            </label>
            <p v-if="form.errors.sort_order" class="mb-3 text-xs text-red-600">
                {{ form.errors.sort_order }}
            </p>
            <label class="block mb-3">
                <span class="text-sm text-gray-700">Превью</span>
                <input
                    type="file"
                    accept="image/*"
                    class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                    :class="form.errors.preview_image ? 'border-red-400' : ''"
                    @change="onPreviewChange"
                >
                <span class="block mt-1 text-xs text-gray-500">
                    jpg, jpeg, png или webp, до 2 МБ, не меньше 200×200 пикселей
                </span>
            </label>
            <p v-if="form.errors.preview_image" class="mb-3 text-xs text-red-600 break-words">
                {{ form.errors.preview_image }}
            </p>
            <img
                v-if="previewUrl"
                :src="previewUrl"
                alt="Предпросмотр превью"
                class="mb-3 h-32 w-48 rounded object-cover border border-gray-200"
            >
            <div class="mt-4">
                <Button type="submit" :processing="form.processing">Создать курс</Button>
            </div>
        </form>
    </AdminLayout>
</template>
