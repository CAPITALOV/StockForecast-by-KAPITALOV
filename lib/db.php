cd /var/www/sotis.org/data/www/forecast.sotis.org
grep -nE '\$(pdo|db|conn|mysql)' lib/db.php | head -20