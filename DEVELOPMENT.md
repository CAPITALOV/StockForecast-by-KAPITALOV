# DEVELOPMENT — руководство разработчика

Документ для того, кто продолжит развивать проект (в том числе AI-ассистент).
Цель — понимать архитектуру без чтения всего кода.

---

## 1. Архитектура

### Поток запроса

1. Все запросы идут через `index.php` (front controller).
2. Роутинг по параметру `?r=...`:
   - `home` (по умолчанию) → `pages/home.php`
   - `stock` → `pages/stock.php`
   - `news` → `pages/news.php`
   - `screener` → `pages/screener.php`
   - `accuracy` → `pages/accuracy.php`
   - `compare` → `pages/compare.php`
   - `admin` / `admin-login` / `admin-settings` → `pages/admin*.php`
3. API-запросы (`?r=api-*`) обрабатываются в `index.php` и отдают JSON.
4. Миграции — `bootstrap.php`. Фоновые задачи — `cron/`.
5. Конфиг — `config.php` (копируется из `config.example.php`).

### Структура каталогов
