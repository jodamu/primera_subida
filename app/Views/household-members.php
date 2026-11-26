<section class="welcome">
  <p class="eyebrow">Administración familiar</p>
  <h1><?= e($household['name']) ?></h1>
  <p class="muted">Solo el propietario puede cambiar roles o suspender integrantes. Los integrantes suspendidos no pueden consultar datos compartidos.</p>
  <a class="button button-quiet" href="/">Volver a tus hogares</a>
</section>

<section class="panel">
  <div class="panel-heading">
    <div><p class="eyebrow">ACCESO COMPARTIDO</p><h2>Integrantes</h2></div>
  </div>
  <div class="household-list">
    <?php foreach ($members as $member): ?>
      <article class="household-item">
        <div>
          <strong><?= e($member['name']) ?></strong>
          <span class="muted"><?= e($member['email']) ?></span>
          <span class="role"><?= e(ucfirst($member['role'])) ?> · <?= $member['status'] === 'active' ? 'Activo' : ($member['status'] === 'suspended' ? 'Suspendido' : 'Se retiró') ?></span>
        </div>
        <?php if ($household['role'] === 'owner' && $member['role'] !== 'owner' && $member['status'] !== 'left' && (int) $member['user_id'] !== $currentUserId): ?>
          <div class="member-actions">
            <form method="post" action="/households/members/role" class="inline-form">
              <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
              <input type="hidden" name="household_id" value="<?= (int) $household['id'] ?>">
              <input type="hidden" name="member_id" value="<?= (int) $member['member_id'] ?>">
              <label class="sr-only" for="role-<?= (int) $member['member_id'] ?>">Rol de <?= e($member['name']) ?></label>
              <select id="role-<?= (int) $member['member_id'] ?>" name="role">
                <option value="member" <?= $member['role'] === 'member' ? 'selected' : '' ?>>Integrante</option>
                <option value="admin" <?= $member['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
              </select>
              <button class="button button-secondary" type="submit">Guardar rol</button>
            </form>
            <form method="post" action="/households/members/status" class="inline-form">
              <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
              <input type="hidden" name="household_id" value="<?= (int) $household['id'] ?>">
              <input type="hidden" name="member_id" value="<?= (int) $member['member_id'] ?>">
              <input type="hidden" name="status" value="<?= $member['status'] === 'active' ? 'suspended' : 'active' ?>">
              <button class="button button-quiet" type="submit">
                <?= $member['status'] === 'active' ? 'Suspender' : 'Reactivar' ?>
              </button>
            </form>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</section>
