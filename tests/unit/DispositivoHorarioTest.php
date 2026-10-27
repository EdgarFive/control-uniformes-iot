<?php

use App\Services\DispositivoHorarioService as Horario;
use CodeIgniter\Test\CIUnitTestCase;

final class DispositivoHorarioTest extends CIUnitTestCase
{
    public function testMultiplePeriodsAndExclusiveEnd(): void
    {
        $periods = [
            ['dia' => 1, 'inicio' => '08:00:00', 'fin' => '12:00:00'],
            ['dia' => 1, 'inicio' => '14:00:00', 'fin' => '18:00:00'],
        ];
        foreach (['07:59:59' => false, '08:00:00' => true, '11:59:59' => true, '12:00:00' => false, '14:00:00' => true, '18:00:00' => false] as $time => $expected) {
            $this->assertSame($expected, Horario::dentro($periods, new DateTimeImmutable('2026-10-26 ' . $time)));
        }
        $this->assertFalse(Horario::dentro($periods, new DateTimeImmutable('2026-10-27 09:00:00')));
    }

    public function testSundayCrossesIntoMonday(): void
    {
        $periods = [['dia' => 7, 'inicio' => '22:00:00', 'fin' => '02:00:00']];
        $this->assertTrue(Horario::dentro($periods, new DateTimeImmutable('2026-10-25 23:00:00')));
        $this->assertTrue(Horario::dentro($periods, new DateTimeImmutable('2026-10-26 01:59:59')));
        $this->assertFalse(Horario::dentro($periods, new DateTimeImmutable('2026-10-26 02:00:00')));
        $this->assertFalse(Horario::dentro([], new DateTimeImmutable()));
    }

    public function testValidationRejectsMalformedAndEqualTimes(): void
    {
        foreach ([null, 'bad', [['dia' => '8', 'inicio' => '08:00', 'fin' => '09:00']], [['dia' => '1', 'inicio' => '24:00', 'fin' => '09:00']], [['dia' => '1', 'inicio' => '08:00', 'fin' => '08:00']], [['dia' => [], 'inicio' => '08:00', 'fin' => '09:00']], array_fill(0, 101, [])] as $rows) {
            try {
                Horario::validar($rows);
                $this->fail('Debe rechazar períodos inválidos.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValidationAcceptsBlankRowsAndOvernight(): void
    {
        $this->assertSame([['dia' => 7, 'inicio' => '22:00:00', 'fin' => '02:00:00']], Horario::validar([
            ['dia' => '', 'inicio' => '', 'fin' => ''],
            ['dia' => '7', 'inicio' => '22:00', 'fin' => '02:00'],
        ]));
        $service = new Horario();
        $this->assertTrue($service->habilitada(['estado' => 'activo'], 'America/Guatemala'));
        $this->assertFalse($service->habilitada(['estado' => 'activo', 'deteccion_pausada' => 't'], 'America/Guatemala'));
        $this->assertFalse($service->habilitada(['estado' => 'inactivo'], 'America/Guatemala'));
        $this->assertFalse(Horario::boolean('f'));
    }
}
