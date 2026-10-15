<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Iniciar sesión · Uniformes IoT</title><link rel="stylesheet" href="<?= base_url('assets/panel.css') ?>"></head>
<body class="login-page">
<main class="login-card">
    <span class="brand-mark">U</span><p class="eyebrow">CONTROL DE UNIFORMES IoT</p>
    <h1>Bienvenido de nuevo</h1><p class="muted">Ingresa a tu espacio de administración.</p>
    <?php if (session('error') || session('errors')): ?><div class="notice danger" role="alert">No fue posible iniciar sesión. Revisa tus credenciales e inténtalo de nuevo.</div><?php endif ?>
    <?php if (session('message')): ?><div class="notice" role="status">Has cerrado sesión correctamente.</div><?php endif ?>
    <form action="<?= url_to('login') ?>" method="post">
        <?= csrf_field() ?>
        <label for="email">Correo electrónico</label><input id="email" type="email" name="email" autocomplete="username" maxlength="254" required autofocus value="<?= esc(old('email'), 'attr') ?>">
        <label for="password">Contraseña</label><input id="password" type="password" name="password" autocomplete="current-password" required>
        <button class="button full" type="submit">Iniciar sesión <span aria-hidden="true">→</span></button>
    </form>
    <p class="login-note">Acceso exclusivo para administradores autorizados.<br>Contacta al administrador si necesitas acceso.</p>
</main>
<footer class="login-footer">Control de Uniformes IoT · Administración segura</footer>
</body></html>
