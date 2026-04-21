<?php ob_start(); ?>
<h2>Painel do Utilizador</h2>
<div class="row g-3">
  <div class="col-md-3"><div class="card p-3">Pedidos activos</div></div>
  <div class="col-md-3"><div class="card p-3">Concluídos</div></div>
  <div class="col-md-3"><div class="card p-3">Pagamentos</div></div>
  <div class="col-md-3"><div class="card p-3">Descontos activos</div></div>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../../layouts/app.php'; ?>
