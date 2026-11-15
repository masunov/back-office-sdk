<?php

declare(strict_types=1);

namespace Mnd\BackOffice\Enum;

/**
 * The units of the nomenclature of the warehouse: the list of the service (`GET /v1/warehouse/item-units`), the same
 * for every project. The service takes the Russian names too («шт», «кг.», «бутылка» — `fromInput`) and answers with
 * the code; `label()` is what to show. Units of one dimension convert into each other (250 g = 0.25 kg); a piece, a
 * pack and a bottle are each their own count. A pack or a bottle may hold a weight or a volume — then the stock of
 * its item is in kg or l (`WarehouseItem::$stockUnit`).
 */
enum ItemUnit: string
{
    case Piece      = 'pcs';
    case Pack       = 'pack';
    case Bottle     = 'bottle';
    case Kilogram   = 'kg';
    case Gram       = 'g';
    case Litre      = 'l';
    case Millilitre = 'ml';
    case Metre      = 'm';

    /**
     * The unit of a code or of a spelling the service takes, null — not a unit of the list.
     */
    public static function fromInput(string $input): ?self
    {
        $spelling = rtrim(mb_strtolower(trim($input)), '.');

        foreach (self::cases() as $unit) {
            if (\in_array($spelling, $unit->spellings(), true)) {
                return $unit;
            }
        }

        return null;
    }

    /**
     * @return list<string> lower case, without a dot
     */
    public function spellings(): array
    {
        return match ($this) {
            self::Piece      => ['pcs', 'pc', 'piece', 'pieces', 'ea', 'шт', 'штука', 'штуки', 'штук'],
            self::Pack       => ['pack', 'pkg', 'упак', 'уп', 'упаковка', 'упаковки'],
            self::Bottle     => ['bottle', 'бут', 'бутылка', 'бутылки'],
            self::Kilogram   => ['kg', 'кг'],
            self::Gram       => ['g', 'г', 'гр'],
            self::Litre      => ['l', 'л', 'литр', 'литры', 'литров'],
            self::Millilitre => ['ml', 'мл'],
            self::Metre      => ['m', 'м', 'метр', 'метры', 'метров'],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Piece      => 'шт',
            self::Pack       => 'упак',
            self::Bottle     => 'бут',
            self::Kilogram   => 'кг',
            self::Gram       => 'г',
            self::Litre      => 'л',
            self::Millilitre => 'мл',
            self::Metre      => 'м',
        };
    }

    /**
     * @return 'count'|'mass'|'volume'|'length'
     */
    public function dimension(): string
    {
        return match ($this) {
            self::Piece, self::Pack, self::Bottle => 'count',
            self::Kilogram, self::Gram            => 'mass',
            self::Litre, self::Millilitre         => 'volume',
            self::Metre                           => 'length',
        };
    }

    public function baseUnit(): self
    {
        return match ($this) {
            self::Gram       => self::Kilogram,
            self::Millilitre => self::Litre,
            default          => $this,
        };
    }

    /**
     * How many base units one of it is: a gram is 0.001 kg.
     */
    public function factor(): string
    {
        return $this->baseUnit() === $this ? '1' : '0.001';
    }

    /**
     * A quantity (a string with up to 3 decimals, as the service gives it) in another unit, or null when they
     * measure different things or the result has more than 3 decimals (0.5 g in kg).
     */
    public function convert(string $quantity, self $to): ?string
    {
        if ($this->baseUnit() !== $to->baseUnit() || preg_match('/^(\d{1,11})(?:\.(\d{1,3}))?$/', $quantity, $m) !== 1) {
            return null;
        }

        // In thousandths: the factors are 1 and 0.001, so a conversion is a shift by three digits.
        $thousandths = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
        $shift       = ($this->factor() === '1' ? 0 : -3) - ($to->factor() === '1' ? 0 : -3);

        if ($shift < 0) {
            if ($thousandths % 1000 !== 0) {
                return null;
            }

            $thousandths = intdiv($thousandths, 1000);
        } elseif ($shift > 0) {
            $thousandths *= 1000;
        }

        return intdiv($thousandths, 1000) . '.' . str_pad((string) ($thousandths % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * May hold a weight or a volume (`WarehouseItemInput::$packageUnit`).
     */
    public function isPackaging(): bool
    {
        return $this === self::Pack || $this === self::Bottle;
    }

    /**
     * May be what a pack or a bottle holds: kg or l.
     */
    public function isPackageMeasure(): bool
    {
        return $this === self::Kilogram || $this === self::Litre;
    }
}
