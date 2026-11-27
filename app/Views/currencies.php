<section class="welcome">
  <p class="eyebrow">Fase 8 · Multi-moneda</p>
  <h1>Monedas y tasas</h1>
  <p class="muted">Los informes, presupuestos y la proyección usan solo cuentas en COP. Las cuentas en USD o EUR se muestran aparte con un equivalente estimado en COP según la tasa manual que registres aquí. No son tasas oficiales.</p>
</section>

<?php foreach ($overview as $item): ?>
  <section class="panel section-gap">
    <div class="panel-heading"><div><p class="eyebrow"><?= $item['space']['scope'] === 'household' ? 'Familiar' : 'Personal' ?></p><h2><?= e($item['space']['name']) ?></h2></div></div>
    <?php if ($item['rates']): ?>
      <div class="table-wrap">
        <table class="data-table table table-hover align-middle">
          <thead><tr><th>Moneda</th><th>1 unidad equivale a (COP)</th><th>Actualizada</th></tr></thead>
          <tbody>
            <?php foreach ($item['rates'] as $rate): ?>
              <tr><td><?= e($rate['currency']) ?></td><td><?= e(rtrim(rtrim((string) $rate['rate_to_cop'], '0'), '.')) ?></td><td><?= e($rate['updated_at']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="muted">Sin tasas registradas.</p>
    <?php endif; ?>
    <?php if ($item['can_manage']): ?>
      <form method="post" action="/currencies" class="form-grid section-gap">
        <input type="hidden" name="_csrf" value="<?= e(\App\Security\Csrf::token()) ?>">
        <input type="hidden" name="space" value="<?= e($item['space']['key']) ?>">
        <label>Moneda
          <select name="currency" required><option value="USD">USD</option><option value="EUR">EUR</option></select>
        </label>
        <label>Valor de 1 unidad en COP
          <input type="text" inputmode="decimal" name="rate" required placeholder="Ej. 4100.50">
        </label>
        <div class="form-grid-full form-actions"><button class="button button-primary" type="submit">Guardar tasa</button></div>
      </form>
    <?php else: ?>
      <p class="notice notice-info">Solo el propietario o un administrador puede cambiar las tasas de este hogar.</p>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
