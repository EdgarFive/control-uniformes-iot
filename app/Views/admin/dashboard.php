<?php $title = 'Resumen de actividad'; $subtitle = 'Incumplimientos registrados por tus dispositivos.'; $section = ''; ?>
<?= $this->extend('admin/layout') ?>
<?= $this->section('content') ?>
<?= view('admin/estadisticas_contenido', compact('stats', 'days', 'company', 'mode', 'from', 'to', 'hourly', 'monthly', 'permitirMensual', 'error')) ?>
<section class="card"><div class="card-heading"><div><h2>Últimas detecciones</h2><p class="muted">Los 6 registros más recientes</p></div><a class="detail-link" href="<?= site_url('admin/detecciones') . '?empresa=' . (int) $company['id'] ?>">Ver historial →</a></div><?= view('admin/tabla', ['rows' => $recent, 'company' => $company]) ?></section>
<?= $this->endSection() ?>
