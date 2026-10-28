<?php [$label, $style] = match ($value) {
    'con_uniforme' => ['Con uniforme', 'success'],
    'sin_uniforme' => ['Sin uniforme', 'danger'],
    'no_concluyente' => ['No concluyente', ''],
    default => [(string) $value, ''],
}; ?>
<span class="badge <?= $style ?>"><?= esc($label) ?></span>
