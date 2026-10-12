<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUniformesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INTEGER',
                'auto_increment' => true,
            ],
            'empresa_id' => [
                'type' => 'INTEGER',
            ],
            'nombre' => [
                'type'       => 'VARCHAR',
                'constraint' => 150,
            ],
            'descripcion' => [
                'type' => 'TEXT',
            ],
            'activo' => [
                'type'    => 'BOOLEAN',
                'default' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('empresa_id');

        $this->forge->addForeignKey(
            'empresa_id',
            'empresas',
            'id',
            'CASCADE',
            'RESTRICT'
        );

        $this->forge->createTable('uniformes');
    }

    public function down()
    {
        $this->forge->dropTable('uniformes', true);
    }
}
