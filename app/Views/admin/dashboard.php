<?php $title = 'Resumen de actividad'; $subtitle = 'Incumplimientos registrados por tus dispositivos.'; $section = ''; ?>
<?= $this->extend('admin/layout') ?>
<?= $this->section('content') ?>
<div class="stats-grid"><?php foreach ($stats as $label => $total): ?><article class="stat-card"><div class="stat-top"><span><?= esc($label) ?></span><span class="stat-icon" aria-hidden="true">↗</span></div><strong><?= number_format($total) ?></strong><small>incumplimientos registrados</small></article><?php endforeach ?></div>
<section class="card"><div class="card-heading"><div><h2>Incumplimientos por día</h2><p class="muted">Últimos 14 días · <?= esc($company['nombre']) ?></p></div><span class="badge danger">Sin uniforme</span></div>
<?php $max = max(1, ...array_column($days, 'total')); ?>
<div class="chart-scroll"><div class="chart" role="img" aria-label="Incumplimientos diarios; valores disponibles en la tabla siguiente."><?php foreach ($days as $day): ?><div class="chart-column"><span class="chart-count"><?= (int) $day['total'] ?></span><div class="chart-track"><div class="chart-bar" style="height:<?= (int) round($day['total'] / $max * 100) ?>%"></div></div><span class="chart-label"><?= esc($day['label']) ?></span></div><?php endforeach ?></div></div>
<details class="chart-data"><summary>Ver valores de la gráfica</summary><table><thead><tr><th>Día</th><th>Incumplimientos</th></tr></thead><tbody><?php foreach ($days as $day): ?><tr><td><?= esc($day['label']) ?></td><td><?= (int) $day['total'] ?></td></tr><?php endforeach ?></tbody></table></details></section>
<section class="card"><div class="card-heading"><div><h2>Últimas detecciones</h2><p class="muted">Los 6 registros más recientes</p></div><a class="detail-link" href="<?= site_url('admin/detecciones') . '?empresa=' . (int) $company['id'] ?>">Ver historial →</a></div><?= view('admin/tabla', ['rows' => $recent, 'company' => $company]) ?></section>
<?= $this->endSection() ?>
