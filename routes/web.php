<?php

declare(strict_types=1);

use App\Http\Controllers\Courses\CatalogController;
use App\Http\Controllers\Courses\CourseController;
use App\Http\Controllers\Courses\CourseStartController;
use App\Http\Controllers\Lessons\LessonController;
use App\Http\Controllers\Practice\RequestExtraTaskController;
use App\Http\Controllers\Practice\RequestPracticeFeedbackController;
use App\Http\Controllers\Practice\SubmitPracticeTaskController;
use App\Http\Controllers\Subscription\PaymentWebhookController;
use App\Http\Controllers\Subscription\SubscriptionController;
use App\Http\Controllers\Theory\AnswerTheoryTaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Публичный каталог курсов (Этап 6): `/` и `/courses` — один контроллер,
// гостевая зона без авторизации. Скоуп `published` фильтрует каталог,
// черновики и архивные курсы недоступны (в т.ч. по прямой ссылке — 404).
Route::get('/', [CatalogController::class, 'index'])->name('home');
Route::get('/courses', [CatalogController::class, 'index'])->name('courses.index');
Route::get('/courses/{slug}', [CourseController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('courses.show');

// Публичная страница тарифов (гостевая зона, без авторизации).
Route::get('/pricing', fn () => Inertia::render('Pricing', [
    'premium' => config('payments.premium'),
]))->name('pricing');

// Личный кабинет подписки (авторизованная зона).
Route::middleware('auth:web')->group(function (): void {
    Route::get('/subscription', [SubscriptionController::class, 'index'])->name('subscription');
    Route::post('/subscription/checkout', [SubscriptionController::class, 'checkout'])
        ->middleware('throttle:subscription')->name('subscription.checkout');
    Route::get('/subscription/checkout/return', [SubscriptionController::class, 'return'])
        ->name('subscription.checkout.return');
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel'])
        ->middleware('throttle:subscription')->name('subscription.cancel');

    // Пользовательский submit-флоу практики (Этап 8): implicit binding
    // PracticeTask — определение задания собирается на сервере из
    // модели (спуфинг эталона/сида невозможен), guards публикации
    // тройки дают 404 внутри Action. Ответ — redirect back с
    // одноразовым flash `practice_feedback`.
    Route::post('/practice-tasks/{task}/submit', SubmitPracticeTaskController::class)
        ->middleware('throttle:practice-submit')
        ->name('practice-tasks.submit');

    // Премиум ИИ-флоу практики (Этап 8): явный запрос фидбэка по неудачной
    // попытке и генерация доп. задачи. Оба роута под EnsurePremium (ИИ —
    // только премиум, concept.md §2.3) и под LLM-стоимостным throttle
    // (5/min, лимитёры в AppServiceProvider); исчерпание дневного лимита
    // токенов маппится в HTTP 429 глобальным renderable (bootstrap/app.php),
    // до этого return'а дело не доходит.
    Route::post('/practice-tasks/{task}/ai-feedback', RequestPracticeFeedbackController::class)
        ->middleware(['ensurepremium', 'throttle:ai-feedback'])
        ->name('practice-tasks.ai-feedback');
    Route::post('/practice-tasks/{task}/ai-extra-task', RequestExtraTaskController::class)
        ->middleware(['ensurepremium', 'throttle:ai-extra-task'])
        ->name('practice-tasks.ai-extra-task');

    // Страница урока (Этап 7): материал + теоретический quiz. Lookup по
    // глобально уникальному slug; неопубликованный урок или курс-родитель —
    // 404 внутри контроллера (семантика публичной карточки курса).
    Route::get('/lessons/{slug}', [LessonController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('lessons.show');

    // Старт/возобновление курса: один эндпоинт для «Начать курс» и
    // «Продолжить» — StartCourse резолвит текущий урок и редиректит на него.
    Route::post('/courses/{course}/start', CourseStartController::class)
        ->whereNumber('course')
        ->name('courses.start');

    // Ответ на теоретический вопрос (Этап 7): Inertia POST → redirect back
    // с одноразовым flash `theory_feedback`.
    Route::post('/theory-tasks/{task}/answer', AnswerTheoryTaskController::class)
        ->middleware('throttle:theory-answer')
        ->whereNumber('task')
        ->name('theory-tasks.answer');
});

// Webhook платёжного провайдера: публичный, без CSRF (bootstrap/app.php) —
// полезная нагрузка провайдера валидируется внутри гейта.
Route::post('/subscription/webhook', PaymentWebhookController::class)
    ->middleware('throttle:payment-webhook')->name('subscription.webhook');
