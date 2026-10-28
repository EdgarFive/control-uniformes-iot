<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\PanelPhoto;
use App\Libraries\PanelTime;
use App\Services\DispositivoHorarioService;
use App\Services\EstadisticasService;
use CodeIgniter\Exceptions\PageNotFoundException;
use DateTimeImmutable;

class PanelController extends BaseController
{
    protected $helpers = ['form', 'url'];

    private function context(): array
    {
        // Even superadmins require an explicit active company membership.
        $companies = db_connect()->table('empresas e')->select('e.id, e.nombre, e.zona_horaria, ue.rol')
            ->join('usuarios_empresas ue', 'ue.empresa_id = e.id')
            ->where('ue.usuario_id', (int) auth('session')->id())->where('ue.activo', true)
            ->where('e.estado', 'activa')->orderBy('e.nombre')->get()->getResultArray();
        $selected = $this->request->getGet('empresa');
        if ($selected !== null && (! is_string($selected) || ! ctype_digit($selected))) {
            throw PageNotFoundException::forPageNotFound();
        }
        $id = $selected ?? session()->get('panel_empresa');
        $company = null;
        foreach ($companies as $candidate) {
            if ((string) $candidate['id'] === (string) $id) {
                $company = $candidate;
            }
        }
        if ($selected !== null && $company === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $company ??= $companies[0] ?? null;
        if ($company !== null) {
            session()->set('panel_empresa', $company['id']);
        }
        return ['companies' => $companies, 'company' => $company];
    }

    private function render(string $view, array $context, array $data = [])
    {
        $this->response->setHeader('Cache-Control', 'private, no-store, max-age=0');
        if ($context['company'] === null) {
            return $this->response->setStatusCode(403)->setBody(view('admin/denegado'));
        }
        [$title, $subtitle, $section] = match ($view) {
            'dashboard' => ['Resumen de actividad', 'Incumplimientos registrados por tus dispositivos.', ''],
            'estadisticas' => ['Estadísticas', 'Consulta los incumplimientos registrados por tu empresa.', '/estadisticas'],
            'historial' => ['Historial de detecciones', 'Consulta evidencias, motivos y notificaciones.', '/detecciones'],
            'detalle' => ['Detección #' . $data['row']['id'], 'Evidencia y resultado del análisis de uniforme.', '/detecciones'],
            'dispositivos' => ['Dispositivos', 'Cámaras registradas en ' . $context['company']['nombre'] . '.', '/dispositivos'],
            default => ['Configuración de uniformes', 'Descripción que utiliza Gemini para evaluar los incumplimientos.', '/uniformes'],
        };
        return view('admin/' . $view, array_merge($context, $data, compact('title', 'subtitle', 'section')));
    }

    private function detections(int $company)
    {
        return db_connect()->table('detecciones d')
            ->select('d.id, d.fecha, d.clasificacion, d.motivo, d.telegram_enviado, p.nombre as camara, e.nombre as empresa')
            ->join('empresas e', 'e.id = d.empresa_id')
            ->join('dispositivos p', 'p.id = d.dispositivo_id AND p.empresa_id = d.empresa_id', 'left')
            ->where('d.empresa_id', $company);
    }

    public function index()
    {
        return $this->actividad('dashboard');
    }

    public function estadisticas()
    {
        return $this->actividad('estadisticas');
    }

    private function actividad(string $view)
    {
        $context = $this->context();
        if (! $context['company']) {
            return $this->render($view, $context);
        }
        $company = $context['company'];
        $now = new DateTimeImmutable('now', PanelTime::zone($company['zona_horaria']));
        $today = $now->setTime(0, 0);
        $end = PanelTime::utc($now);
        $stats = [];
        foreach (['Hoy' => $today, 'Esta semana' => $today->modify('monday this week'), 'Este mes' => $today->modify('first day of this month')] as $label => $start) {
            $stats[$label] = db_connect()->table('detecciones')->where('empresa_id', $company['id'])
                ->where('clasificacion', 'sin_uniforme')->where('fecha >=', PanelTime::utc($start))
                ->where('fecha <=', $end)->countAllResults();
        }
        $days = [];
        // Bounded aggregate queries, without loading all historical rows into PHP.
        for ($i = 13; $i >= 0; $i--) {
            $day = $today->modify('-' . $i . ' days');
            $days[] = ['label' => $day->format('d/m'), 'total' => db_connect()->table('detecciones')
                ->where('empresa_id', $company['id'])->where('clasificacion', 'sin_uniforme')
                ->where('fecha >=', PanelTime::utc($day))->where('fecha <', PanelTime::utc($day->modify('+1 day')))
                ->where('fecha <=', $end)->countAllResults()];
        }
        $mode = $this->request->getGet('vista') ?? 'dia';
        $from = $this->request->getGet('desde') ?? $today->format('Y-m-d');
        $to = $this->request->getGet('hasta') ?? $from;
        $hourly = [];
        $monthly = [];
        $error = null;
        $permitirMensual = true;
        $modes = $permitirMensual ? ['dia', 'hora', 'mes'] : ['dia', 'hora'];
        if (! is_string($mode) || ! in_array($mode, $modes, true)
            || ! is_string($from) || ! is_string($to) || strlen($from) !== 10 || strlen($to) !== 10) {
            return $this->response->setStatusCode(400)->setBody('Filtros inválidos.');
        }
        try {
            if ($mode === 'mes') {
                $monthly = (new EstadisticasService())->porMes((int) $company['id'], $company['zona_horaria'], $now);
            } else {
                // Preserve validation and queries for the existing daily/hourly modes.
                $hourly = (new EstadisticasService())->porHora((int) $company['id'], $company['zona_horaria'], $from, $to);
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
        $recent = $view === 'dashboard'
            ? $this->detections((int) $company['id'])->orderBy('d.fecha', 'DESC')->orderBy('d.id', 'DESC')->limit(6)->get()->getResultArray()
            : [];
        return $this->render($view, $context, compact('stats', 'days', 'recent', 'mode', 'from', 'to', 'hourly', 'monthly', 'permitirMensual', 'error'));
    }

    public function historial()
    {
        $context = $this->context();
        if (! $context['company']) {
            return $this->render('historial', $context);
        }
        $company = $context['company'];
        $filters = [];
        foreach (['desde', 'hasta', 'dispositivo', 'page'] as $key) {
            $value = $this->request->getGet($key) ?? '';
            if (! is_string($value) || strlen($value) > 20) {
                return $this->response->setStatusCode(400)->setBody('Filtros inválidos.');
            }
            $filters[$key] = $value;
        }
        $query = $this->detections((int) $company['id']);
        $error = null;
        try {
            $zone = PanelTime::zone($company['zona_horaria']);
            $start = $filters['desde'] !== '' ? PanelTime::day($filters['desde'], $zone) : null;
            $end = $filters['hasta'] !== '' ? PanelTime::day($filters['hasta'], $zone) : null;
            if ($start && $end && $start > $end) {
                throw new \InvalidArgumentException('La fecha inicial debe ser anterior a la final.');
            }
            if ($start) {
                $query->where('d.fecha >=', PanelTime::utc($start));
            }
            if ($end) {
                $query->where('d.fecha <', PanelTime::utc($end->modify('+1 day')));
            }
            if ($filters['dispositivo'] !== '') {
                if (! ctype_digit($filters['dispositivo'])) {
                    throw new \InvalidArgumentException('Selecciona una cámara válida.');
                }
                $query->where('d.dispositivo_id', $filters['dispositivo']);
            }
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
        $total = $error ? 0 : (clone $query)->countAllResults(false);
        $pages = max(1, (int) ceil($total / 20));
        $page = min($pages, max(1, (int) $filters['page']));
        $rows = $error ? [] : $query->orderBy('d.fecha', 'DESC')->orderBy('d.id', 'DESC')->limit(20, ($page - 1) * 20)->get()->getResultArray();
        $devices = db_connect()->table('dispositivos')->select('id, nombre')->where('empresa_id', $company['id'])->orderBy('nombre')->get()->getResultArray();
        return $this->render('historial', $context, compact('filters', 'error', 'total', 'page', 'pages', 'rows', 'devices'));
    }

    private function findDetection(int $id): array
    {
        // Authorization uses the detection's own tenant, not a client-selected company.
        $row = db_connect()->table('detecciones d')->select('d.*, e.nombre as empresa, e.zona_horaria, p.nombre as camara')
            ->join('empresas e', 'e.id = d.empresa_id')
            ->join('usuarios_empresas ue', 'ue.empresa_id = d.empresa_id')
            ->join('dispositivos p', 'p.id = d.dispositivo_id AND p.empresa_id = d.empresa_id', 'left')
            ->where('ue.usuario_id', (int) auth('session')->id())->where('ue.activo', true)
            ->where('e.estado', 'activa')->where('d.id', $id)->get()->getRowArray();
        if (! $row) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $row;
    }

    public function detalle(int $id)
    {
        $row = $this->findDetection($id);
        $context = $this->context();
        foreach ($context['companies'] as $company) {
            if ((int) $company['id'] === (int) $row['empresa_id']) {
                $context['company'] = $company;
            }
        }
        return $this->render('detalle', $context, compact('row'));
    }

    public function foto(int $id)
    {
        $row = $this->findDetection($id);
        $base = trim((string) env('PHOTO_STORAGE_DIR', ''));
        if ($base === '' && ENVIRONMENT !== 'production') {
            $base = WRITEPATH . 'uploads/detecciones';
        }
        $path = $base === '' ? null : PanelPhoto::resolve($base, (int) $row['empresa_id'], (string) $row['foto_ruta']);
        if ($path === null) {
            throw PageNotFoundException::forPageNotFound();
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw PageNotFoundException::forPageNotFound();
        }
        return $this->response->setContentType('image/jpeg', '')->setHeader('Cache-Control', 'private, no-store, max-age=0')
            ->setHeader('X-Content-Type-Options', 'nosniff')->setBody($bytes);
    }

    public function dispositivos()
    {
        $context = $this->context();
        $rows = $context['company'] ? db_connect()->table('dispositivos d')->select('d.id, d.nombre, d.codigo, d.estado, d.ultima_conexion, d.deteccion_pausada, d.horario_configurado, s.nombre as sede')
            ->join('sedes s', 's.id = d.sede_id AND s.empresa_id = d.empresa_id', 'left')->where('d.empresa_id', $context['company']['id'])
            ->orderBy('d.nombre')->get()->getResultArray() : [];
        $periods = [];
        if ($rows) {
            $periods = db_connect()->table('dispositivo_horarios')->whereIn('dispositivo_id', array_column($rows, 'id'))
                ->orderBy('dia')->orderBy('inicio')->get()->getResultArray();
        }
        return $this->render('dispositivos', $context, compact('rows', 'periods'));
    }

    private function editableDevice(int $id): ?array
    {
        $context = $this->context();
        $company = $context['company'];
        if (! $company || (! auth('session')->user()->inGroup('superadmin') && ! in_array($company['rol'], ['admin', 'superadmin'], true))) {
            return null;
        }
        $device = db_connect()->table('dispositivos')->where('id', $id)->where('empresa_id', $company['id'])->get()->getRowArray();
        if (! $device) {
            throw PageNotFoundException::forPageNotFound();
        }
        return ['company' => $company, 'device' => $device];
    }

    public function guardarHorario(int $id)
    {
        $scope = $this->editableDevice($id);
        if ($scope === null) {
            return $this->response->setStatusCode(403)->setBody(view('admin/denegado'));
        }
        $url = site_url('admin/dispositivos?empresa=' . $scope['company']['id']);
        try {
            $mode = $this->request->getPost('horario_configurado');
            if (! in_array($mode, ['0', '1'], true)) {
                throw new \InvalidArgumentException('Selecciona un modo de horario válido.');
            }
            $periods = DispositivoHorarioService::validar($this->request->getPost('periodos') ?? []);
        } catch (\InvalidArgumentException $e) {
            return redirect()->to($url)->with('error', $e->getMessage());
        }
        $db = db_connect();
        $db->transBegin();
        try {
            // Updating the parent first serializes concurrent PostgreSQL schedule saves.
            $db->table('dispositivos')->where('id', $id)->where('empresa_id', $scope['company']['id'])
                ->update(['horario_configurado' => $mode === '1']);
            $db->table('dispositivo_horarios')->where('dispositivo_id', $id)->delete();
            foreach ($periods as $period) {
                $db->table('dispositivo_horarios')->insert(['dispositivo_id' => $id] + $period);
            }
            if ($db->transStatus() === false) {
                throw new \RuntimeException('No se pudo guardar el horario.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', 'Guardar horario: ' . $e->getMessage());
            return redirect()->to($url)->with('error', 'No se pudo guardar el horario; se conservó la configuración anterior.');
        }
        return redirect()->to($url)->with('message', 'Horario actualizado. La cámara consultará el cambio en aproximadamente 60 segundos.');
    }

    public function pausarDispositivo(int $id)
    {
        $scope = $this->editableDevice($id);
        if ($scope === null) {
            return $this->response->setStatusCode(403)->setBody(view('admin/denegado'));
        }
        $paused = $this->request->getPost('pausada');
        if (! in_array($paused, ['0', '1'], true)) {
            return $this->response->setStatusCode(400)->setBody('Estado de pausa inválido.');
        }
        db_connect()->table('dispositivos')->where('id', $id)->where('empresa_id', $scope['company']['id'])
            ->update(['deteccion_pausada' => $paused === '1']);
        return redirect()->to(site_url('admin/dispositivos?empresa=' . $scope['company']['id']))
            ->with('message', $paused === '1' ? 'Cámara pausada.' : 'Cámara reactivada según su horario.');
    }

    public function uniformes()
    {
        $context = $this->context();
        $rows = $context['company'] ? db_connect()->table('uniformes')->select('id, nombre, descripcion, activo')
            ->where('empresa_id', $context['company']['id'])->orderBy('id')->get()->getResultArray() : [];
        return $this->render('uniformes', $context, compact('rows'));
    }

    public function guardarUniforme(int $id)
    {
        $context = $this->context();
        $company = $context['company'];
        // A global admin with a read-only company membership cannot edit its settings.
        if (! $company || (! auth('session')->user()->inGroup('superadmin') && ! in_array($company['rol'], ['admin', 'superadmin'], true))) {
            return $this->response->setStatusCode(403)->setBody(view('admin/denegado'));
        }
        $row = db_connect()->table('uniformes')->where('id', $id)->where('empresa_id', $company['id'])->get()->getRowArray();
        if (! $row) {
            throw PageNotFoundException::forPageNotFound();
        }
        $description = $this->request->getPost('descripcion');
        if (! is_string($description) || trim($description) === '' || mb_strlen($description) > 10000) {
            return redirect()->to(site_url('admin/uniformes?empresa=' . $company['id']))->with('error', 'Escribe una descripción de 1 a 10 000 caracteres.');
        }
        db_connect()->table('uniformes')->where('id', $id)->where('empresa_id', $company['id'])
            ->update(['descripcion' => trim($description), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return redirect()->to(site_url('admin/uniformes?empresa=' . $company['id']))->with('message', 'Descripción del uniforme actualizada.');
    }
}
