<?php

declare(strict_types=1);

namespace App\Integrations\Spreadsheet;

use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class SpreadsheetCell
{
    public const TYPE_TEXT = 'text';

    public const TYPE_MONEY = 'money';

    public const TYPE_NUMBER = 'number';

    public const TYPE_DATE = 'date';

    public const TYPE_DATE_TIME = 'date_time';

    public const TYPE_LINK = 'link';

    /** Excel rejects HYPERLINK arguments longer than this. */
    public const MAX_LINK_LENGTH = 255;

    private function __construct(
        public string $type,
        public string|int|float|DateTimeInterface|null $value,
        public ?string $label = null,
    ) {}

    public static function text(?string $value): self
    {
        return new self(self::TYPE_TEXT, $value ?? '');
    }

    public static function money(string|int|float|null $value): self
    {
        return $value === null || $value === ''
            ? self::text(null)
            : new self(self::TYPE_MONEY, (float) $value);
    }

    public static function number(int|float|null $value): self
    {
        return $value === null ? self::text(null) : new self(self::TYPE_NUMBER, $value);
    }

    public static function date(?DateTimeInterface $value): self
    {
        return $value === null ? self::text(null) : new self(self::TYPE_DATE, $value);
    }

    public static function dateTime(?DateTimeInterface $value): self
    {
        return $value === null ? self::text(null) : new self(self::TYPE_DATE_TIME, $value);
    }

    public static function link(string $url, string $label): self
    {
        if (strlen($url) > self::MAX_LINK_LENGTH) {
            Log::warning('Spreadsheet hyperlink URL exceeds the Excel limit; written as text.', [
                'length' => strlen($url),
            ]);

            return self::text($url);
        }

        return new self(self::TYPE_LINK, $url, Str::limit($label, self::MAX_LINK_LENGTH, ''));
    }

    public function formula(): string
    {
        $escape = static fn (string $value): string => str_replace('"', '""', $value);

        return sprintf('=HYPERLINK("%s","%s")', $escape((string) $this->value), $escape((string) $this->label));
    }
}
