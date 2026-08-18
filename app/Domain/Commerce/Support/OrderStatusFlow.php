<?php

namespace App\Domain\Commerce\Support;

final class OrderStatusFlow
{
    /** @var list<string> */
    public const STATUSES = ['nouvelle', 'tentative_1', 'tentative_2', 'tentative_3', 'confirmee', 'annulee'];

    /** @return list<string> */
    public static function transitions(string $status): array
    {
        return $status === 'annulee'
            ? array_values(array_diff(self::STATUSES, ['annulee']))
            : array_values(array_diff(self::STATUSES, [$status]));
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::transitions($from), true);
    }

    public static function canEditItems(string $status): bool
    {
        return $status !== 'annulee';
    }

    public static function restoresStock(string $status): bool
    {
        return $status === 'annulee';
    }
}
