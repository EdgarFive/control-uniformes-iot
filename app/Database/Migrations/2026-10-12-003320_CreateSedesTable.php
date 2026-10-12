<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSedesTable extends Migration
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
            'codigo' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
            ],
            'direccion' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'municipio' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'departamento' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'estado' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'activa',
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

        $this->forge->addUniqueKey(
            ['empresa_id', 'codigo'],
            'uq_sedes_empresa_codigo'
        );

        $this->forge->addForeignKey(
            'empresa_id',
            'empresas',
            'id',
            'CASCADE',
            'RESTRICT',
            'fk_sedes_empresas'
        );

        $this->forge->createTable('sedes');
    }

    public function down()
    {
        $this->forge->dropTable('sedes', true);
    }
}
