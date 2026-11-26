<section class="welcome">
  <p class="eyebrow">Configuración por espacio</p>
  <h1>Parámetros financieros</h1>
  <p class="muted">Ajusta el ciclo del presupuesto, sus avisos y la zona horaria. La configuración personal es independiente de la familiar.</p>
</section>

<section class="panel">
  <form method="get" action="/settings" class="space-picker">
    <label>Espacio
      <select name="space">
        <?php foreach ($spaces as $candidate): ?>
          <option value="<?= e($candidate['key']) ?>" <?= $candidate['key'] === $space['key'] ? 'selected' : '' ?>>
            <?= e($candidate['name']) ?><?= $candidate['scope'] === 'household' ? ' · Familiar' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-secondary" type="submit">Ver espacio</button>
  </form>

  <div class="panel-heading section-gap">
    <div><p class="eyebrow"><?= e($space['name']) ?></p><h2>Reglas de este espacio</h2></div>
  </div>
  <?php if (!$canManage): ?>
    <p class="notice notice-info">Puedes consultar estos parámetros. Solo el propietario o un administrador puede modificarlos.</p>
  <?php endif; ?>

  <form method="post" action="/settings" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
    <input type="hidden" name="space" value="<?= e($space['key']) ?>">
    <label>Día de inicio del ciclo mensual
      <input type="number" name="month_start_day" min="1" max="28" value="<?= (int) $settings['month_start_day'] ?>" required <?= $canManage ? '' : 'disabled' ?>>
      <span class="field-help">Usa un día entre 1 y 28 para evitar fechas ambiguas al cambiar de mes.</span>
    </label>
    <label>Avisar al alcanzar (% del presupuesto)
      <input type="number" name="budget_alert_percent" min="1" max="100" value="<?= (int) $settings['budget_alert_percent'] ?>" required <?= $canManage ? '' : 'disabled' ?>>
    </label>
    <label>Días de anticipación para metas
      <input type="number" name="reminder_days_before" min="0" max="30" value="<?= (int) $settings['reminder_days_before'] ?>" required <?= $canManage ? '' : 'disabled' ?>>
    </label>
    <label>Zona horaria de este espacio
      <select name="timezone" required <?= $canManage ? '' : 'disabled' ?>>
        <?php foreach ($timezones as $timezone => $timezoneName): ?>
          <option value="<?= e($timezone) ?>" <?= $settings['timezone'] === $timezone ? 'selected' : '' ?>><?= e($timezoneName) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="checkbox-card form-grid-full">
      <input type="checkbox" name="reminders_enabled" value="1" <?= (int) $settings['reminders_enabled'] === 1 ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
      <span><strong>Activar recordatorios por correo</strong><small>Requiere que el servidor tenga SMTP configurado y la tarea diaria programada.</small></span>
    </label>
    <?php if ($canManage): ?>
      <div class="form-actions form-grid-full">
        <p class="field-help">Las contraseñas SMTP, de base de datos y demás secretos solo se configuran en `.env` del servidor.</p>
        <button class="button button-primary" type="submit">Guardar parámetros</button>
      </div>
    <?php endif; ?>
  </form>
</section>
