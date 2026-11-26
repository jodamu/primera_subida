<section class="auth-card">
  <p class="eyebrow">Confirmación de cuenta</p>
  <h1>Verifica tu correo</h1>
  <p class="muted">Confirma la dirección de correo para activar el acceso a Mi Finanzas.</p>
  <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/email/verify" class="form-stack">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <button class="button button-primary button-wide" type="submit">Confirmar correo</button>
  </form>
  <p class="auth-switch"><a href="/email/resend">Solicitar otro enlace</a></p>
</section>
