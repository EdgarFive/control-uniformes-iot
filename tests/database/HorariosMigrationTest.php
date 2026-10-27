<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class HorariosMigrationTest extends CIUnitTestCase
{
    public function testAdditiveMigrationPreservesExistingDevices(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('Run PHP with -d extension=sqlite3.');
        }
        // A separate in-memory connection, never the configured production group.
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'migration_', 'foreignKeys' => true], false);
        $this->assertSame(':memory:', $db->database);
        $forge = Database::forge($db);
        $forge->addField(['id' => ['type' => 'INTEGER', 'auto_increment' => true], 'nombre' => ['type' => 'VARCHAR', 'constraint' => 150]]);
        $forge->addPrimaryKey('id');
        $forge->createTable('dispositivos');
        $db->table('dispositivos')->insert(['id' => 7, 'nombre' => 'Cámara existente de prueba aislada']);
        require_once APPPATH . 'Database/Migrations/2026-10-27-120000_AddHorariosDispositivos.php';
        $migration = new \App\Database\Migrations\AddHorariosDispositivos($forge);
        try {
            $migration->up();
            $row = $db->table('dispositivos')->where('id', 7)->get()->getRowArray();
            $this->assertSame('Cámara existente de prueba aislada', $row['nombre']);
            $this->assertFalse(\App\Services\DispositivoHorarioService::boolean($row['deteccion_pausada']));
            $this->assertFalse(\App\Services\DispositivoHorarioService::boolean($row['horario_configurado']));
            $this->assertSame(0, $db->table('dispositivo_horarios')->countAllResults());
            $this->assertTrue($db->table('dispositivo_horarios')->insert(['dispositivo_id' => 7, 'dia' => 1, 'inicio' => '08:00:00', 'fin' => '12:00:00']));
        } finally {
            $db->close();
        }
    }
}
