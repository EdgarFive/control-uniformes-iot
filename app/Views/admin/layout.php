<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= esc($title ?? 'Panel') ?> · Uniform</title><link rel="stylesheet" href="<?= base_url('assets/panel.css') ?>"></head>
<body>
<a class="skip" href="#contenido">Ir al contenido</a>
<aside class="sidebar">
    <a class="brand" href="<?= site_url('admin') ?>"><span class="brand-mark">U</span><span>Uniform<span class="brand-sub">IoT CONTROL CENTER</span></span></a>
    <p class="nav-label">ESPACIO DE TRABAJO</p>
    <nav aria-label="Navegación principal">
        <?php foreach (['' => ['◫', 'Resumen'], '/estadisticas' => ['↗', 'Estadísticas'], '/detecciones' => ['▤', 'Detecciones'], '/dispositivos' => ['▣', 'Dispositivos'], '/uniformes' => ['⚙', 'Uniformes']] as $path => [$icon, $label]): ?>
        <a class="nav-link <?= ($section ?? '') === $path ? 'active' : '' ?>" <?= ($section ?? '') === $path ? 'aria-current="page"' : '' ?> href="<?= site_url('admin' . $path) . '?empresa=' . (int) $company['id'] ?>"><span aria-hidden="true"><?= $icon ?></span><?= $label ?></a>
        <?php endforeach ?>
    </nav>
    <div class="sidebar-foot"><span class="status-dot"></span> Uniform<p>Monitoreo de incumplimientos</p></div>
</aside>
<div class="workspace">
<header class="topbar"><span class="topbar-title">Uniform · Panel administrativo</span><div class="account"><span class="avatar"><?= esc(mb_strtoupper(mb_substr(auth()->user()->username ?: 'A', 0, 1))) ?></span><span><?= esc(auth()->user()->username ?: 'Administrador') ?></span><form method="post" action="<?= url_to('logout') ?>"><?= csrf_field() ?><button class="logout" type="submit">Salir</button></form></div></header>
<main id="contenido" class="content">
    <div class="page-heading"><div><p class="eyebrow">CONTROL Y SEGUIMIENTO</p><h1><?= esc($title ?? 'Resumen') ?></h1><p class="muted"><?= esc($subtitle ?? 'Información de tu empresa en un solo lugar.') ?></p></div>
    <form method="get" class="company-picker"><label for="empresa">Empresa activa</label><div class="inline"><select id="empresa" name="empresa"><?php foreach ($companies as $option): ?><option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $company['id'] ? 'selected' : '' ?>><?= esc($option['nombre']) ?></option><?php endforeach ?></select><button type="submit" class="button secondary">Ver</button></div></form></div>
    <?php if (session('message')): ?><div class="notice" role="status"><?= esc(session('message')) ?></div><?php endif ?>
    <?php if (session('error')): ?><div class="notice danger" role="alert"><?= esc(session('error')) ?></div><?php endif ?>
    <?= $this->renderSection('content') ?>
    <footer class="page-footer">Solo se almacenan incumplimientos. Horario: <?= esc($company['zona_horaria']) ?>.</footer>
</main></div>
</body></html>
