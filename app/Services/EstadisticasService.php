<?php

namespace App\Services;

use App\Libraries\PanelTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class EstadisticasService
{
    public function porHora(int $company, string $zone, string $from, string $to): array
    {
        $timezone = PanelTime::zone($zone);
        $start = PanelTime::day($from, $timezone);
        $last = PanelTime::day($to, $timezone);
        if ($last < $start || (int) $start->diff($last)->format('%a') > 2) {
            throw new InvalidArgumentException('Selecciona entre uno y tres días, incluyendo ambas fechas.');
        }
        $series = [];
        for ($day = $start; $day <= $last; $day = $day->modify('+1 day')) {
            $series[$day->format('Y-m-d')] = array_fill(0, 24, 0);
        }
        // Aggregate bounded UTC minutes in SQL (PostgreSQL and SQLite). Converting
        // each bucket in PHP also handles fractional UTC offsets and DST changes.
        $bucket = 'SUBSTR(CAST(fecha AS VARCHAR), 1, 16)';
        $rows = db_connect()->table('detecciones')->select($bucket . ' AS minuto, COUNT(*) AS total', false)
            ->where('empresa_id', $company)->where('clasificacion', 'sin_uniforme')
            ->where('fecha >=', PanelTime::utc($start))->where('fecha <', PanelTime::utc($last->modify('+1 day')))
            ->groupBy($bucket, false)->get()->getResultArray();
        foreach ($rows as $row) {
            $local = (new DateTimeImmutable($row['minuto'] . ':00', new DateTimeZone('UTC')))->setTimezone($timezone);
            $series[$local->format('Y-m-d')][(int) $local->format('G')] += (int) $row['total'];
        }
        return $series;
    }
}
