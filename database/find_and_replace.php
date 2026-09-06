<?php
/**
 * Universal Database Find & Replace Tool with WHERE filter (PHP 7.4+)
 */

session_start();
set_time_limit(0);

// Настройки подключения к БД (зашиты в коде для безопасности)
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

// Получаем список таблиц
$tablesStmt = $pdo->query("SHOW TABLES");
$tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

$selectedTable = $_POST['table'] ?? ($tables[0] ?? '');
$columns = [];

if ($selectedTable && in_array($selectedTable, $tables, true)) {
    $colsStmt = $pdo->prepare("SHOW COLUMNS FROM `$selectedTable`");
    $colsStmt->execute();
    $columns = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
}

$action = $_POST['action'] ?? '';
$logMessage = '';
$previewData = [];
$affectedRowsCount = 0;

// Получаем параметры
$searchCol = $_POST['search_column'] ?? '';
$searchStr = $_POST['search_string'] ?? '';
$replaceStr = $_POST['replace_string'] ?? '';
$whereCondition = trim($_POST['where_condition'] ?? '');
$useRegex = isset($_POST['use_regex']);

$isSubmitted = ($action === 'preview' || $action === 'replace');

if ($isSubmitted && in_array($searchCol, $columns, true) && $searchStr !== '') {
    
    // Ищем первичный ключ таблицы
    $primaryKey = $columns[0];
    foreach ($columns as $col) {
        if (substr($col, -3) === '_id' || $col === 'id') {
            $primaryKey = $col;
            break;
        }
    }

    // Формируем SQL запрос с учетом дополнительного WHERE
    $sql = "SELECT `$primaryKey`, `$searchCol` FROM `$selectedTable` WHERE ";
    $params = [];

    if ($useRegex) {
        $sql .= "`$searchCol` REGEXP ?";
        $params[] = $searchStr;
    } else {
        $sql .= "`$searchCol` LIKE ?";
        $params[] = '%' . $searchStr . '%';
    }

    // Если пользователь задал дополнительное условие, добавляем его
    if ($whereCondition !== '') {
        // Базовая защита от совсем кривых запросов (можно расширять по вкусу)
        $sql .= " AND (" . $whereCondition . ")";
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $pkValue = $row[$primaryKey];
            $originalText = $row[$searchCol];

            // Замена
            if ($useRegex) {
                $newText = @preg_replace($searchStr, $replaceStr, $originalText);
                if ($newText === null) { $newText = $originalText; }
            } else {
                $newText = str_replace($searchStr, $replaceStr, $originalText);
            }

            if ($newText !== $originalText) {
                $affectedRowsCount++;
                
                if ($action === 'replace') {
                    // Обновляем конкретную строку по первичному ключу
                    $updateStmt = $pdo->prepare("UPDATE `$selectedTable` SET `$searchCol` = ? WHERE `$primaryKey` = ?");
                    $updateStmt->execute([$newText, $pkValue]);
                } else {
                    if (count($previewData) < 10) {
                        $previewData[] = [
                            'id' => $pkValue,
                            'old' => $originalText,
                            'new' => $newText
                        ];
                    }
                }
            }
        }

        if ($action === 'replace') {
            $logMessage = "✅ Успешно заменено! Затронуто строк: <strong>{$affectedRowsCount}</strong>";
        }

    } catch (\Exception $e) {
        $logMessage = "❌ Ошибка в SQL запросе (проверьте условие WHERE): " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Массовый поиск и замена в БД с фильтром</title>
    <style>
        body { font-family: sans-serif; max-width: 950px; margin: 30px auto; padding: 0 20px; background: #f9f9f9; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        label { display: block; margin-top: 12px; font-weight: bold; }
        select, input[type="text"], textarea, button { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        textarea { resize: vertical; height: 70px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn-preview { background: #17a2b8; color: white; border: none; font-size: 15px; cursor: pointer; margin-top: 15px; }
        .btn-preview:hover { background: #138496; }
        .btn-replace { background: #dc3545; color: white; border: none; font-size: 16px; cursor: pointer; margin-top: 15px; }
        .btn-replace:hover { background: #c82333; }
        .alert { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .preview-box { background: #222; color: #fff; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 12px; margin-top: 10px; overflow-x: auto; }
        .diff-old { color: #ff6b6b; text-decoration: line-through; }
        .diff-new { color: #51cf66; }
    </style>
</head>
<body>
    <div class="card">
        <h2>🔄 Поиск и замена в БД с условием WHERE</h2>
        
        <?php if (!empty($logMessage)): ?>
            <div class="alert <?= strpos($logMessage, '❌') !== false ? 'alert-error' : '' ?>"><?= $logMessage ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" id="form_action" value="preview">

            <div class="row">
                <div>
                    <label>1. Выберите таблицу:</label>
                    <select name="table" onchange="document.getElementById('form_action').value='preview'; this.form.submit();">
                        <?php foreach ($tables as $tbl): ?>
                            <option value="<?= $tbl ?>" <?= ($tbl === $selectedTable) ? 'selected' : '' ?>><?= $tbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <?php if (!empty($columns)): ?>
                        <label>2. Колонка для поиска:</label>
                        <select name="search_column">
                            <?php foreach ($columns as $col): ?>
                                <option value="<?= $col ?>" <?= ($col === $searchCol) ? 'selected' : '' ?>><?= $col ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($columns)): ?>
                <div class="row">
                    <div>
                        <label>Что ищем:</label>
                        <textarea name="search_string" placeholder="Например: old-path, можно использовать регулярные выражения"><?= htmlspecialchars($searchStr) ?></textarea>
                    </div>
                    <div>
                        <label>На что заменяем:</label>
                        <textarea name="replace_string" placeholder="Например: new-path"><?= htmlspecialchars($replaceStr) ?></textarea>
                    </div>
                </div>

                <label>Дополнительное условие WHERE (необязательно):</label>
                <input type="text" name="where_condition" value="<?= htmlspecialchars($whereCondition) ?>" placeholder="Например: status = 1 AND category_id = 5">
                <small style="color: #666; display: block; margin-top: 3px;">Пиши как в обычном SQL (без слова WHERE). Если пусто — поиск идет по всей таблице.</small>

                <label style="font-weight: normal; margin-top: 15px; cursor: pointer;">
                    <input type="checkbox" name="use_regex" value="1" <?= $useRegex ? 'checked' : '' ?> style="width: auto;"> Использовать регулярные выражения (PCRE)
                </label>

                <div style="display: flex; gap: 15px;">
                    <button type="submit" onclick="document.getElementById('form_action').value='preview';" class="btn-preview">👁️ Сделать предпросмотр</button>
                    
                    <?php if ($isSubmitted && $action === 'preview' && $affectedRowsCount > 0): ?>
                        <button type="submit" onclick="document.getElementById('form_action').value='replace'; return confirm('Внимание! Изменения запишутся в базу. Продолжить?');" class="btn-replace">⚡ Выполнить замену (<?= $affectedRowsCount ?> строк)</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($isSubmitted && $action === 'preview' && empty($logMessage)): ?>
        <div class="card">
            <h3>📊 Результаты предпросмотра</h3>
            <p>Найдено подходящих строк: <strong><?= $affectedRowsCount ?></strong></p>

            <?php if (!empty($previewData)): ?>
                <p style="font-size: 13px; color: #666;">Примеры изменений (первые 10 строк):</p>
                <?php foreach ($previewData as $item): ?>
                    <div class="preview-box">
                        <div><b>ID (<?= $primaryKey ?? 'id' ?>):</b> <?= $item['id'] ?></div>
                        <div><span class="diff-old">Было:</span> <?= htmlspecialchars(mb_strimwidth($item['old'], 0, 150, "...")) ?></div>
                        <div><span class="diff-new">Станет:</span> <?= htmlspecialchars(mb_strimwidth($item['new'], 0, 150, "...")) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>