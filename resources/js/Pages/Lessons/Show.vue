<script setup>
import Button from '../../Components/Button.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    lesson: { type: Object, required: true },
    course: { type: Object, required: true },
    // answers: map task_id => { option_id, is_correct } — только ответы
    // текущего пользователя; на сервере остаётся «эталонная» корректность
    // вариантов, сюда приезжает лишь факт «верно/неверно» его выбора.
    answers: { type: Object, required: false, default: () => ({}) },
    // 'InProgress' | 'Completed' | null (null — урок не начат).
    lessonStatus: { type: String, required: false, default: null },
    // Одноразовый flash результата последнего ответа:
    // { task_id, is_correct, error_text? } | null.
    feedback: { type: Object, required: false, default: null },
    // Практические задания урока: {id, statement, expected_result_text,
    // order} — эталонные строки, их хеш и сид-скрипт остаются на сервере.
    practiceTasks: { type: Array, required: false, default: () => [] },
    // Id заданий этого урока, у которых есть Passed-попытка пользователя.
    passedPracticeTaskIds: { type: Array, required: false, default: () => [] },
    // Одноразовый flash последней практической попытки:
    // { task_id, status: 'passed'|'failed'|'error'|'busy',
    //   result: {rows, columns, duration_ms, error}|null,
    //   diff: {expected, actual}|null, error_text: string|null } | null.
    practiceFeedback: { type: Object, required: false, default: null },
    // Одноразовый flash ИИ-фидбэка по неудачной попытке (премиум):
    // { task_id, body } | null.
    aiFeedback: { type: Object, required: false, default: null },
    // Одноразовый flash сгенерированной доп. задачи (премиум):
    // { task_id, task_text, expected_result } | null.
    extraTask: { type: Object, required: false, default: null },
    // Режим предпросмотра из админки (Этап 11): урок показывается
    // «глазами пользователя», включая черновики, — все вопросы и
    // задания списком read-only, без форм, ИИ-кнопок и записи прогресса.
    previewMode: { type: Boolean, required: false, default: false },
});

const isAnsweredCorrectly = (taskId) => props.answers[taskId]?.is_correct === true;

// Текущий вопрос — первый без верного ответа; когда все отвечены верно,
// quiz пройден (что дальше — решает lessonStatus).
const currentTask = computed(
    () => props.lesson.theoryTasks.find((task) => !isAnsweredCorrectly(task.id)) ?? null,
);
const solvedTasks = computed(() => props.lesson.theoryTasks.filter((task) => isAnsweredCorrectly(task.id)));

// Порядок вариантов в БД не несёт смысла: в авторских md правильный
// вариант (✅) часто записан первым, и ученик привыкает угадывать его
// по позиции. Поэтому в интерактивной карточке варианты перемешиваем
// (Fisher-Yates — равномерные перестановки; sort(() => Math.random() - 0.5)
// даёт смещённое распределение и потому запрещён).
const shuffleOptions = (options) => {
    const shuffled = [...options];
    for (let i = shuffled.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
    }
    return shuffled;
};

// Перемешиваем один раз на вопрос и кэшируем по task.id: после
// POST → 303 back (Inertia-revisit, компонент не пересоздаётся) порядок
// не должен «прыгать» между рендерами. Мутируем копию, props не трогаем;
// полная перезагрузка страницы = новый компонент = новый порядок.
// Preview-список всех вопросов остаётся в авторском порядке — это
// инструмент автора.
const shuffledOptionsCache = new Map();

const optionsForCard = (task) => {
    if (!shuffledOptionsCache.has(task.id)) {
        shuffledOptionsCache.set(task.id, shuffleOptions(task.options));
    }

    return shuffledOptionsCache.get(task.id);
};

