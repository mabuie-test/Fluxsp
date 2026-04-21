<?php ob_start(); ?>
<h2>Login</h2>
<form method="post" action="/login" class="bg-white p-4 rounded shadow-sm">
  <input name="email" class="form-control mb-3" placeholder="Email">
  <input name="password" type="password" class="form-control mb-3" placeholder="Senha">
  <button class="btn btn-primary">Entrar</button>
</form>
<?php $content = ob_get_clean(); require __DIR__ . '/../layouts/app.php'; ?>
