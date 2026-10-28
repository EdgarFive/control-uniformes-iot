<div class="stats-grid"><?php foreach ($stats as $label => $total): ?><article class="stat-card"><div class="stat-top"><span><?= esc($label) ?></span><span class="stat-icon" aria-hidden="true">↗</span></div><strong><?= number_format($total) ?></strong><small>incumplimientos registrados</small></article><?php endforeach ?></div>
<section class="card">
<form method="get" class="filters">
<input type="hidden" name="empresa" value="<?= (int) $company['id'] ?>">
<?php if ($permitirMensual ?? false): ?>
<div><label for="vista">Visualización</label><select id="vista" name="vista"><option value="hora" <?= $mode === 'hora' ? 'selected' : '' ?>>Por hora</option><option value="dia" <?= $mode === 'dia' ? 'selected' : '' ?>>Por día</option><option value="mes" <?= $mode === 'mes' ? 'selected' : '' ?>>Por mes</option></select></div>
<?php else: ?>
<div><label for="vista">Visualización</label><select id="vista" name="vista"><option value="dia" <?= $mode === 'dia' ? 'selected' : '' ?>>Por día</option><option value="hora" <?= $mode === 'hora' ? 'selected' : '' ?>>Por hora</option></select></div>
<?php endif ?>
<div><label for="desde">Fecha inicial (por hora)</label><input id="desde" name="desde" type="date" value="<?= esc($from) ?>" required></div>
<div><label for="hasta">Fecha final (por hora)</label><input id="hasta" name="hasta" type="date" value="<?= esc($to) ?>" required></div>
<button class="button" type="submit">Consultar</button>
<p class="muted"><?php if ($mode === 'mes'): ?>Por mes: últimos 12 meses, incluyendo el actual hasta ahora. Las fechas anteriores solo se aplican a Por hora.<?php else: ?>Por hora: de uno a tres días, incluyendo ambas fechas.<?php endif ?> Zona: <?= esc($company['zona_horaria']) ?>.</p>
</form>
<?php if ($error): ?><div class="notice danger" role="alert"><?= esc($error) ?></div><?php endif ?>
<?php if ($mode === 'dia'): ?>
<div class="card-heading"><div><h2>Incumplimientos por día</h2><p class="muted">Últimos 14 días · <?= esc($company['nombre']) ?></p></div><span class="badge danger">Sin uniforme</span></div>
<?php $max = max(1, ...array_column($days, 'total')); ?>
<div class="chart-scroll"><div class="chart" role="img" aria-label="Incumplimientos diarios; valores disponibles en la tabla siguiente."><?php foreach ($days as $day): ?><div class="chart-column"><span class="chart-count"><?= (int) $day['total'] ?></span><div class="chart-track"><div class="chart-bar" style="height:<?= (int) round($day['total'] / $max * 100) ?>%"></div></div><span class="chart-label"><?= esc($day['label']) ?></span></div><?php endforeach ?></div></div>
<details class="chart-data"><summary>Ver valores de la gráfica</summary><table><thead><tr><th>Día</th><th>Incumplimientos</th></tr></thead><tbody><?php foreach ($days as $day): ?><tr><td><?= esc($day['label']) ?></td><td><?= (int) $day['total'] ?></td></tr><?php endforeach ?></tbody></table></details>
<?php elseif ($mode === 'mes' && ! $error): ?>
<?php $monthMax = max(1, ...array_column($monthly, 'total')); $monthTotal = array_sum(array_column($monthly, 'total')); ?>
<div class="card-heading"><div><h2>Incumplimientos por mes</h2><p class="muted">Últimos 12 meses · <?= esc($company['nombre']) ?> · Total del período: <?= (int) $monthTotal ?> incumplimientos registrados.</p></div><span class="badge danger">Sin uniforme</span></div>
<div class="chart-scroll"><div class="chart" role="img" aria-label="Incumplimientos mensuales; valores disponibles en la tabla siguiente.">
<?php foreach ($monthly as $month): ?><div class="chart-column"><span class="chart-count"><?= (int) $month['total'] ?></span><div class="chart-track"><div class="chart-bar" style="height:<?= (int) round($month['total'] / $monthMax * 100) ?>%"></div></div><span class="chart-label" title="<?= esc($month['label'], 'attr') ?>"><?= esc($month['short']) ?><br><?= esc($month['year']) ?></span></div><?php endforeach ?>
</div></div>
<details class="chart-data"><summary>Ver valores por mes</summary><div class="table-scroll"><table><thead><tr><th>Mes y año</th><th>Incumplimientos</th></tr></thead><tbody>
<?php foreach ($monthly as $month): ?><tr><td><?= esc($month['label']) ?></td><td><?= (int) $month['total'] ?></td></tr><?php endforeach ?>
</tbody><tfoot><tr><th>Total del período</th><td><?= (int) $monthTotal ?></td></tr></tfoot></table></div></details>
<?php elseif (! $error): ?>
<?php $totals = array_map('array_sum', $hourly); $hourMax = max(1, ...array_merge(...array_values($hourly))); ?>
<div class="card-heading"><div><h2>Incumplimientos por hora</h2><p class="muted"><?= esc($company['nombre']) ?> · Total del período: <?= (int) array_sum($totals) ?> incumplimientos registrados.</p></div><span class="badge danger">Sin uniforme</span></div>
<?php foreach ($hourly as $date => $hours): ?>
<div class="hour-day"><h3><?= esc($date) ?> · <?= (int) $totals[$date] ?> incumplimientos</h3>
<div class="chart-scroll"><div class="chart hourly-chart" role="img" aria-label="Incumplimientos por hora del <?= esc($date) ?>; valores en la tabla siguiente.">
<?php foreach ($hours as $hour => $total): ?><div class="chart-column"><span class="chart-count"><?= (int) $total ?></span><div class="chart-track"><div class="chart-bar" style="height:<?= (int) round($total / $hourMax * 100) ?>%"></div></div><span class="chart-label"><?= sprintf('%02d:00', $hour) ?></span></div><?php endforeach ?>
</div></div></div>
<?php endforeach ?>
<details class="chart-data"><summary>Ver valores por hora y día</summary><div class="table-scroll"><table><thead><tr><th>Hora local</th><?php foreach ($hourly as $date => $hours): ?><th><?= esc($date) ?></th><?php endforeach ?></tr></thead><tbody>
<?php for ($hour = 0; $hour < 24; $hour++): ?><tr><td><?= sprintf('%02d:00', $hour) ?></td><?php foreach ($hourly as $hours): ?><td><?= (int) $hours[$hour] ?></td><?php endforeach ?></tr><?php endfor ?>
</tbody><tfoot><tr><th>Total</th><?php foreach ($totals as $total): ?><td><?= (int) $total ?></td><?php endforeach ?></tr></tfoot></table></div></details>
<p class="chart-data">Solo se cuentan registros sin_uniforme. Las horas sin registros muestran cero. Si cambia el horario de verano, las horas repetidas se suman en la misma hora local.</p>
<?php endif ?>
</section>