// Flash теории показываем по тому же паттерну, что и практика
// (shownPracticeFeedback): «Неверно» — только на текущем вопросе
// (после неверного ответа вопрос не смещается, task_id совпадает);
// «Верно» — относится к только что зачтённому вопросу, поэтому
// показываем его баннером уровня секции, над карточкой вопроса, —
// а не на новом неотвеченном вопросе.
const shownTheoryFeedback = computed(() => {
    const feedback = props.feedback;
    if (!feedback) {
        return null;
    }

    if (feedback.is_correct === false) {
        return feedback.task_id === currentTask.value?.id ? feedback : null;
    }

    return isAnsweredCorrectly(feedback.task_id) ? feedback : null;
});

// Практика — строго после теории: секция видна, когда текущий теоретический
// вопрос отсутствует (теория пройдена или её в уроке нет — оба случая
// корректны); без практических заданий блок не рендерится вовсе.
const practiceVisible = computed(() => currentTask.value === null && props.practiceTasks.length > 0);

// Текущее практическое задание — первое без Passed-попытки
// (последовательность — UI-уровень, как и в quiz теории).
const currentPracticeTask = computed(
    () => props.practiceTasks.find((task) => !props.passedPracticeTaskIds.includes(task.id)) ?? null,
);
const passedPracticeCount = computed(() => props.passedPracticeTaskIds.length);

// Flash показываем, только если он относится к текущему заданию
// (failed/error/busy — задание не сместилось) или к только что решённому
// (passed — флоу уже перешёл дальше, вплоть до «Практика пройдена»).
const shownPracticeFeedback = computed(() => {
    const feedback = props.practiceFeedback;
    if (!feedback) {
        return null;
    }

    const isForCurrentTask = feedback.task_id === currentPracticeTask.value?.id;
    const isJustPassed =
        feedback.status === 'passed' && props.passedPracticeTaskIds.includes(feedback.task_id);

    return isForCurrentTask || isJustPassed ? feedback : null;
});

// Колонки таблицы диффа — ключи первой строки результата.
const diffColumns = (rows) => (Array.isArray(rows) && rows.length > 0 ? Object.keys(rows[0]) : []);

const diffSides = computed(() => {
    const feedback = shownPracticeFeedback.value;
    if (!feedback || feedback.status !== 'failed' || !feedback.diff) {
        return [];
    }

    return [
        { title: 'Эталон', rows: feedback.diff.expected ?? [] },
        { title: 'Ваш результат', rows: feedback.diff.actual ?? [] },
    ];
});

const form = useForm({ option_id: null });

const submit = () => {
    // POST → 303 back: страница перечитает answers/progress/feedback
    // на сервере, клиентское состояние не дублируется.
    form.post(`/theory-tasks/${currentTask.value.id}/answer`);
};

const practiceForm = useForm({ code: '' });

const submitPractice = () => {
    // POST → 303 back: страница перечитает passedPracticeTaskIds и flash
    // practice_feedback на сервере (тот же паттерн, что и quiz-форма).
    practiceForm.post(`/practice-tasks/${currentPracticeTask.value.id}/submit`);
};

// ---------- Премиум ИИ (Этап 8) ----------

const page = usePage();

// Гость на странице урока невозможен (auth-роут) — auth.user всегда есть.
const isPremium = computed(() => page.props.auth.user?.is_premium === true);

// Ошибка лимита ИИ приходит из page-level errors (глобальный renderable
// в bootstrap/app.php делает redirect back withErrors(['ai' => ...])),
// а не из flash — токены при отказе не списываются.
const aiError = computed(() => page.props.errors?.ai ?? null);

// Фидбэк ИИ показываем, только если он относится к текущему заданию
// (после неудачной попытки задание не смещается).
const shownAiFeedback = computed(() => {
    const feedback = props.aiFeedback;
    if (!feedback || feedback.task_id !== currentPracticeTask.value?.id) {
        return null;
    }

    return feedback;
});

// Кнопка фидбэка — после неудачной попытки (failed/error) и только для
// премиума; free-пользователь ИИ-кнопок не видит (гейтинг — EnsurePremium
// на сервере, здесь только видимость). В previewMode — двойной гейт:
// интерактивные действия отключены целиком.
const canRequestAiFeedback = computed(
    () =>
        !props.previewMode &&
        isPremium.value &&
        shownPracticeFeedback.value !== null &&
        ['failed', 'error'].includes(shownPracticeFeedback.value.status),
);

