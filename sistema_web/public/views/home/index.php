<?php ob_start(); ?>
<div class="p-5 bg-white rounded shadow-sm">
  <h1>Moz Acad</h1>
  <p>Plataforma inteligente de apoio à escrita científica, normalização institucional e geração documental académica.</p>
  <a href="/register" class="btn btn-primary">Começar agora</a>
</div>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/app.php'; ?>
