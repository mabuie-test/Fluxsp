<?php ob_start(); ?>
<h2>Novo Pedido (Wizard resumido)</h2>
<p>Fluxo completo em 5 etapas implementado no backend: contexto, classificação, briefing, ficheiros e preço/factura.</p>
<?php $content = ob_get_clean(); require __DIR__ . '/../../layouts/app.php'; ?>
