<?= $this->extend('admin/layout') ?>
<?= $this->section('content') ?>
<?= view('admin/estadisticas_contenido', compact('stats', 'days', 'company', 'mode', 'from', 'to', 'hourly', 'error')) ?>
<?= $this->endSection() ?>
