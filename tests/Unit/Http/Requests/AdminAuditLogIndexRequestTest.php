<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\AdminAuditAction;
use App\Http\Requests\Admin\AdminAuditLogIndexRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuditLogIndexRequestTest extends TestCase
{
    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new AdminAuditLogIndexRequest)->authorize());
    }

    public function test_rules_cover_all_filters(): void
    {
        $rules = (new AdminAuditLogIndexRequest)->rules();

        $this->assertCount(2, $rules['action']);
        $this->assertSame('nullable', $rules['action'][0]);
        $this->assertInstanceOf(Enum::class, $rules['action'][1]);
        $this->assertSame(['nullable', 'integer', 'min:1'], $rules['admin_id']);
        $this->assertSame(['nullable', 'date'], $rules['date_from']);
        $this->assertSame(['nullable', 'date', 'after_or_equal:date_from'], $rules['date_to']);
    }

    public function test_messages_are_human_readable(): void
    {
        $messages = (new AdminAuditLogIndexRequest)->messages();

        $this->assertSame('Недопустимое значение действия', $messages['action.Illuminate\Validation\Rules\Enum']);
        $this->assertSame('Идентификатор администратора должен быть целым числом', $messages['admin_id.integer']);
        $this->assertSame('Идентификатор администратора должен быть не меньше 1', $messages['admin_id.min']);
        $this->assertSame('Дата «от» должна быть корректной датой', $messages['date_from.date']);
        $this->assertSame('Дата «до» должна быть корректной датой', $messages['date_to.date']);
        $this->assertSame('Дата «до» не может быть раньше даты «от»', $messages['date_to.after_or_equal']);
    }

    public function test_valid_filters_pass(): void
    {
        $validator = Validator::make(
            [
                'action' => AdminAuditAction::UserRoleChanged->value,
                'admin_id' => 1,
                'date_from' => '2026-01-01',
                'date_to' => '2026-12-31',
            ],
            (new AdminAuditLogIndexRequest)->rules(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_each_valid_filter_alone_passes(): void
    {
        $rules = (new AdminAuditLogIndexRequest)->rules();

        foreach ([
            ['action' => AdminAuditAction::UserBlocked->value],
            ['admin_id' => 7],
            ['admin_id' => '7'],
            ['date_from' => '2026-01-01'],
            ['date_to' => '2026-12-31'],
        ] as $data) {
            $validator = Validator::make($data, $rules);
            $this->assertTrue($validator->passes(), 'Failed for filter: '.implode(', ', array_keys($data)));
        }
    }

    public function test_empty_filters_pass(): void
    {
        $validator = Validator::make([], (new AdminAuditLogIndexRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFilterProvider(): array
    {
        return [
            'unknown action value' => [['action' => 'NotAnAction'], 'action'],
            'negative admin id' => [['admin_id' => -1], 'admin_id'],
            'zero admin id' => [['admin_id' => 0], 'admin_id'],
            'non-numeric admin id' => [['admin_id' => 'abc'], 'admin_id'],
            'float admin id' => [['admin_id' => 2.5], 'admin_id'],
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
        $validator = Validator::make($data, (new AdminAuditLogIndexRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($expectedErrorField));
    }
}
