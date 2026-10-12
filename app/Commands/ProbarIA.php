<?php

namespace App\Commands;

use App\Services\UniformeIAService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ProbarIA extends BaseCommand
{
    protected $group = 'Control de Uniformes';
    protected $name = 'ia:probar';
    protected $description = 'Analiza una fotografía con Gemini sin guardarla.';

    public function run(array $params)
    {
        $ruta = $params[0] ?? '';
        $uniforme = $params[1] ?? '';

        if ($ruta === '' || $uniforme === '') {
            CLI::write(
                'Uso: php spark ia:probar "ruta_foto.jpg" "descripcion del uniforme"',
                'yellow'
            );
            return EXIT_ERROR;
        }

        if (!is_file($ruta)) {
            CLI::error('No se encontró la fotografía: ' . $ruta);
            return EXIT_ERROR;
        }

        $imagen = file_get_contents($ruta);

        if ($imagen === false) {
            CLI::error('No se pudo leer la fotografía.');
            return EXIT_ERROR;
        }

        CLI::write('Analizando fotografía con Gemini...', 'yellow');

        try {
            $servicio = new UniformeIAService();

            $resultado = $servicio->analizar(
                $imagen,
                $uniforme
            );

            CLI::write('Resultado del análisis:', 'green');

            CLI::write(json_encode(
                $resultado,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ));

            CLI::write('La prueba no guardó fotografías.', 'green');

            return EXIT_SUCCESS;

        } catch (\Throwable $e) {
            CLI::error('Error: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
