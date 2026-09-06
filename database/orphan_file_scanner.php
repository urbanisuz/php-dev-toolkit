<?php
/**
 * Universal File & Database Auditor (Broken Links & Orphan Files Finder)
 * Универсальный сканер битых ссылок в БД и беспризорных файлов на диске
 */

session_start();
set_time_limit(0);

// Обязательный полифилл для PHP 7.4 (так как str_ends_with появилась только в PHP 8.0)
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// Настройки подключения к БД (можно адаптировать под свои дефолты)
$host = 'localhost';
$db = 'testt';
$user = 'kraftkorob_usr';
$pass = 'NRMXOA}NYAwxy2026';
$charset = 'utf8';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Получаем список таблиц
$tablesStmt = $pdo->query("SHOW TABLES");
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

$selectedTable = $_POST['table'] ?? ($tables[0] ?? '');
$columns = [];

if ($selectedTable && in_array($selectedTable, $tables)) {
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$selectedTable`");
    $colsStmt->execute();
    $columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
}

$isSubmitted = isset($_POST['scan']);
$brokenLinks = [];
$orphanFiles = [];
$stats = ['db_total' => 0, 'broken_count' => 0, 'disk_total' => 0, 'orphan_count' => 0];

if ($isSubmitted) {
    $fileColumn = $_POST['file_column'] ?? '';
    // Корневая папка сайта/сервера, относительно которой хранятся пути в БД (например, папка с картинками)
    // По умолчанию берем текущую директорию или настраиваемый путь
    $targetDir = rtrim($_POST['target_dir'] ?? '', '/\\');

    if (in_array($fileColumn, $columns) && !empty($targetDir) && is_dir($targetDir)) {
        
        // Ищем первичный ключ таблицы
        $primaryKey = $columns[0];
        foreach ($columns as $col) {
            if (str_ends_with($col, '_id')) {
                $primaryKey = $col;
                break;
            }
        }

        // 1. Читаем все пути из базы данных
        $stmt = $pdo->query("SELECT `$primaryKey`, `$fileColumn` FROM `$selectedTable` WHERE `$fileColumn` IS NOT NULL AND `$fileColumn` != ''");
        $dbFiles = []; // Массив путей, на которые ссылается база

        while ($row = $stmt->fetch()) {
            $stats['db_total']++;
            $pkValue = $row[$primaryKey];
            $dbPath = trim($row[$fileColumn]);
            
            // Нормализуем путь (убираем ведущие слэши для корректной склейки)
            $cleanedPath = ltrim($dbPath, '/\\');
            $fullDiskPath = $targetDir . '/' . $cleanedPath;

            $dbFiles[$cleanedPath] = true;

            // Проверка на битую ссылку (в базе запись есть, а файла на диске нет)
            if (!file_exists($fullDiskPath) || !is_file($fullDiskPath)) {
                $brokenLinks[] = [
                    'id' => $pkValue,
                    'path' => $dbPath
                ];
                $stats['broken_count']++;
            }
        }

        // 2. Сканируем физическую папку на диске рекурсивно в поисках сирот
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $diskFilesSet = [];
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $stats['disk_total']++;
                $filePath = $file->getPathname();
                
                // Приводим к относительному пути от $targetDir для сверки с базой
                $relativePath = str_replace('\\', '/', substr($filePath, strlen($targetDir) + 1));
                
                // Пропускаем служебные файлы (например, .htaccess, thumbs.db)
                if (in_array(basename($relativePath), ['.htaccess', 'Thumbs.db', '.DS_Store'])) {
                    continue;
                }

                // Если файла из диска нет в массиве путей из БД — это сирота
                if (!isset($dbFiles[$relativePath])) {
                    $orphanFiles[] = $relativePath;
                    $stats['orphan_count']++;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Аудит файлов и битых ссылок БД</title>
    <style>
        body { font-family: sans-serif; max-width: 900px; margin: 30px auto; padding: 0 20px; background: #f9f9f9; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        select, input[type="text"], button { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #dc3545; color: white; border: none; font-size: 16px; cursor: pointer; margin-top: 20px; }
        button:hover { background: #c82333; }
        .results-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .log-box { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; max-height: 350px; overflow-y: auto; font-family: monospace; font-size: 12px; }
        .stats { background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin-bottom: 15px; }
        .stats-green { background: #d4edda; border-left-color: #28a745; }
    </style>
</head>
<body>
    <div class="card">
        <h2>📂 Универсальный сканер файлов (Битые ссылки & Сироты на диске)</h2>
        <form method="POST">
            <label>Выберите таблицу:</label>
            <select name="table" onchange="this.form.submit()">
                <?php foreach ($tables as $tbl): ?>
                    <option value="<?= $tbl ?>" <?= ($tbl === $selectedTable) ? 'selected' : '' ?>><?= $tbl ?></option>
                <?php endforeach; ?>
            </select>

            <?php if (!empty($columns)): ?>
                <label>Колонка с путем к файлу (например, image):</label>
                <select name="file_column">
                    <?php foreach ($columns as $col): ?>
                        <option value="<?= $col ?>" <?= (in_array($col, ['image', 'thumb', 'file'])) ? 'selected' : '' ?>><?= $col ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Абсолютный или относительный путь к папке на сервере (например, <code>image/catalog</code>):</label>
                <input type="text" name="target_dir" value="<?= htmlspecialchars($_POST['target_dir'] ?? 'image') ?>" placeholder="image">

                <button type="submit" name="scan" value="1">Запустить сканирование</button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($isSubmitted): ?>
        <div class="card">
            <h3>📊 Результаты аудита</h3>
            <div class="stats stats-green">
                Проверено записей в БД: <strong><?= $stats['db_total'] ?></strong> | Проверено файлов на диске: <strong><?= $stats['disk_total'] ?></strong>
            </div>

            <div class="results-grid">
                <div>
                    <h4 style="color: #dc3545;">❌ Битые ссылки в БД (Файла нет на диске): <?= $stats['broken_count'] ?></h4>
                    <div class="log-box">
                        <?php if (empty($brokenLinks)): ?>
                            <div>Битых ссылок не обнаружено! Всё чисто.</div>
                        <?php else: ?>
                            <?php foreach ($brokenLinks as $item): ?>
                                <div>[ID: <?= $item['id'] ?>] — <?= htmlspecialchars($item['path']) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <h4 style="color: #fd7e14;">⚠️ Файлы-сироты на диске (Нет в базе): <?= $stats['orphan_count'] ?></h4>
                    <div class="log-box">
                        <?php if (empty($orphanFiles)): ?>
                            <div>Лишних файлов на диске не найдено.</div>
                        <?php else: ?>
                            <?php foreach ($orphanFiles as $file): ?>
                                <div><?= htmlspecialchars($file) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>