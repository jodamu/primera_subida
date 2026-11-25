<section class="auth-card">
  <p class="eyebrow">Bienvenido de nuevo</p>
  <h1>Inicia sesión</h1>
  <p class="muted">Administra tus finanzas con claridad y privacidad.</p>
  <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/login" class="form-stack">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <label>Correo electrónico
      <input type="email" name="email" autocomplete="email" maxlength="254" value="jodamu90@gmail.com" required>
    </label>
    <label>Contraseña
      <input type="password" name="password" autocomplete="current-password" maxlength="4096" value="Adxufl61h" required>
    </label>
    <button class="button button-primary button-wide" type="submit">Entrar</button>
  </form>
  <p class="auth-switch"><a href="/password/forgot">¿Olvidaste tu contraseña?</a></p>
  <p class="auth-switch">¿No recibiste el correo de verificación? <a href="/email/resend">Reenviarlo</a></p>
  <p class="auth-switch">¿Aún no tienes cuenta? <a href="/register">Crear cuenta</a></p>
</section>
