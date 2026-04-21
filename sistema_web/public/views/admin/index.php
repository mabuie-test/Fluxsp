<?php ob_start(); ?>
<h2>Painel Administrativo</h2>
<ul>
  <li>Utilizadores, instituições, cursos, disciplinas</li>
  <li>Tipos de trabalho, normas, estruturas, templates</li>
  <li>Pedidos, pagamentos Débito, revisão humana, pricing e descontos</li>
</ul>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/app.php'; ?>
