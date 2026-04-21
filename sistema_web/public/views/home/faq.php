<?php ob_start(); ?>
<h2>FAQ</h2>
<p>Monografias exigem revisão humana obrigatória antes da entrega.</p>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/app.php'; ?>
