<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDatosPanelDetecciones extends Migration
{
    public function up()
    {
        $this->forge->addColumn('detecciones', [
            'motivo' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'telegram_enviado' => [
                'type' => 'BOOLEAN',
                'null' => true,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn(
            'detecciones',
            'telegram_enviado'
        );

        $this->forge->dropColumn(
            'detecciones',
            'motivo'
        );
    }
}
