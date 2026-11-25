<section class="auth-card">
  <p class="eyebrow">Acceso seguro</p>
  <h1>Crear nueva contraseña</h1>
  <p class="muted">Usa al menos 12 caracteres. Al actualizarla, se cerrarán las demás sesiones.</p>
  <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/password/reset" class="form-stack">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <label>Nueva contraseña
      <input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
    </label>
    <label>Confirma la nueva contraseña
      <input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" maxlength="4096" required>
    </label>
    <button class="button button-primary button-wide" type="submit">Actualizar contraseña</button>
  </form>
</section>
