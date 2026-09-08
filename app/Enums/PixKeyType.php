<?php

declare(strict_types=1);

namespace App\Enums;

use App\Rules\ValidCpf;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Validation\ValidationRule;

enum PixKeyType: string implements HasColor, HasIcon, HasLabel
{
    case Random = 'random';
    case Cpf = 'cpf';
    case Phone = 'phone';
    case Email = 'email';

    public function getLabel(): string
    {
        return match ($this) {
            self::Random => __('enums.pix_key_type.random'),
            self::Cpf => __('enums.pix_key_type.cpf'),
            self::Phone => __('enums.pix_key_type.phone'),
            self::Email => __('enums.pix_key_type.email'),
        };
    }

    public function getColor(): string
    {
        return 'gray';
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Random => 'heroicon-o-key',
            self::Cpf => 'heroicon-o-identification',
            self::Phone => 'heroicon-o-device-phone-mobile',
            self::Email => 'heroicon-o-envelope',
        };
    }

    public function inputPlaceholder(): string
    {
        return match ($this) {
            self::Cpf => '000.000.000-00',
            self::Phone => '11999999999',
            self::Email => 'email@exemplo.com',
            self::Random => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        };
    }

    /**
     * @return array<int, string|ValidationRule>
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::Cpf => [new ValidCpf],
            self::Email => ['email:rfc'],
            self::Phone => ['regex:/^\d{10,13}$/'],
            self::Random => ['uuid'],
        };
    }
}
