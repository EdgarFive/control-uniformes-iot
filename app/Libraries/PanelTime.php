<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PanelTime
{
    public static function zone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (\Exception) {
            return new DateTimeZone('America/Guatemala');
        }
    }

    public static function utc(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function day(string $value, DateTimeZone $zone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Introduce fechas válidas.');
        }
        return $date;
    }

    public static function display(?string $value, string $zone): string
    {
        return $value ? (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(self::zone($zone))->format('d/m/Y H:i') : 'Sin registro';
    }
}
