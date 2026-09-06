<?php
/**
 * Universal Database Column Migrator & Data Transfer Tool
 * Универсальный скрипт для переноса и миграции данных между колонками БД
 */

session_start();
set_time_limit(0);

// Настройки подключения к БД (лучше вынести в конфиг или оставить локальные дефолты)
$host = 'localhost';
$db = '';
$user = '';
$pass = '';
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

// Получаем список всех таблиц в базе для селекта
$tablesStmt = $pdo->query("SHOW TABLES");
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

$selectedTable = $_POST['table'] ?? ($tables[0] ?? '');
$columns = [];

if ($selectedTable && in_array($selectedTable, $tables)) {
    // Получаем список колонок для выбранной таблицы
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$selectedTable`");
    $colsStmt->execute();
    $columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
}

// Обработка отправки формы переноса
$isSubmitted = isset($_POST['migrate']);
$log = [];
$stats = ['updated' => 0, 'skipped' => 0];

if ($isSubmitted) {
    $sourceCol = $_POST['source_column'] ?? '';
    $targetCol = $_POST['target_column'] ?? '';
    $mode = $_POST['mode'] ?? 'empty_only'; // empty_only или all

    // Безопасная проверка: колонки должны существовать в таблице
    if (in_array($sourceCol, $columns) && in_array($targetCol, $columns)) {
        
        // Определяем условие WHERE в зависимости от выбранного режима
        $whereClause = ($mode === 'empty_only') 
            ? "WHERE (`$targetCol` IS NULL OR `$targetCol` = '') AND `$sourceCol` IS NOT NULL AND `$sourceCol` != ''" 
            : "WHERE `$sourceCol` IS NOT NULL";

        // Получаем первичный ключ таблицы (предполагаем стандартный ID на примере первой колонки или ищем 'id')
        // Для универсальности найдем первый столбец с суффиксом _id или просто первый столбец
        $primaryKey = $columns[0];
        foreach ($columns as $col) {
            if (str_ends_with($col, '_id')) {
                $primaryKey = $col;
                break;
            }
        }

        $selectSql = "SELECT `$primaryKey`, `$sourceCol`, `$targetCol` FROM `$selectedTable` $whereClause";
        $stmt = $pdo->query($selectSql);
        
        while ($row = $stmt->fetch()) {
            $pkValue = $row[$primaryKey];
            $valToCopy = $row[$sourceCol];

            // Здесь можно встроить кастомную обработку или регулярку при необходимости, 
            // сейчас переносим «как есть» (или с минимальной очисткой)
            $newValue = $valToCopy;

            // Выполняем обновление целевой колонки
            $updateSql = "UPDATE `$selectedTable` SET `$targetCol` = :val WHERE `$primaryKey` = :pk";
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                'val' => $newValue,
                'pk'  => $pkValue
            ]);

            $log[] = "✅ Запись [ID: $pkValue]: из `{$sourceCol}` в `{$targetCol}` успешно скопировано.";
            $stats['updated']++;
        }
    } else {
        $log[] = "❌ Ошибка: Выбраны некорректные колонки.";
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Универсальный мигратор колонок БД</title>
    <style>
        body { font-family: sans-serif; max-width: 800px; margin: 30px auto; padding: 0 20px; background: #f9f9f9; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        select, button { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #007bff; color: white; border: none; font-size: 16px; cursor: pointer; margin-top: 20px; }
        button:hover { background: #0056b3; }
        .log-box { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 13px; }
        .stats { background: #e8f4fd; padding: 10px; border-left: 4px solid #007bff; margin-bottom: 15px; }
    </style>
    <!-- Подключаем jQuery для динамической смены полей при выборе таблицы (опционально) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div class="card">
        <h2>🔄 Универсальный мигратор данных между колонками</h2>
        <form method="POST">
            <label>Выберите таблицу:</label>
            <select name="table" id="tableSelect" onchange="this.form.submit()">
                <?php foreach ($tables as $tbl): ?>
                    <option value="<?= $tbl ?>" <?= ($tbl === $selectedTable) ? 'selected' : '' ?>><?= $tbl ?></option>
                <?php endforeach; ?>
            </select>

            <?php if (!empty($columns)): ?>
                <label>Колонка-источник (откуда копируем):</label>
                <select name="source_column">
                    <?php foreach ($columns as $col): ?>
                        <option value="<?= $col ?>"><?= $col ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Целевая колонка (куда копируем):</label>
                <select name="target_column">
                    <?php foreach ($columns as $col): ?>
                        <option value="<?= $col ?>"><?= $col ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Режим переноса:</label>
                <select name="mode">
                    <option value="empty_only">Только если целевая колонка пустая (NULL или '')</option>
                    <option value="all">Перезаписывать все выбранные строки принудительно</option>
                </select>

                <button type="submit" name="migrate" value="1">Запустить перенос данных</button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($isSubmitted): ?>
        <div class="card">
            <h3>📊 Результат выполнения</h3>
            <div class="stats">
                ✅ Успешно обновлено строк: <strong><?= $stats['updated'] ?></strong>
            </div>
            <div class="log-box">
                <?php foreach ($log as $line): ?>
                    <div><?= $line ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>