const aiFeedbackPending = ref(false);
const extraTaskPending = ref(false);

const requestAiFeedback = () => {
    // POST → 303 back: страница перечитает flash ai_feedback (или errors.ai
    // при исчерпании лимита — 429-маппинг) на сервере.
    aiFeedbackPending.value = true;
    router.post(`/practice-tasks/${shownPracticeFeedback.value.task_id}/ai-feedback`, {}, {
        onFinish: () => {
            aiFeedbackPending.value = false;
        },
    });
};

const requestExtraTask = () => {
    // POST → 303 back: страница перечитает flash extra_task; доп. задача
    // только показывается, никуда не сохраняется.
    extraTaskPending.value = true;
    router.post(`/practice-tasks/${currentPracticeTask.value.id}/ai-extra-task`, {}, {
        onFinish: () => {
            extraTaskPending.value = false;
        },
    });
};

// ---------- Стадии урока (материал → теория → практика) ----------

// Стадии урока: сначала чтение материала, затем теория, затем практика
// (concept.md §3.3). Поэтапность — UI-уровень (преемственность Этапов
// 7–8): сервер отдаёт все данные одним ответом (LessonController),
// клиент лишь решает, какую стадию показывать.
const STAGES = { MATERIAL: 'material', THEORY: 'theory', PRACTICE: 'practice' };

const hasTheoryTasks = computed(() => props.lesson.theoryTasks.length > 0);
const hasPracticeTasks = computed(() => props.practiceTasks.length > 0);

// Любая активность означает, что пользователь уже миновал стадию чтения:
// начальная стадия для него — не материал (после полной перезагрузки
// страницы он не должен возвращаться к обязательному чтению).
const hasAnyActivity = computed(
    () =>
        Object.keys(props.answers).length > 0 ||
        props.passedPracticeTaskIds.length > 0 ||
        props.lessonStatus !== null,
);

// Начальная стадия: свежий пользователь — материал; далее — там, где
// остановился: первый неотвеченный вопрос теории, иначе практика
// (если есть), иначе список решённой теории (теория-only урок).
const initialStage = computed(() => {
    if (!hasAnyActivity.value) {
        return STAGES.MATERIAL;
    }
    if (currentTask.value !== null) {
        return STAGES.THEORY;
    }
    if (hasPracticeTasks.value) {
        return STAGES.PRACTICE;
    }
    return STAGES.THEORY;
});

const activeStage = ref(initialStage.value);

// Материал «пройден», если пользователь уже был дальше него в этой
// сессии (начальная стадия не материал) или нажал CTA на material-стадии.
// Ref переживает Inertia POST → 303 back (компонент не пересоздаётся),
// сбрасывается только полной перезагрузкой браузера.
const materialAcknowledged = ref(initialStage.value !== STAGES.MATERIAL);

const theoryTabEnabled = computed(() => materialAcknowledged.value && hasTheoryTasks.value);
const practiceTabEnabled = computed(
    () => materialAcknowledged.value && practiceVisible.value,
);

// Подсказки заблокированных табов — почему стадия ещё недоступна
// (в preview блокировок нет — подсказок тоже).
const theoryTabTitle = computed(() =>
    !props.previewMode && !theoryTabEnabled.value ? 'Сначала изучите материал' : null,
);

const practiceTabTitle = computed(() => {
    if (props.previewMode || practiceTabEnabled.value) {
        return null;
    }

    return materialAcknowledged.value ? 'Доступна после теории' : 'Сначала изучите материал';
});

const goToStage = (stage) => {
    activeStage.value = stage;
};

const proceedFromMaterial = () => {
    materialAcknowledged.value = true;
    activeStage.value = hasTheoryTasks.value ? STAGES.THEORY : STAGES.PRACTICE;
};

