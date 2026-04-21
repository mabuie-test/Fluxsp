<?php ob_start(); ?>
<h2>Pricing</h2>
<p>Preço dinâmico por tipo de trabalho, páginas, urgência, extras e descontos por utilizador.</p>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/app.php'; ?>
