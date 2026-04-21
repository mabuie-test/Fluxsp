<?php ob_start(); ?>
<h2>Como funciona</h2>
<p>Wizard de pedidos, pricing automático, pagamento M-Pesa Débito, pipeline assistido e DOCX final.</p>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/app.php'; ?>