// Автопереход по concept.md §4: после верного ответа на последний
// теоретический вопрос (POST → 303 back с обновлёнными answers)
// пользователь попадает к практике.
watch(
    () => currentTask.value,
    () => {
        if (
            activeStage.value === STAGES.THEORY &&
            currentTask.value === null &&
            hasPracticeTasks.value
        ) {
            activeStage.value = STAGES.PRACTICE;
        }
    },
);
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <header class="bg-white border-b border-gray-200">
            <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
                <Link href="/" class="text-xl font-bold text-gray-900">it-learns</Link>
                <nav class="flex gap-3 text-sm">
                    <Link href="/courses" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                        Каталог
                    </Link>
                    <Link href="/dashboard" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                        Кабинет
                    </Link>
                </nav>
            </div>
        </header>

        <main class="max-w-4xl mx-auto px-4 py-10">
            <div
                v-if="previewMode"
                class="mb-6 rounded bg-yellow-50 border border-yellow-300 px-4 py-3 text-sm text-yellow-800"
            >
                <span class="font-medium">Режим предпросмотра (глазами пользователя)</span>
                — действия отключены, прогресс не записывается.
            </div>

            <Link
                :href="previewMode ? `/admin/courses/${course.id}/preview` : `/courses/${course.slug}`"
                class="text-sm text-blue-600 hover:underline"
            >
                ← {{ course.title }}
            </Link>

            <section class="mt-4 bg-white rounded-lg shadow border border-gray-200 p-6">
                <div class="flex items-center justify-between gap-4">
                    <h1 class="text-3xl font-bold text-gray-900">{{ lesson.title }}</h1>
                    <span
                        v-if="previewMode && lesson.is_published === false"
                        class="shrink-0 px-3 py-1 bg-gray-100 text-gray-600 border border-gray-200 rounded-full text-xs"
                    >
                        Черновик
                    </span>
                    <span
                        v-if="lessonStatus === 'Completed'"
                        class="shrink-0 px-3 py-1 bg-green-50 text-green-700 border border-green-200 rounded-full text-xs"
                    >
                        Урок пройден
                    </span>
                </div>
            </section>

            <!-- Бар стадий: поэтапный флоу «материал → теория → практика»
                 (concept.md §3.3). Таб «Материал» доступен всегда; в
                 обычном режиме теория и практика блокируются, пока
                 предыдущая стадия не пройдена; в preview гейтинга нет. -->
            <div class="mt-6 flex flex-wrap gap-2">
                <button
                    type="button"
                    class="px-4 py-2 rounded border text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-white"
                    :class="activeStage === STAGES.MATERIAL ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'"
                    @click="goToStage(STAGES.MATERIAL)"
                >
                    1. Материал
                </button>
                <button
                    v-if="previewMode || hasTheoryTasks"
                    type="button"
                    class="px-4 py-2 rounded border text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-white"
                    :class="activeStage === STAGES.THEORY ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'"
                    :disabled="!previewMode && !theoryTabEnabled"
                    :title="theoryTabTitle"
                    @click="goToStage(STAGES.THEORY)"
                >
                    2. Теория
                </button>
                <button
                    v-if="hasPracticeTasks"
                    type="button"
                    class="px-4 py-2 rounded border text-sm font-medium disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-white"
                    :class="activeStage === STAGES.PRACTICE ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'"
                    :disabled="!previewMode && !practiceTabEnabled"
                    :title="practiceTabTitle"
                    @click="goToStage(STAGES.PRACTICE)"
                >
                    3. Практика
                </button>
            </div>

            <!-- Стадия «Материал»: смонтирована всегда, видимость — v-show,
                 поэтому возврат к материалу с любой стадии не размонтирует
                 остальные секции (введённый ввод живёт в их формах). -->
            <section
                v-show="activeStage === STAGES.MATERIAL"
                class="mt-6 bg-white rounded-lg shadow border border-gray-200 p-6"
            >
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Материал</h2>
                <p v-if="lesson.material" class="text-gray-700 whitespace-pre-line">{{ lesson.material }}</p>
                <p v-else class="text-gray-500">В этом уроке нет материала для чтения</p>

                <!-- CTA снимает блокировку заданий и открывает следующую
                     доступную стадию: теорию, а без теоретических заданий —
                     практику. Уроку без заданий вовсе CTA не нужен; в
                     preview его тоже нет. -->
                <div v-if="!previewMode && (hasTheoryTasks || hasPracticeTasks)" class="mt-6">
                    <Button type="button" @click="proceedFromMaterial">
                        {{ hasTheoryTasks ? 'Перейти к теории' : 'Перейти к практике' }}
                    </Button>
                </div>
            </section>

            <!-- Стадия «Теория»: v-if — пока материал не пройден, секции в
                 DOM нет; после разблокировки — v-show, чтобы выбранный radio
                 переживал уход к материалу и возврат. -->
            <section v-if="theoryTabEnabled || previewMode" v-show="activeStage === STAGES.THEORY" class="mt-8">
                <div class="mb-4">
                    <Button type="button" variant="secondary" @click="goToStage(STAGES.MATERIAL)">
                        Открыть материал
                    </Button>
                </div>

                <h2 class="text-xl font-semibold text-gray-900 mb-4">Теоретические задания</h2>

                <!-- Preview: все вопросы урока списком read-only, без
                     radio-выбора и кнопки «Ответить». -->
                <template v-if="previewMode">
                    <div
                        v-for="task in lesson.theoryTasks"
                        :key="task.id"
                        class="bg-white rounded-lg shadow border border-gray-200 p-6 mb-4"
                    >
                        <div class="flex items-start justify-between gap-3 mb-1">
                            <p class="text-sm text-gray-500">Вопрос {{ task.order }}</p>
                            <span
                                v-if="task.is_published === false"
                                class="shrink-0 px-2 py-0.5 bg-gray-100 text-gray-600 border border-gray-200 rounded text-xs"
                            >
                                Черновик
                            </span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ task.question }}</h3>
                        <ul class="space-y-2">
                            <li
                                v-for="option in task.options"
                                :key="option.id"
                                class="px-4 py-3 rounded border border-gray-200 text-sm text-gray-700"
                            >
                                {{ option.text }}
                            </li>
                        </ul>
                    </div>
                    <p v-if="lesson.theoryTasks.length === 0" class="text-sm text-gray-500">
                        Теоретических заданий в уроке нет.
                    </p>
                </template>

                <template v-else>
                    <div v-if="solvedTasks.length > 0" class="bg-white rounded-lg border border-gray-200 p-6 mb-4">
                        <h3 class="text-sm font-medium text-gray-500 mb-2">Отвечено верно</h3>
                        <ul class="space-y-1">
                            <li
                                v-for="task in solvedTasks"
                                :key="task.id"
                                class="flex items-start gap-2 text-sm text-gray-600"
                            >
                                <span class="text-green-600">✓</span>
                                {{ task.question }}
                            </li>
                        </ul>
                    </div>

                    <p
                        v-if="shownTheoryFeedback && shownTheoryFeedback.is_correct === false"
                        class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm"
                    >
                        Неверно: {{ shownTheoryFeedback.error_text }}
                    </p>
                    <p
                        v-else-if="shownTheoryFeedback && shownTheoryFeedback.is_correct === true"
                        class="mb-4 rounded bg-green-50 border border-green-200 text-green-700 px-4 py-3 text-sm"
                    >
                        Верно!
                    </p>

                    <div v-if="currentTask" class="bg-white rounded-lg shadow border border-gray-200 p-6">
                        <p class="text-sm text-gray-500 mb-2">Вопрос {{ currentTask.order }}</p>
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ currentTask.question }}</h3>

                        <form @submit.prevent="submit">
                            <label
                                v-for="option in optionsForCard(currentTask)"
                                :key="option.id"
                                class="flex items-start gap-3 mb-3 px-4 py-3 rounded border border-gray-200 cursor-pointer hover:bg-gray-50"
                                :class="form.option_id === option.id ? 'border-blue-400 bg-blue-50' : ''"
                            >
                                <input v-model="form.option_id" type="radio" name="option" :value="option.id" class="mt-1" />
                                <span class="text-sm text-gray-700">{{ option.text }}</span>
                            </label>

                            <p v-if="form.errors.option_id" class="mb-3 text-sm text-red-600">
                                {{ form.errors.option_id }}
                            </p>

                            <Button type="submit" :processing="form.processing" :disabled="form.option_id === null">
                                Ответить
                            </Button>
                        </form>
                    </div>
                </template>
            </section>

            <!-- Стадия «Практика»: v-if — секции нет в DOM, пока стадия
                 заблокирована (материал не пройден или теория не отвечена);
                 v-show — набранный SQL в textarea переживает просмотр
                 материала и возврат. -->
            <section v-if="practiceTabEnabled || previewMode" v-show="activeStage === STAGES.PRACTICE" class="mt-8">
                <div class="mb-4">
                    <Button type="button" variant="secondary" @click="goToStage(STAGES.MATERIAL)">
                        Открыть материал
                    </Button>
                </div>

                <div class="flex items-baseline justify-between gap-4 mb-4">
                    <h2 class="text-xl font-semibold text-gray-900">Практические задания</h2>
                    <p v-if="!previewMode" class="text-sm text-gray-500">
                        Решено: {{ passedPracticeCount }} из {{ practiceTasks.length }}
                    </p>
                </div>

                <!-- Обычный режим: интерактивный флоу с формой SQL,
                     ИИ-кнопками и flash-блоками попыток. В preview эта
                     ветка не рендерится вовсе. -->
                <template v-if="!previewMode">
                    <p
                        v-if="aiError"
                        class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm"
                    >
                        {{ aiError }}
                    </p>

                <p
                    v-if="shownPracticeFeedback && shownPracticeFeedback.status === 'busy'"
                    class="mb-4 rounded bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 text-sm"
                >
                    Уже выполняется предыдущая попытка — попробуйте чуть позже.
                </p>
                <p
                    v-else-if="shownPracticeFeedback && shownPracticeFeedback.status === 'passed'"
                    class="mb-4 rounded bg-green-50 border border-green-200 text-green-700 px-4 py-3 text-sm"
                >
                    Верно!
                </p>
                <div
                    v-else-if="shownPracticeFeedback && shownPracticeFeedback.status === 'error'"
                    class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm"
                >
                    <p class="font-medium mb-1">Ошибка исполнения</p>
                    <p v-if="shownPracticeFeedback.error_text" class="whitespace-pre-line">
                        {{ shownPracticeFeedback.error_text }}
                    </p>
                </div>
                <div
                    v-else-if="shownPracticeFeedback && shownPracticeFeedback.status === 'failed'"
                    class="mb-4 rounded bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm"
                >
                    <p class="font-medium mb-2">Неверно — результат не совпал с эталоном.</p>
                    <p v-if="shownPracticeFeedback.result && shownPracticeFeedback.result.error" class="mb-2">
                        {{ shownPracticeFeedback.result.error }}
                    </p>

                    <div v-if="diffSides.length > 0" class="grid gap-4 md:grid-cols-2">
                        <div v-for="side in diffSides" :key="side.title">
                            <p class="font-medium text-gray-700 mb-1">{{ side.title }}</p>
                            <table v-if="side.rows.length > 0" class="w-full text-left border border-gray-200 text-xs">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th
                                            v-for="column in diffColumns(side.rows)"
                                            :key="column"
                                            class="border-b border-gray-200 px-2 py-1 font-medium text-gray-600"
                                        >
                                            {{ column }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, rowIndex) in side.rows" :key="rowIndex">
                                        <td
                                            v-for="column in diffColumns(side.rows)"
                                            :key="column"
                                            class="border-b border-gray-100 px-2 py-1 text-gray-700"
                                        >
                                            {{ row[column] }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="text-xs text-gray-500">Запрос не вернул строк</p>
                        </div>
                    </div>
                </div>

                <div v-if="canRequestAiFeedback" class="mb-4">
                    <Button
                        type="button"
                        variant="secondary"
                        :processing="aiFeedbackPending"
                        @click="requestAiFeedback"
                    >
                        Запросить ИИ-фидбэк
                    </Button>
                </div>

                <div v-if="shownAiFeedback" class="mb-4 bg-white rounded-lg border border-blue-200 p-6">
                    <h3 class="text-sm font-medium text-blue-700 mb-2">ИИ-фидбэк по попытке</h3>
                    <p class="text-sm text-gray-700 whitespace-pre-line">{{ shownAiFeedback.body }}</p>
                </div>

                <div v-if="extraTask" class="mb-4 bg-white rounded-lg border border-indigo-200 p-6">
                    <h3 class="text-sm font-medium text-indigo-700 mb-2">Дополнительная задача</h3>
                    <p class="text-base text-gray-900 mb-2">{{ extraTask.task_text }}</p>
                    <p v-if="extraTask.expected_result" class="text-sm text-gray-600">
                        Ожидаемый результат: {{ extraTask.expected_result }}
                    </p>
                </div>

                <div v-if="currentPracticeTask" class="bg-white rounded-lg shadow border border-gray-200 p-6">
                    <p class="text-sm text-gray-500 mb-2">Задание {{ currentPracticeTask.order }}</p>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ currentPracticeTask.statement }}</h3>
                    <p class="text-sm text-gray-600 mb-4">
                        Ожидаемый результат: {{ currentPracticeTask.expected_result_text }}
                    </p>

                    <form @submit.prevent="submitPractice">
                        <label class="block mb-3">
                            <span class="text-sm text-gray-700">SQL-запрос</span>
                            <textarea
                                v-model="practiceForm.code"
                                rows="6"
                                placeholder="SELECT ..."
                                class="mt-1 w-full px-3 py-2 border rounded-md font-mono text-sm bg-gray-50 focus:bg-white focus:outline-none focus:ring-1 focus:ring-blue-400"
                                :class="practiceForm.errors.code ? 'border-red-400' : 'border-gray-300'"
                            ></textarea>
                        </label>
                        <!-- Мульти-стейтменты разрешены (docker-драйвер):
                             зачёт по result set последней инструкции. -->
                        <p class="mb-3 text-xs text-gray-500">
                            Можно несколько инструкций, разделённых «;». Зачёт по результату последней.
                        </p>
                        <p v-if="practiceForm.errors.code" class="mb-3 text-xs text-red-600 break-words">
                            {{ practiceForm.errors.code }}
                        </p>

                        <Button type="submit" :processing="practiceForm.processing">Отправить решение</Button>
                    </form>

                    <div v-if="isPremium" class="mt-4 pt-4 border-t border-gray-200">
                        <Button
                            type="button"
                            variant="secondary"
                            :processing="extraTaskPending"
                            @click="requestExtraTask"
                        >
                            Сгенерировать дополнительную задачу
                        </Button>
                    </div>
                </div>

                <div v-else class="bg-white rounded-lg shadow border border-gray-200 p-6">
                    <p class="text-lg font-semibold text-gray-900 mb-2">Практика пройдена</p>
                    <p class="text-sm text-gray-500">Все практические задания урока решены.</p>
                </div>
                </template>

                <template v-else>
                    <!-- Preview: все задания урока списком read-only — только
                         формулировка и ожидаемый результат, без формы SQL и
                         ИИ-кнопок. -->
                    <div
                        v-for="task in practiceTasks"
                        :key="task.id"
                        class="bg-white rounded-lg shadow border border-gray-200 p-6 mb-4"
                    >
                        <div class="flex items-start justify-between gap-3 mb-2">
                            <p class="text-sm text-gray-500">Задание {{ task.order }}</p>
                            <span
                                v-if="task.is_published === false"
                                class="shrink-0 px-2 py-0.5 bg-gray-100 text-gray-600 border border-gray-200 rounded text-xs"
                            >
                                Черновик
                            </span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ task.statement }}</h3>
                        <p class="text-sm text-gray-600">
                            Ожидаемый результат: {{ task.expected_result_text }}
                        </p>
                    </div>
                </template>
            </section>
        </main>
    </div>
</template>
