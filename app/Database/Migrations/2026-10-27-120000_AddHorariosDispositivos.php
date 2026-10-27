<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddHorariosDispositivos extends Migration
{
    public function up()
    {
        $this->forge->addColumn('dispositivos', [
            'deteccion_pausada' => ['type' => 'BOOLEAN', 'default' => false],
            'horario_configurado' => ['type' => 'BOOLEAN', 'default' => false],
        ]);
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'dispositivo_id' => ['type' => 'INTEGER'],
            'dia' => ['type' => 'INTEGER'], // ISO: lunes=1, domingo=7; día de inicio.
            'inicio' => ['type' => 'TIME'],
            'fin' => ['type' => 'TIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['dispositivo_id', 'dia']);
        $this->forge->addForeignKey('dispositivo_id', 'dispositivos', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('dispositivo_horarios');
    }

    public function down()
    {
        // Rollback requires an explicit data-preservation procedure.
        throw new \RuntimeException('Migración aditiva: no se permite eliminar horarios automáticamente.');
    }
}
