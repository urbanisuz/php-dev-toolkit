<?php
/**
 * Database Log & Session Garbage Cleaner (PHP 7.4+)
 */

session_start();
set_time_limit(0);

// Настройки подключения к БД (зашиты в коде для безопасности)
$host = 'localhost';
$db = 'testt';
$user = 'root';
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
$matchedRowsCount = 0;

// Параметры очистки
$dateColumn = $_POST['date_column'] ?? '';
$daysOld = (int)($_POST['days_old'] ?? 30);
$customWhere = trim($_POST['custom_where'] ?? '');

$isSubmitted = ($action === 'preview' || $action === 'clean');

if ($isSubmitted) {
    try {
        // Формируем условие WHERE для поиска мусора
        $whereClause = '';
        $params = [];

        if ($customWhere !== '') {
            $whereClause = $customWhere;
        } elseif ($dateColumn !== '' && in_array($dateColumn, $columns, true)) {
            // Удаляем всё, что старше N дней
            $whereClause = "`$dateColumn` < (NOW() - INTERVAL ? DAY)";
            $params[] = $daysOld;
        }

        // Выполняем подсчет только если пользователь реально заполнил критерии очистки
        if ($whereClause !== '') {
            $countSql = "SELECT COUNT(*) FROM `$selectedTable` WHERE " . $whereClause;
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $matchedRowsCount = $countStmt->fetchColumn();

            if ($action === 'clean' && $matchedRowsCount > 0) {
                $deleteSql = "DELETE FROM `$selectedTable` WHERE " . $whereClause;
                $deleteStmt = $pdo->prepare($deleteSql);
                $deleteStmt->execute($params);
                
                $logMessage = "✅ Успешно удалено устаревших записей: <strong>{$matchedRowsCount}</strong>";
                $matchedRowsCount = 0; 
            } elseif ($action === 'clean') {
                $logMessage = "ℹ️ Нет записей для удаления по заданным критериям.";
            }
        } elseif ($action === 'clean') {
            throw new Error("Необходимо указать либо колонку с датой, либо произвольное условие WHERE!");
        }

    } catch (\Exception $e) {
        $logMessage = "❌ Ошибка: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Очистка мусорных логов и сессий в БД</title>
    <style>
        body { font-family: sans-serif; max-width: 950px; margin: 30px auto; padding: 0 20px; background: #f9f9f9; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        label { display: block; margin-top: 12px; font-weight: bold; }
        select, input[type="text"], input[type="number"], button { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn-preview { background: #17a2b8; color: white; border: none; font-size: 15px; cursor: pointer; margin-top: 15px; }
        .btn-preview:hover { background: #138496; }
        .btn-clean { background: #dc3545; color: white; border: none; font-size: 16px; cursor: pointer; margin-top: 15px; }
        .btn-clean:hover { background: #c82333; }
        .alert { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
    </style>
</head>
<body>
    <div class="card">
        <h2>🗑️ Очистка таблиц от мусора (логи, сессии, история)</h2>
        
        <?php if (!empty($logMessage)): ?>
            <div class="alert <?= strpos($logMessage, '❌') !== false ? 'alert-error' : '' ?>"><?= $logMessage ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" id="form_action" value="preview">

            <div class="row">
                <div>
                    <label>1. Выберите таблицу с мусором:</label>
                    <select name="table" onchange="document.getElementById('form_action').value='preview'; this.form.submit();">
                        <?php foreach ($tables as $tbl): ?>
                            <option value="<?= $tbl ?>" <?= ($tbl === $selectedTable) ? 'selected' : '' ?>><?= $tbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <?php if (!empty($columns)): ?>
                        <label>2. Колонка с датой/временем (для авто-расчета):</label>
                        <select name="date_column">
                            <option value="">-- Не использовать (задать WHERE вручную) --</option>
                            <?php foreach ($columns as $col): ?>
                                <option value="<?= $col ?>" <?= ($col === $dateColumn) ? 'selected' : '' ?>><?= $col ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($columns)): ?>
                <div class="row">
                    <div>
                        <label>Удалить всё, что старше (дней):</label>
                        <input type="number" name="days_old" value="<?= $daysOld ?>" min="1" max="3650">
                    </div>
                    <div>
                        <label>Или произвольное условие WHERE (заменяет дни):</label>
                        <input type="text" name="custom_where" value="<?= htmlspecialchars($customWhere) ?>" placeholder="Например: expire < UNIX_TIMESTAMP() или status = 0">
                    </div>
                </div>
                <small style="color: #666; display: block; margin-top: 5px;">Если выбрана колонка с датой, скрипт удалит записи старше указанного количества дней. Либо можно написать жесткое условие в поле выше.</small>

                <div style="display: flex; gap: 15px;">
                    <button type="submit" onclick="document.getElementById('form_action').value='preview';" class="btn-preview">👁️ Найти сколько мусора (COUNT)</button>
                    
                    <?php if ($isSubmitted && $action === 'preview' && $matchedRowsCount > 0): ?>
                        <button type="submit" onclick="document.getElementById('form_action').value='clean'; return confirm('Внимание! Записи будут безвозвратно удалены из базы. Продолжить?');" class="btn-clean">🔥 Очистить мусор (Удалить <?= $matchedRowsCount ?> строк)</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($isSubmitted && $action === 'preview' && empty($logMessage) && $matchedRowsCount > 0): ?>
        <div class="card" style="background: #fff3cd; border: 1px solid #ffeeba; color: #856404;">
            <h3>⚠️ Внимание, обнаружен мусор!</h3>
            <p>По вашему условию найдено записей для удаления: <strong><?= $matchedRowsCount ?></strong></p>
            <p style="font-size: 13px;">Нажмите красную кнопку выше, чтобы запустить процесс очистки.</p>
        </div>
    <?php endif; ?>
</body>
</html>