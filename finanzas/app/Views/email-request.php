<section class="auth-card">
  <p class="eyebrow"><?= $purpose === 'verify_email' ? 'Confirmación de cuenta' : 'Acceso seguro' ?></p>
  <h1><?= $purpose === 'verify_email' ? 'Reenviar verificación' : 'Recuperar contraseña' ?></h1>
  <p class="muted">
    <?= $purpose === 'verify_email'
      ? 'Ingresa el correo usado al registrarte y te enviaremos un nuevo enlace de verificación.'
      : 'Ingresa el correo de tu cuenta. Si existe y está verificado, recibirás instrucciones para restablecer la contraseña.' ?>
  </p>
  <form method="post" action="<?= $purpose === 'verify_email' ? '/email/resend' : '/password/forgot' ?>" class="form-stack">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <label>Correo electrónico
      <input type="email" name="email" autocomplete="email" maxlength="254" required>
    </label>
    <button class="button button-primary button-wide" type="submit">Enviar instrucciones</button>
  </form>
  <p class="auth-switch"><a href="/login">Volver a iniciar sesión</a></p>
</section>
