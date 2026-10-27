<?php

namespace App\Services;

use App\Libraries\PanelTime;
use DateTimeImmutable;
use InvalidArgumentException;

final class DispositivoHorarioService
{
    public static function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    public function habilitada(array $device, string $zone, ?DateTimeImmutable $now = null): bool
    {
        if (($device['estado'] ?? '') !== 'activo' || self::boolean($device['deteccion_pausada'] ?? false)) {
            return false;
        }
        if (! self::boolean($device['horario_configurado'] ?? false)) {
            return true;
        }
        $periods = db_connect()->table('dispositivo_horarios')->where('dispositivo_id', $device['id'])->get()->getResultArray();
        return self::dentro($periods, ($now ?? new DateTimeImmutable())->setTimezone(PanelTime::zone($zone)));
    }

    public static function dentro(array $periods, DateTimeImmutable $local): bool
    {
        $day = (int) $local->format('N');
        $previous = $day === 1 ? 7 : $day - 1;
        $time = $local->format('H:i:s');
        foreach ($periods as $period) {
            $start = substr($period['inicio'], 0, 5) . ':00';
            $end = substr($period['fin'], 0, 5) . ':00';
            $periodDay = (int) $period['dia'];
            if ($start < $end && $periodDay === $day && $time >= $start && $time < $end) {
                return true;
            }
            if ($start > $end && (($periodDay === $day && $time >= $start) || ($periodDay === $previous && $time < $end))) {
                return true;
            }
        }
        return false;
    }

    public static function validar(mixed $rows): array
    {
        if (! is_array($rows) || count($rows) > 100) {
            throw new InvalidArgumentException('Introduce hasta 100 períodos por cámara.');
        }
        $valid = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Período inválido.');
            }
            $day = $row['dia'] ?? '';
            $start = $row['inicio'] ?? '';
            $end = $row['fin'] ?? '';
            if ($day === '' && $start === '' && $end === '') {
                continue;
            }
            if (! is_string($day) || ! preg_match('/^[1-7]$/D', $day)
                || ! is_string($start) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $start)
                || ! is_string($end) || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $end)
                || $start === $end) {
                throw new InvalidArgumentException('Cada período necesita un día y horas distintas válidas (HH:MM).');
            }
            $valid[] = ['dia' => (int) $day, 'inicio' => $start . ':00', 'fin' => $end . ':00'];
        }
        return $valid;
    }
}
