<section class="welcome">
  <p class="eyebrow">Personalización</p>
  <h1>Categorías</h1>
  <p class="muted">Crea categorías propias para cada espacio. Las categorías archivadas dejan de ofrecerse en formularios, pero conservan el historial.</p>
</section>

<section class="panel">
  <form method="get" action="/categories" class="space-picker">
    <label>Espacio
      <select name="space">
        <?php foreach ($spaces as $candidate): ?>
          <option value="<?= e($candidate['key']) ?>" <?= $candidate['key'] === $data['space']['key'] ? 'selected' : '' ?>>
            <?= e($candidate['name']) ?><?= $candidate['scope'] === 'household' ? ' · Familiar' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="button button-secondary" type="submit">Ver espacio</button>
  </form>

  <div class="panel-heading section-gap">
    <div><p class="eyebrow"><?= e($data['space']['name']) ?></p><h2>Nueva categoría</h2></div>
  </div>
  <?php if (!$data['can_manage']): ?>
    <p class="notice notice-info">Solo el propietario o un administrador puede cambiar las categorías de este hogar.</p>
  <?php else: ?>
    <form method="post" action="/categories" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
      <input type="hidden" name="space" value="<?= e($data['space']['key']) ?>">
      <label>Nombre
        <input type="text" name="name" maxlength="80" required placeholder="Ej. Mascotas">
      </label>
      <label>Tipo
        <select name="kind" required>
          <option value="expense">Gasto</option>
          <option value="income">Ingreso</option>
        </select>
      </label>
      <div class="form-grid-full form-actions">
        <button class="button button-primary" type="submit">Crear categoría</button>
      </div>
    </form>
  <?php endif; ?>
</section>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow"><?= e($data['space']['name']) ?></p><h2>Categorías propias</h2></div></div>
  <?php if (!$data['custom']): ?>
    <div class="empty-state"><strong>Aún no hay categorías propias</strong><p>Las categorías base siguen disponibles para registrar movimientos.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table table table-hover align-middle">
        <thead><tr><th>Nombre</th><th>Tipo</th><th>Estado</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($data['custom'] as $category): ?>
            <tr>
              <td>
                <?php if ($data['can_manage'] && (int) $category['is_active'] === 1): ?>
                  <form method="post" action="/categories/rename" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                    <input type="hidden" name="space" value="<?= e($data['space']['key']) ?>">
                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                    <input type="text" name="name" maxlength="80" required value="<?= e($category['name']) ?>" aria-label="Nombre de la categoría">
                    <button class="button button-quiet" type="submit">Renombrar</button>
                  </form>
                <?php else: ?>
                  <?= e($category['name']) ?>
                <?php endif; ?>
              </td>
              <td><?= $category['kind'] === 'income' ? 'Ingreso' : 'Gasto' ?></td>
              <td><span class="status-pill status-<?= (int) $category['is_active'] === 1 ? 'active' : 'archived' ?>"><?= (int) $category['is_active'] === 1 ? 'Activa' : 'Archivada' ?></span></td>
              <td>
                <?php if ($data['can_manage']): ?>
                  <form method="post" action="<?= (int) $category['is_active'] === 1 ? '/categories/archive' : '/categories/restore' ?>"<?= (int) $category['is_active'] === 1 ? ' data-confirm="¿Archivar esta categoría? Dejará de ofrecerse en nuevos movimientos."' : '' ?>>
                    <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
                    <input type="hidden" name="space" value="<?= e($data['space']['key']) ?>">
                    <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                    <button class="button button-quiet" type="submit"><?= (int) $category['is_active'] === 1 ? 'Archivar' : 'Restaurar' ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="panel section-gap">
  <div class="panel-heading"><div><p class="eyebrow">DISPONIBLES PARA TODOS</p><h2>Categorías base</h2></div></div>
  <p class="muted">
    <?php foreach ($data['global'] as $index => $category): ?><?= $index > 0 ? ' · ' : '' ?><?= e($category['name']) ?> (<?= $category['kind'] === 'income' ? 'ingreso' : 'gasto' ?>)<?php endforeach; ?>
  </p>
</section>
