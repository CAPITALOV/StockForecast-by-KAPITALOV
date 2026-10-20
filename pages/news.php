<?php
$pdo = db();
$announcements = $pdo->query("SELECT id, title, content, image, created_at FROM announcements ORDER BY created_at DESC")->fetchAll();
$flash = get_flash();
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Новости и анонсы — StockForecast</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <a class="logo" href="index.php">📈 StockForecast</a>
    <nav>
        <a href="index.php">Главная</a>
        <a href="index.php?r=news">Новости</a>
        <a href="index.php?r=screener">Screener</a>
        <a href="index.php?r=accuracy">Точность</a>
        <a href="<?= is_admin() ? url('admin') : url('admin-login') ?>">Админка</a>
        <button type="button" id="theme-toggle" class="btn" title="Переключить тему">🌓</button>
    </nav>
</header>
<main>
<h1>Новости и анонсы проекта</h1>
<p class="muted">Здесь мы публикуем обновления системы, новые функции и важные объявления.</p>

<?php if (empty($announcements)): ?>
    <p class="warn">Пока нет опубликованных новостей.</p>
<?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 24px; margin-top: 24px;">
        <?php foreach ($announcements as $a): ?>
            <div class="card" style="padding: 24px;">
                <div class="muted" style="font-size: 13px; margin-bottom: 8px;">
                    <?= date('d.m.Y H:i', strtotime($a['created_at'])) ?>
                </div>
                <h2 style="margin: 0 0 16px 0; color: var(--accent);"><?= h($a['title']) ?></h2>
                
                <?php if ($a['image'] && is_file(__DIR__ . '/../' . $a['image'])): ?>
                    <div style="margin-bottom: 16px;">
                        <img src="<?= h($a['image']) ?>" alt="<?= h($a['title']) ?>" style="max-width: 100%; border-radius: 8px; border: 1px solid var(--border);">
                    </div>
                <?php endif; ?>

                <div style="color: var(--text); line-height: 1.7; font-size: 15px;">
                    <?= nl2br(h($a['content'])) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</main>
<footer>© StockForecast</footer>
<script src="static/theme.js"></script>
</body>
</html>