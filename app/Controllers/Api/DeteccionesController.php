<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;

class DeteccionesController extends BaseController
{
    public function health()
    {
        try {
            $db = db_connect();
            $db->query('SELECT 1');

            return $this->response->setJSON([
                'ok' => true,
                'sistema' => 'Control de Uniformes IoT',
                'base_datos' => 'conectada',
            ]);

        } catch (\Throwable $e) {
            log_message('error', $e->getMessage());

            return $this->response
                ->setStatusCode(503)
                ->setJSON([
                    'ok' => false,
                    'mensaje' => 'Servicio no disponible',
                ]);
        }
    }
}
