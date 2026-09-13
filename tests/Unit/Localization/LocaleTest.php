<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_testing_environment_defaults_to_russian_locale(): void
    {
        $this->assertSame('ru', app()->getLocale());
    }

    public function test_passwords_sent_flash_message_is_russian(): void
    {
        app()->setLocale('ru');

        $ru = __('passwords.sent');
        $en = app('translator')->get('passwords.sent', [], 'en');

        $this->assertSame('Ссылка для сброса пароля отправлена на электронную почту.', $ru);
        $this->assertNotSame($en, $ru);
    }

    public function test_auth_failed_message_is_russian(): void
    {
        app()->setLocale('ru');

        $ru = __('auth.failed');
        $en = app('translator')->get('auth.failed', [], 'en');

        $this->assertSame('Эти учетные данные не соответствуют нашим записям.', $ru);
        $this->assertNotSame($en, $ru);
    }

    public function test_trans_choice_substitutes_min_placeholder_in_validation_min_string(): void
    {
        app()->setLocale('ru');

        $line = trans_choice('validation.min.string', 3, ['min' => 3]);

        $this->assertStringContainsString('символ', $line);
        $this->assertStringContainsString('3', $line);
        $this->assertStringNotContainsString(':min', $line);
    }

    public function test_validation_attributes_translate_auth_form_fields(): void
    {
        app()->setLocale('ru');

        $attributes = ['name', 'email', 'password', 'password_confirmation', 'role', 'current_password'];

        foreach ($attributes as $attribute) {
            $key = 'validation.attributes.'.$attribute;

            $this->assertNotSame($key, __($key), "Attribute «{$attribute}» must have a Russian translation.");
        }
    }

    public function test_ru_translation_keys_cover_all_en_keys(): void
    {
        foreach (['auth', 'passwords', 'validation'] as $file) {
            $en = require lang_path('en/'.$file.'.php');
            $ru = require lang_path('ru/'.$file.'.php');

            $missing = array_values(array_diff_key($en, $ru));
            $this->assertSame(
                [],
                $missing,
                "lang/ru/{$file}.php is missing keys present in lang/en/{$file}.php."
            );

            foreach ($en as $key => $value) {
                if (is_array($value)) {
                    $nestedMissing = array_values(array_diff_key($value, $ru[$key]));
                    $this->assertSame(
                        [],
                        $nestedMissing,
                        "lang/ru/{$file}.php is missing nested keys of «{$key}» present in lang/en/{$file}.php."
                    );
                }
            }
        }
    }
}
