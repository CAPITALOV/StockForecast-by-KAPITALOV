<?php
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $stmt = db()->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$u]);
    $row = $stmt->fetch();
    if ($row && password_verify($p, $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = $u;
        header('Location: ' . url('admin'));
        exit;
    }
    $error = 'Неверный логин или пароль';
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Вход администратора</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">📈 StockForecast</a>
    <nav><a href="index.php">На главную</a></nav>
</header>
<main>
<h1>Вход администратора</h1>
<?php if ($error): ?><div class="flash" style="background:#4a1e1e;color:#ff8a80"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="form-narrow">
    <label>Логин <input name="username" required autofocus></label>
    <label>Пароль <input type="password" name="password" required></label>
    <button type="submit">Войти</button>
</form>
<p class="muted">По умолчанию: <code>admin / admin</code>. Смените пароль после первого входа.</p>
</main>
</body>
</html>