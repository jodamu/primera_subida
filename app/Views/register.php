<section class="auth-card">
  <p class="eyebrow">Comienza con una base segura</p>
  <h1>Crea tu cuenta</h1>
  <p class="muted">Tu información personal será privada. Al registrarte se creará tu hogar financiero.</p>
  <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/register" class="form-stack">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <label>Nombre
      <input type="text" name="name" autocomplete="name" maxlength="120" required value="<?= e($name ?? '') ?>">
    </label>
    <label>Correo electrónico
      <input type="email" name="email" autocomplete="email" maxlength="254" required value="<?= e($email ?? '') ?>">
    </label>
    <label>Contraseña <span class="field-hint">Mínimo 12 caracteres</span>
      <input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
    </label>
    <button class="button button-primary button-wide" type="submit">Crear cuenta</button>
  </form>
  <p class="auth-switch">¿Ya tienes cuenta? <a href="/login">Iniciar sesión</a></p>
</section>
