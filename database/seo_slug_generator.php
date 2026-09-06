<?php
/**
 * SEO Slug Generator & Fixer Tool (PHP 7.4+)
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

// Получаем параметры для генератора урлов
$sourceCol = $_POST['source_column'] ?? ''; // Откуда брать текст (например, name)
$targetCol = $_POST['target_column'] ?? ''; // Куда писать slug (например, keyword или slug)
$onlyEmpty = isset($_POST['only_empty']);   // Обрабатывать только пустые

$isSubmitted = ($action === 'preview' || $action === 'generate');

/**
 * Функция транслитерации и очистки под URL (Slugify)
 */
function makeSlug($string) {
    $converter = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ь' => '', 'ы' => 'y', 'ъ' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    
    $string = mb_strtolower($string);
    $string = strtr($string, $converter);
    // Заменяем все не буквенно-цифровые символы на дефисы
    $string = preg_replace('~[^\p{L}\p{N}]+~u', '-', $string);
    // Удаляем краевые дефисы
    $string = trim($string, '-');
    
    return $string;
}

if ($isSubmitted && in_array($sourceCol, $columns, true) && in_array($targetCol, $columns, true)) {
    
    // Ищем первичный ключ
    $primaryKey = $columns[0];
    foreach ($columns as $col) {
        if (substr($col, -3) === '_id' || $col === 'id') {
            $primaryKey = $col;
            break;
        }
    }

    // Формируем выборку
    $sql = "SELECT `$primaryKey`, `$sourceCol`, `$targetCol` FROM `$selectedTable`";
    if ($onlyEmpty) {
        $sql .= " WHERE `$targetCol` IS NULL OR `$targetCol` = ''";
    }

    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $pkValue = $row[$primaryKey];
            $sourceText = $row[$sourceCol];
            $currentTarget = $row[$targetCol];

            // Генерируем новый чистый slug из текста источника
            $newSlug = makeSlug($sourceText);

            if ($newSlug !== '' && $newSlug !== $currentTarget) {
                $affectedRowsCount++;

                if ($action === 'generate') {
                    $updateStmt = $pdo->prepare("UPDATE `$selectedTable` SET `$targetCol` = ? WHERE `$primaryKey` = ?");
                    $updateStmt->execute([$newSlug, $pkValue]);
                } else {
                    if (count($previewData) < 10) {
                        $previewData[] = [
                            'id' => $pkValue,
                            'source' => $sourceText,
                            'old' => $currentTarget ?: '(пусто)',
                            'new' => $newSlug
                        ];
                    }
                }
            }
        }

        if ($action === 'generate') {
            $logMessage = "✅ Успешно сгенерировано и записано SEO-урлов: <strong>{$affectedRowsCount}</strong>";
        }

    } catch (\Exception $e) {
        $logMessage = "❌ Ошибка выполнения: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Генератор / Исправление SEO-урлов (Slugify)</title>
    <style>
        body { font-family: sans-serif; max-width: 950px; margin: 30px auto; padding: 0 20px; background: #f9f9f9; color: #333; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        label { display: block; margin-top: 12px; font-weight: bold; }
        select, input[type="text"], button { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn-preview { background: #17a2b8; color: white; border: none; font-size: 15px; cursor: pointer; margin-top: 15px; }
        .btn-preview:hover { background: #138496; }
        .btn-exec { background: #28a745; color: white; border: none; font-size: 16px; cursor: pointer; margin-top: 15px; }
        .btn-exec:hover { background: #218838; }
        .alert { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .preview-box { background: #222; color: #fff; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 12px; margin-top: 10px; overflow-x: auto; }
        .diff-old { color: #ff6b6b; }
        .diff-new { color: #51cf66; }
    </style>
</head>
<body>
    <div class="card">
        <h2>🛠️ Генератор SEO-урлов (Slugify)</h2>
        
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
                        <label>2. Колонка-источник (откуда брать текст, напр. name):</label>
                        <select name="source_column">
                            <?php foreach ($columns as $col): ?>
                                <option value="<?= $col ?>" <?= ($col === $sourceCol) ? 'selected' : '' ?>><?= $col ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($columns)): ?>
                <div class="row">
                    <div>
                        <label>3. Целевая колонка для урла (напр. keyword / slug):</label>
                        <select name="target_column">
                            <?php foreach ($columns as $col): ?>
                                <option value="<?= $col ?>" <?= ($col === $targetCol) ? 'selected' : '' ?>><?= $col ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label style="font-weight: normal; margin-top: 15px; cursor: pointer;">
                    <input type="checkbox" name="only_empty" value="1" <?= $onlyEmpty ? 'checked' : '' ?> style="width: auto;"> Обрабатывать только те записи, где целевой урл пустой (NULL или '')
                </label>

                <div style="display: flex; gap: 15px;">
                    <button type="submit" onclick="document.getElementById('form_action').value='preview';" class="btn-preview">👁️ Предпросмотр генерации</button>
                    
                    <?php if ($isSubmitted && $action === 'preview' && $affectedRowsCount > 0): ?>
                        <button type="submit" onclick="document.getElementById('form_action').value='generate'; return confirm('Записать новые урлы в базу данных?');" class="btn-exec">⚡ Записать урлы (<?= $affectedRowsCount ?> шт.)</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($isSubmitted && $action === 'preview' && empty($logMessage)): ?>
        <div class="card">
            <h3>📊 Результаты предпросмотра генерации</h3>
            <p>Готово к обновлению записей: <strong><?= $affectedRowsCount ?></strong></p>

            <?php if (!empty($previewData)): ?>
                <p style="font-size: 13px; color: #666;">Примеры преобразований (первые 10 строк):</p>
                <?php foreach ($previewData as $item): ?>
                    <div class="preview-box">
                        <div><b>ID:</b> <?= $item['id'] ?> | <b>Источник:</b> <?= htmlspecialchars($item['source']) ?></div>
                        <div><span class="diff-old">Было:</span> <?= htmlspecialchars($item['old']) ?></div>
                        <div><span class="diff-new">Станет:</span> <?= htmlspecialchars($item['new']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>