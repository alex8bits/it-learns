<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\PaymentStatus;
use App\Http\Requests\Admin\AdminPaymentIndexRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPaymentIndexRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminPaymentIndexRequest)->authorize());
    }

    public function test_rules_cover_all_filters(): void
    {
        $rules = (new AdminPaymentIndexRequest)->rules();

        $this->assertCount(2, $rules['status']);
        $this->assertSame('nullable', $rules['status'][0]);
        $this->assertInstanceOf(Enum::class, $rules['status'][1]);
        $this->assertSame(['nullable', 'string', 'max:255'], $rules['email']);
        $this->assertSame(['nullable', 'date'], $rules['date_from']);
        $this->assertSame(['nullable', 'date', 'after_or_equal:date_from'], $rules['date_to']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminPaymentIndexRequest)->messages();

        $this->assertSame('Недопустимое значение статуса', $messages['status.Illuminate\Validation\Rules\Enum']);
        $this->assertSame('Email должен быть строкой', $messages['email.string']);
        $this->assertSame('Email не может быть длиннее 255 символов', $messages['email.max']);
        $this->assertSame('Дата «от» должна быть корректной датой', $messages['date_from.date']);
        $this->assertSame('Дата «до» должна быть корректной датой', $messages['date_to.date']);
        $this->assertSame('Дата «до» не может быть раньше даты «от»', $messages['date_to.after_or_equal']);
    }

    public function test_valid_filters_pass(): void
    {
        $validator = Validator::make(
            [
                'status' => PaymentStatus::Succeeded->value,
                'email' => 'payer@example.com',
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
            ],
            (new AdminPaymentIndexRequest)->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_each_valid_filter_alone_passes(): void
    {
        $rules = (new AdminPaymentIndexRequest)->rules();

        foreach ([
            ['status' => PaymentStatus::Failed->value],
            ['email' => 'payer@example.com'],
            ['email' => 'payer@'],
            ['date_from' => '2026-01-01'],
            ['date_to' => '2026-12-31'],
        ] as $data) {
            $validator = Validator::make($data, $rules);
            $this->assertTrue($validator->passes(), 'Failed for filter: '.implode(', ', array_keys($data)));
        }
    }

    public function test_empty_filters_pass(): void
    {
        $validator = Validator::make([], (new AdminPaymentIndexRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFilterProvider(): array
    {
        return [
            'unknown status value' => [['status' => 'NotAStatus'], 'status'],
            'email is not a string' => [['email' => ['not', 'a', 'string']], 'email'],
            'email is too long' => [['email' => str_repeat('a', 256)], 'email'],
            'date_from is not a date' => [['date_from' => 'not-a-date'], 'date_from'],
            'date_to is not a date' => [['date_to' => 'not-a-date'], 'date_to'],
            'date_to before date_from' => [
                ['date_from' => '2026-12-31', 'date_to' => '2026-01-01'],
                'date_to',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidFilterProvider')]
    public function test_invalid_filters_fail(array $data, string $expectedErrorField): void
    {
        $validator = Validator::make($data, (new AdminPaymentIndexRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
