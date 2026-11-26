<section class="auth-card">
  <p class="eyebrow">Finanzas compartidas</p>
  <h1>Unirte a un hogar</h1>
  <p class="muted">Al aceptar, podrás acceder al espacio familiar compartido. Los datos personales de otros integrantes seguirán siendo privados.</p>
  <?php if (!empty($error)): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="/invitations/accept" class="form-stack">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <button class="button button-primary button-wide" type="submit">Aceptar invitación</button>
    <a class="button button-quiet button-wide" href="/">Cancelar</a>
  </form>
</section>
