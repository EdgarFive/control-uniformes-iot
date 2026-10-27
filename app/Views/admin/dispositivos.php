<?php $title = 'Dispositivos'; $subtitle = 'Cámaras registradas en ' . $company['nombre'] . '.'; $section = '/dispositivos'; ?>
<?= $this->extend('admin/layout') ?><?= $this->section('content') ?>
<section class="card"><div class="card-heading"><h2>Cámaras registradas</h2><span class="badge"><?= count($rows) ?> dispositivos</span></div><div class="table-scroll"><table><thead><tr><th>Cámara</th><th>Código</th><th>Sede</th><th>Estado</th><th>Última conexión</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= esc($row['nombre']) ?></td><td><?= esc($row['codigo']) ?></td><td><?= esc($row['sede'] ?? 'Sin sede asignada') ?></td><td><span class="badge <?= $row['estado'] === 'activo' ? 'success' : '' ?>"><?= esc($row['estado']) ?></span></td><td><?= esc(\App\Libraries\PanelTime::display($row['ultima_conexion'], $company['zona_horaria'])) ?></td></tr><?php endforeach ?><?php if (! $rows): ?><tr><td colspan="5" class="empty">No hay dispositivos registrados.</td></tr><?php endif ?></tbody></table></div></section>
<?php $canEdit = auth()->user()->inGroup('superadmin') || in_array($company['rol'], ['admin', 'superadmin'], true); $week = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo']; ?>
<?php foreach ($rows as $row): ?>
<?php $paused = \App\Services\DispositivoHorarioService::boolean($row['deteccion_pausada']); $configured = \App\Services\DispositivoHorarioService::boolean($row['horario_configurado']); $devicePeriods = array_values(array_filter($periods, static fn ($p) => (int) $p['dispositivo_id'] === (int) $row['id'])); ?>
<section class="card schedule-card">
<div class="card-heading"><div><h2><?= esc($row['nombre']) ?> · Horarios</h2><p class="muted"><?= esc($company['zona_horaria']) ?> · <?= $configured ? 'Horario configurado' : 'Sin restricción horaria' ?></p></div><span class="badge <?= $paused ? 'danger' : 'success' ?>"><?= $paused ? 'Pausada' : 'Sin pausa manual' ?></span></div>
<div class="schedule-body">
<?php if ($canEdit): ?><form method="post" action="<?= site_url('admin/dispositivos/' . (int) $row['id'] . '/pausa') . '?empresa=' . (int) $company['id'] ?>">
<?= csrf_field() ?><input type="hidden" name="pausada" value="<?= $paused ? '0' : '1' ?>"><button class="button secondary" type="submit"><?= $paused ? 'Reactivar cámara' : 'Pausar cámara' ?></button>
</form><?php else: ?><p class="muted">Tu asociación con esta empresa permite únicamente consultar.</p><?php endif ?>
<form method="post" action="<?= site_url('admin/dispositivos/' . (int) $row['id'] . '/horarios') . '?empresa=' . (int) $company['id'] ?>">
<?= csrf_field() ?>
<fieldset <?= $canEdit ? '' : 'disabled' ?>>
<legend>Funcionamiento semanal</legend>
<label for="modo-<?= (int) $row['id'] ?>">Restricción horaria</label>
<select id="modo-<?= (int) $row['id'] ?>" name="horario_configurado"><option value="0" <?= ! $configured ? 'selected' : '' ?>>Sin horario: todos los días, 24 horas</option><option value="1" <?= $configured ? 'selected' : '' ?>>Usar los períodos siguientes</option></select>
<p class="muted">Con horario configurado, los días sin períodos quedan deshabilitados. Si el fin es anterior al inicio, el período termina al día siguiente. Inicio incluido; fin excluido. La pausa manual prevalece.</p>
<div class="period-list" data-next="<?= count($devicePeriods) + 1 ?>">
<?php foreach (array_merge($devicePeriods, count($devicePeriods) < 100 ? [['dia' => '', 'inicio' => '', 'fin' => '']] : []) as $index => $period): ?>
<div class="period-row">
<label>Día<select name="periodos[<?= $index ?>][dia]"><option value="">Sin período</option><?php foreach ($week as $number => $name): ?><option value="<?= $number ?>" <?= (string) $period['dia'] === (string) $number ? 'selected' : '' ?>><?= $name ?></option><?php endforeach ?></select></label>
<label>Inicio<input type="time" name="periodos[<?= $index ?>][inicio]" value="<?= esc(substr($period['inicio'], 0, 5)) ?>"></label>
<label>Fin<input type="time" name="periodos[<?= $index ?>][fin]" value="<?= esc(substr($period['fin'], 0, 5)) ?>"></label>
<?php if ($canEdit): ?><button type="button" class="button secondary" data-remove-period>Quitar</button><?php endif ?>
</div><?php endforeach ?>
</div>
<?php if ($canEdit): ?><div class="schedule-actions"><button class="button secondary" type="button" data-add-period>Agregar período</button><button class="button" type="submit">Guardar horario</button></div><?php endif ?>
</fieldset>
</form>
<p class="muted">El servidor aplica los cambios inmediatamente a nuevas fotografías. La cámara actualiza su configuración aproximadamente cada 60 segundos.</p>
</div></section>
<?php endforeach ?>
<?php if ($canEdit && $rows): ?><script>
document.querySelectorAll('.schedule-card').forEach(card => {
    const list = card.querySelector('.period-list');
    const prototype = list.querySelector('.period-row').cloneNode(true);
    card.querySelector('[data-add-period]').addEventListener('click', () => {
        if (list.children.length >= 100) return;
        const row = prototype.cloneNode(true);
        const index = Number(list.dataset.next);
        list.dataset.next = index + 1;
        row.querySelectorAll('input, select').forEach(input => {
            input.name = input.name.replace(/periodos\[\d+\]/, 'periodos[' + index + ']');
            input.value = '';
        });
        list.append(row);
        row.querySelector('select').focus();
    });
    list.addEventListener('click', event => {
        if (event.target.matches('[data-remove-period]')) event.target.closest('.period-row').remove();
    });
});
</script><?php endif ?>
<?= $this->endSection() ?>
