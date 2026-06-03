<?php
declare(strict_types=1);

session_start();

const GRADE_OPTIONS = [1, 2, 3, 4, 5];
const DB_PATH = __DIR__ . '/../lernbrief_hub.db';

$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

function db(): PDO
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    init_db($db);
    return $db;
}

function init_db(PDO $db): void
{
    $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    is_active INTEGER NOT NULL DEFAULT 1,
    archived_name TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    full_name TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS competencies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS ratings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    competency_id INTEGER NOT NULL,
    semester TEXT NOT NULL,
    grade INTEGER NOT NULL,
    note TEXT DEFAULT '',
    UNIQUE(student_id, competency_id, semester),
    FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY(competency_id) REFERENCES competencies(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS sentence_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    competency_id INTEGER NOT NULL,
    grade INTEGER NOT NULL,
    semester TEXT NOT NULL DEFAULT '*',
    sentence TEXT NOT NULL,
    FOREIGN KEY(competency_id) REFERENCES competencies(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS letters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    semester TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL,
    template_name TEXT NOT NULL DEFAULT 'Standard',
    body_font_family TEXT NOT NULL DEFAULT 'Georgia',
    body_font_size INTEGER NOT NULL DEFAULT 16,
    FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS student_semester_goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    semester TEXT NOT NULL,
    goal_text TEXT NOT NULL,
    UNIQUE(student_id, semester),
    FOREIGN KEY(student_id) REFERENCES students(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS group_semester_intros (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id INTEGER NOT NULL,
    semester TEXT NOT NULL,
    intro_text TEXT NOT NULL,
    UNIQUE(group_id, semester),
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS letter_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    header_html TEXT NOT NULL,
    footer_html TEXT NOT NULL,
    include_average_sentence INTEGER NOT NULL DEFAULT 1,
    average_sentence_template TEXT NOT NULL,
    header_position TEXT NOT NULL DEFAULT 'top',
    footer_position TEXT NOT NULL DEFAULT 'bottom',
    body_font_family TEXT NOT NULL DEFAULT 'Georgia',
    body_font_size INTEGER NOT NULL DEFAULT 16,
    include_export_signature INTEGER NOT NULL DEFAULT 1,
    export_signature_template TEXT NOT NULL DEFAULT 'Datum: {date}<br><br>Unterschrift: ______________________________',
    is_active INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor TEXT NOT NULL DEFAULT '',
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER,
    details TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);
SQL);

    $letterCols = array_column($db->query('PRAGMA table_info(letters)')->fetchAll(), 'name');
    if (!in_array('template_name', $letterCols, true)) {
        $db->exec("ALTER TABLE letters ADD COLUMN template_name TEXT NOT NULL DEFAULT 'Standard'");
    }
    if (!in_array('body_font_family', $letterCols, true)) {
        $db->exec("ALTER TABLE letters ADD COLUMN body_font_family TEXT NOT NULL DEFAULT 'Georgia'");
    }
    if (!in_array('body_font_size', $letterCols, true)) {
        $db->exec("ALTER TABLE letters ADD COLUMN body_font_size INTEGER NOT NULL DEFAULT 16");
    }

    $groupCols = array_column($db->query('PRAGMA table_info(groups)')->fetchAll(), 'name');
    if (!in_array('is_active', $groupCols, true)) {
        $db->exec("ALTER TABLE groups ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
    }
    if (!in_array('archived_name', $groupCols, true)) {
        $db->exec("ALTER TABLE groups ADD COLUMN archived_name TEXT DEFAULT NULL");
    }

    $studentCols = array_column($db->query('PRAGMA table_info(students)')->fetchAll(), 'name');
    if (!in_array('is_active', $studentCols, true)) {
        $db->exec("ALTER TABLE students ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
    }

    $templateCols = array_column($db->query('PRAGMA table_info(letter_templates)')->fetchAll(), 'name');
    $templateMigrations = [
        'footer_html' => "ALTER TABLE letter_templates ADD COLUMN footer_html TEXT NOT NULL DEFAULT ''",
        'include_average_sentence' => "ALTER TABLE letter_templates ADD COLUMN include_average_sentence INTEGER NOT NULL DEFAULT 1",
        'average_sentence_template' => "ALTER TABLE letter_templates ADD COLUMN average_sentence_template TEXT NOT NULL DEFAULT 'Zusammenfassend ergibt sich eine Durchschnittsnote von {avg_grade} und damit ein insgesamt {avg_text}er Leistungsstand.'",
        'header_position' => "ALTER TABLE letter_templates ADD COLUMN header_position TEXT NOT NULL DEFAULT 'top'",
        'footer_position' => "ALTER TABLE letter_templates ADD COLUMN footer_position TEXT NOT NULL DEFAULT 'bottom'",
        'body_font_family' => "ALTER TABLE letter_templates ADD COLUMN body_font_family TEXT NOT NULL DEFAULT 'Georgia'",
        'body_font_size' => "ALTER TABLE letter_templates ADD COLUMN body_font_size INTEGER NOT NULL DEFAULT 16",
        'include_export_signature' => "ALTER TABLE letter_templates ADD COLUMN include_export_signature INTEGER NOT NULL DEFAULT 1",
        'export_signature_template' => "ALTER TABLE letter_templates ADD COLUMN export_signature_template TEXT NOT NULL DEFAULT 'Datum: {date}<br><br>Unterschrift: ______________________________'",
        'is_active' => "ALTER TABLE letter_templates ADD COLUMN is_active INTEGER NOT NULL DEFAULT 0",
    ];
    foreach ($templateMigrations as $column => $sql) {
        if (!in_array($column, $templateCols, true)) {
            $db->exec($sql);
        }
    }

    if ((int)$db->query('SELECT COUNT(*) FROM competencies')->fetchColumn() === 0) {
        $defaults = [
            ['Fachwissen', 'Beherrscht die fachlichen Grundlagen', 1],
            ['Mitarbeit', 'Bringt sich aktiv in den Unterricht ein', 2],
            ['Sozialverhalten', 'Arbeitet respektvoll und kooperativ', 3],
            ['Selbstorganisation', 'Plant und erledigt Aufgaben eigenstaendig', 4],
        ];
        $stmt = $db->prepare('INSERT INTO competencies (name, description, sort_order) VALUES (?, ?, ?)');
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
        foreach ($db->query('SELECT id, name FROM competencies')->fetchAll() as $comp) {
            foreach (GRADE_OPTIONS as $grade) {
                $db->prepare('INSERT INTO sentence_templates (competency_id, grade, semester, sentence) VALUES (?, ?, ?, ?)')
                    ->execute([$comp['id'], $grade, '*', "In {$comp['name']} erreicht {name} aktuell die Note {$grade}."]);
            }
        }
    }

    setting_insert('letter_include_average_sentence', '1');
    setting_insert('letter_average_sentence_template', 'Zusammenfassend ergibt sich eine Durchschnittsnote von {avg_grade} und damit ein insgesamt {avg_text}er Leistungsstand.');
    setting_insert('letter_header_template', 'Lernbrief fuer {name}<br>Lerngruppe: {group_name}<br>Halbjahr: {semester}<br><br>{full_name} hat im aktuellen Halbjahr in den vereinbarten Kompetenzbereichen insgesamt {avg_text}e Leistungen gezeigt.<br><br>Im Einzelnen zeigt sich folgende Entwicklung:');
    setting_insert('archived_semesters', '[]');

    if ((int)$db->query('SELECT COUNT(*) FROM letter_templates')->fetchColumn() === 0) {
        $db->prepare(<<<SQL
INSERT INTO letter_templates (
    name, header_html, footer_html, include_average_sentence, average_sentence_template,
    header_position, footer_position, body_font_family, body_font_size,
    include_export_signature, export_signature_template, is_active
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
SQL)->execute([
            'Standard',
            get_setting('letter_header_template'),
            '',
            1,
            get_setting('letter_average_sentence_template'),
            'top',
            'bottom',
            'Georgia',
            16,
            1,
            'Datum: {date}<br><br>Unterschrift: ______________________________',
        ]);
    }

    if ((int)$db->query('SELECT COUNT(*) FROM letter_templates WHERE is_active = 1')->fetchColumn() === 0) {
        $db->exec('UPDATE letter_templates SET is_active = 1 WHERE id = (SELECT id FROM letter_templates ORDER BY id ASC LIMIT 1)');
    }
}

function setting_insert(string $key, string $value): void
{
    db()->prepare('INSERT OR IGNORE INTO app_settings (key, value) VALUES (?, ?)')->execute([$key, $value]);
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_http_user(): string
{
    foreach (['HTTP_USER', 'PHP_AUTH_USER', 'REMOTE_USER', 'AUTH_USER'] as $key) {
        $value = trim((string)($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function is_valid_grade(int $grade): bool
{
    return in_array($grade, GRADE_OPTIONS, true);
}

function audit_log(string $action, string $entityType, ?int $entityId = null, array|string $details = ''): void
{
    try {
        $payload = is_array($details) ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$details;
        db()->prepare('INSERT INTO audit_logs (actor, action, entity_type, entity_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([current_http_user(), $action, $entityType, $entityId, $payload ?: '', date('Y-m-d\TH:i:s')]);
    } catch (Throwable $e) {
        export_debug_log('Audit log failed: ' . $e->getMessage());
    }
}

function export_system_checks(): array
{
    $tmpDir = __DIR__ . '/../tmp';
    $mpdfTmp = $tmpDir . '/mpdf';
    return [
        ['label' => 'Composer Autoload', 'ok' => is_file(__DIR__ . '/../vendor/autoload.php'), 'detail' => '../vendor/autoload.php'],
        ['label' => 'mPDF', 'ok' => class_exists('\\Mpdf\\Mpdf'), 'detail' => 'PDF-Export mit sauberem Unicode/Layout'],
        ['label' => 'PHP Extension mbstring', 'ok' => extension_loaded('mbstring'), 'detail' => 'fuer mPDF erforderlich'],
        ['label' => 'Word (.doc) HTML-Export', 'ok' => true, 'detail' => 'Word95/LibreOffice kompatibel (Standard)'],
        ['label' => 'tmp beschreibbar', 'ok' => ensure_writable_dir($tmpDir), 'detail' => '../tmp'],
        ['label' => 'tmp/mpdf beschreibbar', 'ok' => ensure_writable_dir($mpdfTmp), 'detail' => '../tmp/mpdf'],
    ];
}

function ensure_writable_dir(string $dir): bool
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return is_dir($dir) && is_writable($dir);
}

function route(string $path, array $query = []): string
{
    $url = 'index.php' . ($path === '/' ? '' : '?r=' . rawurlencode($path));
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    return $url;
}

function current_school_semester(?DateTimeImmutable $now = null): string
{
    $dt = $now ?: new DateTimeImmutable();
    $year = (int)$dt->format('Y');
    $month = (int)$dt->format('n');
    if ($month >= 8) {
        return $year . '/' . ($year + 1) . '-HJ1';
    }
    if ($month === 1) {
        return ($year - 1) . '/' . $year . '-HJ1';
    }
    return ($year - 1) . '/' . $year . '-HJ2';
}

function parse_semester(string $value): ?array
{
    if (!preg_match('/^(\d{4})\/(\d{4})-(HJ1|HJ2)$/', $value, $m)) {
        return null;
    }
    $start = (int)$m[1];
    $end = (int)$m[2];
    return $end === $start + 1 ? [$start, $end, $m[3]] : null;
}

function semester_sort_value(string $semester): int
{
    $p = parse_semester($semester);
    if (!$p) {
        return -1;
    }
    return $p[0] * 10 + ($p[2] === 'HJ1' ? 1 : 2);
}

function default_semester(): string
{
    return current_school_semester();
}

function normalize_semester(?string $value): string
{
    $value = trim((string)$value);
    return parse_semester($value) ? $value : default_semester();
}

function school_semester_options(): array
{
    $current = default_semester();
    $start = (int)explode('/', $current)[0];
    $options = [];
    for ($year = $start - 3; $year <= $start + 3; $year++) {
        $options[] = $year . '/' . ($year + 1) . '-HJ1';
        $options[] = $year . '/' . ($year + 1) . '-HJ2';
    }
    foreach (db()->query('SELECT semester FROM ratings UNION SELECT semester FROM letters')->fetchAll() as $row) {
        if (parse_semester((string)$row['semester'])) {
            $options[] = $row['semester'];
        }
    }
    $options = array_values(array_unique($options));
    usort($options, fn($a, $b) => semester_sort_value($b) <=> semester_sort_value($a));
    return $options;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function redirect_to(string $path, array $query = []): never
{
    header('Location: ' . route($path, $query));
    exit;
}

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function query_groups(): array
{
    return all('
        SELECT g.id, g.name, COUNT(s.id) AS student_count
        FROM groups g
        LEFT JOIN students s ON s.group_id = g.id AND COALESCE(s.is_active, 1) = 1
        WHERE COALESCE(g.is_active, 1) = 1
        GROUP BY g.id, g.name
        ORDER BY g.name ASC
    ');
}

function query_competencies(): array
{
    return all('SELECT * FROM competencies ORDER BY sort_order ASC, name ASC');
}

function get_setting(string $key, string $default = ''): string
{
    $row = one('SELECT value FROM app_settings WHERE key = ?', [$key]);
    return $row ? (string)$row['value'] : $default;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO app_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function archived_semesters(): array
{
    $decoded = json_decode(get_setting('archived_semesters', '[]'), true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_filter(array_map('strval', $decoded), fn($s) => parse_semester($s) !== null));
}

function set_archived_semesters(array $items): void
{
    $items = array_values(array_unique(array_filter($items, fn($s) => parse_semester($s) !== null)));
    usort($items, fn($a, $b) => semester_sort_value($b) <=> semester_sort_value($a));
    set_setting('archived_semesters', json_encode($items, JSON_UNESCAPED_SLASHES));
}

function active_letter_template(): array
{
    return one('SELECT * FROM letter_templates WHERE is_active = 1 ORDER BY id ASC LIMIT 1')
        ?: one('SELECT * FROM letter_templates ORDER BY id ASC LIMIT 1')
        ?: [];
}

function export_signature_values(array $letter = [], array $tpl = []): array
{
    return [
        'date' => date('d.m.Y'),
        'iso_date' => date('Y-m-d'),
        'name' => (string)($letter['full_name'] ?? 'Max Beispiel'),
        'full_name' => (string)($letter['full_name'] ?? 'Max Beispiel'),
        'semester' => (string)($letter['semester'] ?? default_semester()),
        'template_name' => (string)($tpl['name'] ?? $letter['template_name'] ?? 'Standard'),
    ];
}

function export_signature_html(array $letter = [], ?array $tpl = null): string
{
    $tpl = $tpl ?: (isset($letter['template_name'])
        ? (one('SELECT * FROM letter_templates WHERE name = ?', [(string)$letter['template_name']]) ?: active_letter_template())
        : active_letter_template());
    if (!$tpl || (int)($tpl['include_export_signature'] ?? 1) !== 1) {
        return '';
    }
    $template = (string)($tpl['export_signature_template'] ?? 'Datum: {date}<br><br>Unterschrift: ______________________________');
    $html = normalize_inline_html(safe_format($template, export_signature_values($letter, $tpl)));
    return trim($html) === '' ? '' : "<div class='export-meta'>" . ensure_block_html($html) . '</div>';
}

function ensure_sentence_punctuation(string $text): string
{
    $clean = trim($text);
    if ($clean === '') {
        return '';
    }
    return preg_match('/[.!?]$/u', $clean) ? $clean : $clean . '.';
}

function safe_format(string $template, array $values): string
{
    return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', fn($m) => array_key_exists($m[1], $values) ? (string)$values[$m[1]] : $m[0], $template);
}

function normalize_inline_html(string $html): string
{
    $html = trim($html);
    $html = preg_replace('/\r\n|\r|\n/', '<br>', $html);
    $html = preg_replace('/(?:<br\s*\/?>\s*){3,}/i', '<br><br>', $html);
    return $html ?? '';
}

function blockify_inline_html(string $html): string
{
    $html = normalize_inline_html($html);
    $chunks = preg_split('/(?:<br\s*\/?>\s*){1,}/i', $html) ?: [];
    $out = '';
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if ($chunk !== '') {
            $out .= '<p>' . $chunk . '</p>';
        }
    }
    return $out;
}

function ensure_block_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (preg_match('/<(p|div|ul|ol|li|h1|h2|h3|h4|blockquote)\b/i', $html)) {
        return $html;
    }
    return blockify_inline_html($html);
}

function plain_text_to_letter_html(string $text, bool $punctuateParagraphs = false): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
    if ($text === '') {
        return '';
    }

    $blocks = preg_split("/\n\s*\n/", $text) ?: [];
    $html = '';
    foreach ($blocks as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", trim($block))), fn($line) => $line !== ''));
        if (!$lines) {
            continue;
        }

        $bulletItems = [];
        $numberItems = [];
        foreach ($lines as $line) {
            if (preg_match('/^[-*•]\s+(.+)$/u', $line, $m)) {
                $bulletItems[] = $m[1];
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $line, $m)) {
                $numberItems[] = $m[1];
            }
        }

        if (count($bulletItems) === count($lines)) {
            $html .= '<ul>';
            foreach ($bulletItems as $item) {
                $html .= '<li>' . h($punctuateParagraphs ? ensure_sentence_punctuation($item) : $item) . '</li>';
            }
            $html .= '</ul>';
            continue;
        }

        if (count($numberItems) === count($lines)) {
            $html .= '<ol>';
            foreach ($numberItems as $item) {
                $html .= '<li>' . h($punctuateParagraphs ? ensure_sentence_punctuation($item) : $item) . '</li>';
            }
            $html .= '</ol>';
            continue;
        }

        $paragraphLines = array_map(
            fn($line) => h($punctuateParagraphs ? ensure_sentence_punctuation($line) : $line),
            $lines
        );
        $html .= '<p>' . implode('<br>', $paragraphLines) . '</p>';
    }

    return $html;
}

function html_to_text(string $html): string
{
    $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\s*\/\s*(p|div)\s*>/i', "\n", $text ?? '');
    $text = html_entity_decode(strip_tags($text ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $lines = array_filter(array_map('trim', preg_split('/\R/', $text) ?: []));
    return implode("\n", $lines);
}

function html_style_map(string $style): array
{
    $out = [];
    foreach (explode(';', $style) as $chunk) {
        if (!str_contains($chunk, ':')) {
            continue;
        }
        [$key, $value] = explode(':', $chunk, 2);
        $out[strtolower(trim($key))] = trim($value);
    }
    return $out;
}

function html_font_size_half_points(string $value): ?int
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return null;
    }
    if (str_ends_with($value, 'px')) {
        return max(12, min(56, (int)round(((float)substr($value, 0, -2)) * 1.5)));
    }
    if (str_ends_with($value, 'pt')) {
        return max(12, min(56, (int)round(((float)substr($value, 0, -2)) * 2)));
    }
    if (is_numeric($value)) {
        return max(12, min(56, (int)round(((float)$value) * 2)));
    }
    return null;
}

function html_font_tag_size(string $value): int
{
    return match ((int)$value) {
        1 => 16,
        2 => 20,
        4 => 28,
        5 => 36,
        6 => 44,
        7 => 52,
        default => 24,
    };
}

function html_blocks_from_content(string $html): array
{
    $html = preg_replace('/<br\s*\/?>/i', '</p><p>', $html) ?? $html;
    $html = preg_replace('/<\/div>\s*<p>/i', '</div><p>', $html) ?? $html;
    if (!class_exists('DOMDocument')) {
        return array_map(
            fn($line) => ['align' => 'left', 'runs' => [['text' => $line, 'bold' => false, 'italic' => false, 'underline' => false, 'font' => null, 'size' => null]]],
            explode("\n", html_to_text($html))
        );
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $body = $dom->getElementsByTagName('body')->item(0);
    $blocks = [];
    $base = ['bold' => false, 'italic' => false, 'underline' => false, 'font' => null, 'size' => null, 'align' => 'left'];
    if ($body) {
        foreach ($body->childNodes as $child) {
            html_collect_blocks($child, $base, $blocks);
        }
    }
    return array_values(array_filter($blocks, fn($block) => trim(implode('', array_column($block['runs'], 'text'))) !== ''));
}

function html_collect_blocks(DOMNode $node, array $style, array &$blocks): void
{
    if ($node instanceof DOMText) {
        $text = preg_replace('/\s+/u', ' ', $node->nodeValue);
        if (trim((string)$text) !== '') {
            $blocks[] = ['align' => $style['align'], 'runs' => [['text' => trim((string)$text), ...$style]]];
        }
        return;
    }
    if (!$node instanceof DOMElement) {
        return;
    }

    $tag = strtolower($node->tagName);
    $style = html_merge_node_style($node, $style);
    $blockTags = ['p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'blockquote'];
    if ($tag === 'div' && html_element_has_block_children($node)) {
        foreach ($node->childNodes as $child) {
            html_collect_blocks($child, $style, $blocks);
        }
        return;
    }
    if (in_array($tag, $blockTags, true)) {
        $runs = [];
        if ($tag === 'li') {
            $runs[] = ['text' => '- ', ...$style];
        }
        html_collect_runs($node, $style, $runs);
        if ($tag === 'h1' || $tag === 'h2') {
            foreach ($runs as &$run) {
                $run['bold'] = true;
                $run['size'] = $tag === 'h1' ? 32 : 28;
            }
            unset($run);
        }
        $blocks[] = ['align' => $style['align'], 'runs' => $runs];
        return;
    }

    foreach ($node->childNodes as $child) {
        html_collect_blocks($child, $style, $blocks);
    }
}

function html_element_has_block_children(DOMElement $node): bool
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'blockquote'], true)) {
            return true;
        }
    }
    return false;
}

function html_collect_runs(DOMNode $node, array $style, array &$runs): void
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $text = preg_replace('/\s+/u', ' ', $child->nodeValue);
            if ($text !== null && $text !== '') {
                $runs[] = ['text' => $text, ...$style];
            }
            continue;
        }
        if (!$child instanceof DOMElement) {
            continue;
        }
        if (strtolower($child->tagName) === 'br') {
            $runs[] = ['text' => "\n", ...$style];
            continue;
        }
        $childStyle = html_merge_node_style($child, $style);
        html_collect_runs($child, $childStyle, $runs);
    }
}

function html_merge_node_style(DOMElement $node, array $style): array
{
    $tag = strtolower($node->tagName);
    if (in_array($tag, ['b', 'strong'], true)) {
        $style['bold'] = true;
    }
    if (in_array($tag, ['i', 'em'], true)) {
        $style['italic'] = true;
    }
    if ($tag === 'u') {
        $style['underline'] = true;
    }
    if ($tag === 'font') {
        if ($node->hasAttribute('face')) {
            $style['font'] = $node->getAttribute('face');
        }
        if ($node->hasAttribute('size')) {
            $style['size'] = html_font_tag_size($node->getAttribute('size'));
        }
    }
    $css = html_style_map($node->getAttribute('style'));
    if (isset($css['font-weight']) && ($css['font-weight'] === 'bold' || (int)$css['font-weight'] >= 600)) {
        $style['bold'] = true;
    }
    if (($css['font-style'] ?? '') === 'italic') {
        $style['italic'] = true;
    }
    if (str_contains($css['text-decoration'] ?? '', 'underline')) {
        $style['underline'] = true;
    }
    if (isset($css['font-family'])) {
        $style['font'] = trim($css['font-family'], '"\' ');
    }
    if (isset($css['font-size'])) {
        $style['size'] = html_font_size_half_points($css['font-size']) ?? $style['size'];
    }
    if (isset($css['text-align']) && in_array($css['text-align'], ['left', 'center', 'right', 'justify'], true)) {
        $style['align'] = $css['text-align'];
    }
    return $style;
}

function avg_text(float $grade): string
{
    if ($grade <= 1.5) return 'sehr gut';
    if ($grade <= 2.5) return 'gut';
    if ($grade <= 3.5) return 'befriedigend';
    if ($grade <= 4.5) return 'ausreichend';
    return 'verbesserungsbeduerftig';
}

function build_letter(int $studentId, string $semester): string
{
    $student = one('
        SELECT s.id, s.group_id, s.full_name, g.name AS group_name
            FROM students s JOIN groups g ON g.id = s.group_id
            WHERE s.id = ?
              AND COALESCE(g.is_active, 1) = 1
              AND COALESCE(s.is_active, 1) = 1
    ', [$studentId]);
    if (!$student) {
        throw new RuntimeException('Schueler nicht gefunden.');
    }
    $ratings = all('
        SELECT c.name AS competency_name, r.competency_id, r.grade, r.note
        FROM ratings r JOIN competencies c ON c.id = r.competency_id
        WHERE r.student_id = ? AND r.semester = ?
        ORDER BY c.sort_order ASC, c.name ASC
    ', [$studentId, $semester]);
    if (!$ratings) {
        throw new RuntimeException('Keine Bewertungen fuer dieses Halbjahr vorhanden.');
    }

    $avg = round(array_sum(array_map(fn($r) => (int)$r['grade'], $ratings)) / count($ratings), 2);
    $values = [
        'name' => $student['full_name'],
        'full_name' => $student['full_name'],
        'group_name' => $student['group_name'],
        'semester' => $semester,
        'avg_grade' => number_format($avg, 2, '.', ''),
        'avg_text' => avg_text($avg),
    ];
    $tpl = active_letter_template();
    $header = normalize_inline_html(safe_format((string)$tpl['header_html'], $values));
    if ($header === '') {
        $header = normalize_inline_html(safe_format('Lernbrief fuer {name}<br>Lerngruppe: {group_name}<br>Halbjahr: {semester}', $values));
    }

    $intro = one('SELECT intro_text FROM group_semester_intros WHERE group_id = ? AND semester = ?', [$student['group_id'], $semester]);
    $goal = one('SELECT goal_text FROM student_semester_goals WHERE student_id = ? AND semester = ?', [$studentId, $semester]);

    $footerParts = [];
    if ((int)$tpl['include_average_sentence'] === 1) {
        $footerParts[] = ensure_sentence_punctuation(safe_format((string)$tpl['average_sentence_template'], $values));
    }
    $customFooter = normalize_inline_html(safe_format((string)$tpl['footer_html'], $values));
    if (trim($customFooter) !== '') {
        $footerParts[] = $customFooter;
    }
    if ($goal && trim((string)$goal['goal_text']) !== '') {
        $footerParts[] = '<p><strong>Halbjahresziele:</strong></p>' . plain_text_to_letter_html((string)$goal['goal_text']);
    }

    $parts = [];
    $headerPosition = in_array($tpl['header_position'], ['top', 'after_intro'], true) ? $tpl['header_position'] : 'top';
    $footerPosition = in_array($tpl['footer_position'], ['bottom', 'after_header'], true) ? $tpl['footer_position'] : 'bottom';

    if ($headerPosition === 'top') {
        $parts[] = "<div class='letter-header'>" . ensure_block_html($header) . "</div>";
    }
    if ($footerPosition === 'after_header') {
        foreach ($footerParts as $part) {
            $parts[] = "<div class='letter-footer'>" . ensure_block_html($part) . "</div>";
        }
    }
    if ($intro && trim((string)$intro['intro_text']) !== '') {
        $parts[] = plain_text_to_letter_html((string)$intro['intro_text'], true);
    }
    if ($headerPosition === 'after_intro') {
        $parts[] = "<div class='letter-header'>" . ensure_block_html($header) . "</div>";
    }

    foreach ($ratings as $row) {
        $candidates = all('SELECT sentence FROM sentence_templates WHERE competency_id = ? AND grade = ? ORDER BY id ASC', [$row['competency_id'], $row['grade']]);
        $sentence = $candidates ? $candidates[array_rand($candidates)]['sentence'] : "In {$row['competency_name']} liegt die Leistung bei der Note {$row['grade']}.";
        $sentence = str_replace('{name}', (string)$student['full_name'], (string)$sentence);
        if (trim((string)$row['note']) !== '') {
            $sentence .= ' Hinweis: ' . $row['note'];
        }
        $parts[] = '<p>' . h(ensure_sentence_punctuation(implode(' ', array_filter(array_map('trim', preg_split('/\R/', $sentence) ?: []))))) . '</p>';
    }
    if ($footerPosition === 'bottom') {
        foreach ($footerParts as $part) {
            if (trim($part) !== '') {
                $parts[] = "<div class='letter-footer'>" . ensure_block_html($part) . "</div>";
            }
        }
    }

    $font = $tpl['body_font_family'] ?: 'Georgia';
    $size = max(4, min(28, (int)$tpl['body_font_size']));
    return "<div style='font-family:" . h($font) . ";font-size:{$size}px;line-height:1.35;'>" . implode('', $parts) . '</div>';
}

function layout(string $active, callable $content): void
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    ?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lernbrief-Hub</title>
    <link rel="stylesheet" href="static/style.css">
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('lernbrief-theme');
                if (theme === 'dark') {
                    document.documentElement.dataset.theme = 'dark';
                }
            } catch (e) {}
        })();
    </script>
</head>
<body>
<header class="topbar">
    <div class="topbar-main"><h1>Lernbrief-Hub</h1><button type="button" class="theme-toggle" data-theme-toggle aria-label="Dunkles Design aktivieren" title="Dunkles Design aktivieren"><span data-theme-label>Dunkel</span></button></div>
    <nav class="topnav" aria-label="Hauptnavigation">
        <div class="nav-group">
            <span class="nav-group-title">Arbeitsbereich</span>
            <a class="nav-pill <?= $active === 'overview' ? 'active' : '' ?>" href="<?= route('/overview') ?>">Uebersicht</a>
            <a class="nav-pill <?= $active === 'index' ? 'active' : '' ?>" href="<?= route('/') ?>">Dashboard</a>
        </div>
        <div class="nav-group">
            <span class="nav-group-title">Verwaltung</span>
            <a class="nav-pill <?= $active === 'new_group' ? 'active' : '' ?>" href="<?= route('/groups/new') ?>">Lerngruppe anlegen</a>
            <a class="nav-pill <?= $active === 'competencies' ? 'active' : '' ?>" href="<?= route('/competencies') ?>">Kompetenzen</a>
            <a class="nav-pill <?= $active === 'templates' ? 'active' : '' ?>" href="<?= route('/templates') ?>">Satzbausteine</a>
            <a class="nav-pill <?= $active === 'letter_templates' ? 'active' : '' ?>" href="<?= route('/letter-templates') ?>">Lernbriefvorlagen</a>
            <a class="nav-pill <?= $active === 'data' ? 'active' : '' ?>" href="<?= route('/data') ?>">Daten & Archiv</a>
        </div>
    </nav>
</header>
<main class="container">
    <?php if ($messages): ?><section class="messages"><?php foreach ($messages as $m): ?><div class="message <?= h($m['type']) ?>"><?= h($m['message']) ?></div><?php endforeach; ?></section><?php endif; ?>
    <?php $content(); ?>
</main>
<script>
    (function () {
        function currentTheme() {
            return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
        }
        function applyTheme(theme) {
            var normalized = theme === 'dark' ? 'dark' : 'light';
            document.documentElement.dataset.theme = normalized;
            var label = document.querySelector('[data-theme-label]');
            if (label) {
                label.textContent = normalized === 'dark' ? 'Hell' : 'Dunkel';
            }
            var button = document.querySelector('[data-theme-toggle]');
            if (button) {
                var actionLabel = normalized === 'dark' ? 'Helles Design aktivieren' : 'Dunkles Design aktivieren';
                button.setAttribute('aria-label', actionLabel);
                button.setAttribute('title', actionLabel);
            }
        }
        document.addEventListener('DOMContentLoaded', function () {
            var stored = 'light';
            try {
                stored = localStorage.getItem('lernbrief-theme') || 'light';
            } catch (e) {}
            applyTheme(stored);
            var button = document.querySelector('[data-theme-toggle]');
            if (!button) {
                return;
            }
            button.addEventListener('click', function () {
                var next = currentTheme() === 'dark' ? 'light' : 'dark';
                applyTheme(next);
                try {
                    localStorage.setItem('lernbrief-theme', next);
                } catch (e) {}
            });
        });
    })();
</script>
</body>
</html><?php
}

function page_index(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $httpUser = current_http_user();
    $groups = query_groups();
    $recent = all('
        SELECT l.id, l.semester, l.created_at, s.full_name
        FROM letters l
        JOIN students s ON s.id = l.student_id
        JOIN groups g ON g.id = s.group_id
        WHERE COALESCE(g.is_active, 1) = 1
          AND COALESCE(s.is_active, 1) = 1
        ORDER BY l.created_at DESC
        LIMIT 10
    ');
    $results = $q === '' ? [] : all('
        SELECT s.id AS student_id, s.full_name, g.id AS group_id, g.name AS group_name
        FROM students s JOIN groups g ON g.id = s.group_id
        WHERE COALESCE(g.is_active, 1) = 1
          AND COALESCE(s.is_active, 1) = 1
          AND (s.full_name LIKE ? OR g.name LIKE ?)
        ORDER BY s.full_name ASC LIMIT 50
    ', ["%{$q}%", "%{$q}%"]);
    layout('index', function () use ($q, $httpUser, $groups, $recent, $results) { ?>
<?php if ($httpUser !== ''): ?><section class="panel welcome-panel"><h2>Willkommen, <?= h($httpUser) ?></h2><p>Du bist angemeldet und arbeitest im Lernbrief-Hub.</p></section><?php endif; ?>
<section class="panel">
    <h2>Schuelersuche</h2>
    <form method="get" class="inline-form">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Name oder Lerngruppe suchen" required>
        <button type="submit">Suchen</button>
        <?php if ($q !== ''): ?><a class="button-link" href="<?= route('/') ?>">Zuruecksetzen</a><?php endif; ?>
    </form>
    <?php if ($q !== ''): ?><table><thead><tr><th>Schueler</th><th>Lerngruppe</th><th>Bewertung</th><th>Schuelerakte</th></tr></thead><tbody>
        <?php foreach ($results as $s): ?><tr><td><?= h($s['full_name']) ?></td><td><?= h($s['group_name']) ?></td><td><a class="button-link" href="<?= route('/ratings', ['student_id' => $s['student_id'], 'semester' => default_semester()]) ?>">Oeffnen</a></td><td><a class="button-link" href="<?= route('/ratings', ['student_id' => $s['student_id'], 'semester' => default_semester()]) ?>#student-record">Anzeigen</a></td></tr><?php endforeach; ?>
        <?php if (!$results): ?><tr><td colspan="4">Keine Schueler zur Suche gefunden.</td></tr><?php endif; ?>
    </tbody></table><?php endif; ?>
</section>
<section class="panel"><h2>Lerngruppen</h2><table><thead><tr><th>Gruppe</th><th>Anzahl Schueler</th><th>Aktion</th></tr></thead><tbody>
    <?php foreach ($groups as $group): ?><tr><td><?= h($group['name']) ?></td><td><?= h($group['student_count']) ?></td><td><a class="button-link" href="<?= route('/groups/show', ['id' => $group['id']]) ?>">Oeffnen</a></td></tr><?php endforeach; ?>
    <?php if (!$groups): ?><tr><td colspan="3">Noch keine Lerngruppe vorhanden.</td></tr><?php endif; ?>
</tbody></table></section>
<section class="panel"><h2>Zuletzt erzeugte Lernbriefe</h2><table><thead><tr><th>Schueler</th><th>Halbjahr</th><th>Erstellt am</th><th>Aktion</th><th>PDF</th><th>Word (.doc)</th></tr></thead><tbody>
    <?php foreach ($recent as $letter): ?><tr><td><?= h($letter['full_name']) ?></td><td><?= h($letter['semester']) ?></td><td><?= h($letter['created_at']) ?></td><td><a class="button-link" href="<?= route('/letters/show', ['id' => $letter['id']]) ?>">Anzeigen</a></td><td><a class="button-link" href="<?= route('/letters/pdf', ['id' => $letter['id']]) ?>">Export</a></td><td><a class="button-link" href="<?= route('/letters/word', ['id' => $letter['id']]) ?>">Export</a></td></tr><?php endforeach; ?>
    <?php if (!$recent): ?><tr><td colspan="6">Noch keine Lernbriefe erzeugt.</td></tr><?php endif; ?>
</tbody></table></section><?php });
}

function page_overview(): void
{
    $summary = one('
        SELECT
        (SELECT COUNT(*) FROM groups WHERE COALESCE(is_active, 1) = 1) AS group_count,
        (SELECT COUNT(*) FROM students s JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1) AS student_count,
        (SELECT COUNT(*) FROM competencies) AS competency_count,
        (SELECT COUNT(*) FROM ratings r JOIN students s ON s.id = r.student_id JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1) AS rating_count,
        (SELECT COUNT(*) FROM letters l JOIN students s ON s.id = l.student_id JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1) AS letter_count,
        (SELECT COUNT(DISTINCT r.student_id) FROM ratings r JOIN students s ON s.id = r.student_id JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1) AS rated_student_count,
        (SELECT COUNT(DISTINCT l.student_id) FROM letters l JOIN students s ON s.id = l.student_id JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1) AS letter_student_count,
        (SELECT COUNT(DISTINCT r.semester) FROM ratings r JOIN students s ON s.id = r.student_id JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1) AS rating_semester_count,
        (SELECT COUNT(DISTINCT l.semester) FROM letters l JOIN students s ON s.id = l.student_id JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1) AS letter_semester_count,
        (SELECT COUNT(*) FROM ratings r JOIN students s ON s.id = r.student_id JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1 AND r.semester = ?) AS current_semester_rating_count,
        (SELECT COUNT(*) FROM letters l JOIN students s ON s.id = l.student_id JOIN groups g ON g.id = s.group_id WHERE COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1 AND l.semester = ?) AS current_semester_letter_count
    ', [default_semester(), default_semester()]);
    $largest = all('SELECT g.id, g.name, COUNT(s.id) AS student_count FROM groups g LEFT JOIN students s ON s.group_id = g.id AND COALESCE(s.is_active, 1) = 1 WHERE COALESCE(g.is_active, 1) = 1 GROUP BY g.id, g.name ORDER BY student_count DESC, g.name ASC LIMIT 5');
    $activity = all('
        SELECT semester, SUM(rating_count) AS rating_count, SUM(letter_count) AS letter_count
        FROM (
            SELECT r.semester, COUNT(*) AS rating_count, 0 AS letter_count
            FROM ratings r
            JOIN students s ON s.id = r.student_id
            JOIN groups g ON g.id = s.group_id
            WHERE COALESCE(g.is_active, 1) = 1
              AND COALESCE(s.is_active, 1) = 1
            GROUP BY r.semester

            UNION ALL

            SELECT l.semester, 0 AS rating_count, COUNT(*) AS letter_count
            FROM letters l
            JOIN students s ON s.id = l.student_id
            JOIN groups g ON g.id = s.group_id
            WHERE COALESCE(g.is_active, 1) = 1
              AND COALESCE(s.is_active, 1) = 1
            GROUP BY l.semester
        ) activity
        GROUP BY semester
        ORDER BY semester DESC
        LIMIT 6
    ');
    $recent = all('
        SELECT l.id, l.semester, l.created_at, s.full_name
        FROM letters l
        JOIN students s ON s.id = l.student_id
        JOIN groups g ON g.id = s.group_id
        WHERE COALESCE(g.is_active, 1) = 1
          AND COALESCE(s.is_active, 1) = 1
        ORDER BY l.created_at DESC
        LIMIT 5
    ');
    layout('overview', function () use ($summary, $largest, $activity, $recent) { ?>
<section class="panel overview-hero"><div><h2>Gesamtuebersicht</h2><p>Wichtige Kennzahlen, aktuelle Aktivitaet und ein schneller Blick auf die Nutzung des Lernbrief-Hub.</p></div><div class="overview-badge">Aktuelles Halbjahr: <?= h(default_semester()) ?></div></section>
<section class="overview-stats">
    <?php foreach ([['Schueler','student_count','group_count','Lerngruppen insgesamt','accent-teal'],['Lernbriefe','letter_count','letter_student_count','Schueler mit gespeicherten Briefen','accent-blue'],['Bewertungen','rating_count','rated_student_count','Schueler bereits bewertet','accent-gold'],['Kompetenzen','competency_count',null,'Aktiv im Bewertungssystem hinterlegt','accent-slate']] as $card): ?>
    <article class="stat-card <?= $card[4] ?>"><span class="stat-label"><?= h($card[0]) ?></span><strong class="stat-value"><?= h($summary[$card[1]] ?? 0) ?></strong><p><?= $card[2] ? h($summary[$card[2]] ?? 0) . ' ' : '' ?><?= h($card[3]) ?></p></article>
    <?php endforeach; ?>
</section>
<section class="overview-grid">
    <section class="panel"><h3>Aktuelles Halbjahr</h3><div class="overview-kpis"><?php foreach ([['Bewertungen','current_semester_rating_count'],['Lernbriefe','current_semester_letter_count'],['Bewertete Halbjahre','rating_semester_count'],['Brief-Halbjahre','letter_semester_count']] as $k): ?><div><span class="mini-kpi-label"><?= h($k[0]) ?></span><strong><?= h($summary[$k[1]] ?? 0) ?></strong></div><?php endforeach; ?></div></section>
    <section class="panel"><h3>Groesste Lerngruppen</h3><?= table_rows(['Lerngruppe','Schueler','Aktion'], $largest, fn($g) => [h($g['name']), h($g['student_count']), '<a class="button-link" href="'.route('/groups/show', ['id'=>$g['id']]).'">Oeffnen</a>'], 'Noch keine Lerngruppen vorhanden.') ?></section>
    <section class="panel"><h3>Aktivitaet nach Halbjahr</h3><?= table_rows(['Halbjahr','Bewertungen','Lernbriefe'], $activity, fn($r) => [h($r['semester']), h($r['rating_count']), h($r['letter_count'])], 'Noch keine Halbjahresaktivitaet vorhanden.') ?></section>
    <section class="panel"><h3>Zuletzt gespeicherte Lernbriefe</h3><?= table_rows(['Schueler','Halbjahr','Erstellt am','Aktion'], $recent, fn($l) => [h($l['full_name']), h($l['semester']), h($l['created_at']), '<a class="button-link" href="'.route('/letters/show', ['id'=>$l['id']]).'">Anzeigen</a>'], 'Noch keine Lernbriefe gespeichert.') ?></section>
</section><?php });
}

function table_rows(array $headers, array $rows, callable $map, string $empty): string
{
    ob_start(); ?><table><thead><tr><?php foreach ($headers as $h): ?><th><?= h($h) ?></th><?php endforeach; ?></tr></thead><tbody><?php
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($map($row) as $cell) echo '<td>' . $cell . '</td>';
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="' . count($headers) . '">' . h($empty) . '</td></tr>';
    ?></tbody></table><?php return ob_get_clean();
}

function page_new_group(): void
{
    layout('new_group', function () { ?>
<section class="panel"><h2>Lerngruppe anlegen</h2><form action="<?= route('/groups/create') ?>" method="post" class="inline-form"><input type="text" name="name" placeholder="Name der Lerngruppe" required><button type="submit">Anlegen</button></form></section><?php });
}

function page_group_show(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $group = one('SELECT * FROM groups WHERE id = ? AND COALESCE(is_active, 1) = 1', [$id]);
    if (!$group) { flash('Lerngruppe nicht gefunden.', 'error'); redirect_to('/'); }
    $students = all('SELECT * FROM students WHERE group_id = ? AND COALESCE(is_active, 1) = 1 ORDER BY full_name ASC', [$id]);
    $inactiveStudents = all('SELECT * FROM students WHERE group_id = ? AND COALESCE(is_active, 1) = 0 ORDER BY full_name ASC', [$id]);
    $semester = normalize_semester($_GET['semester'] ?? default_semester());
    $options = school_semester_options();
    $intro = one('SELECT intro_text FROM group_semester_intros WHERE group_id = ? AND semester = ?', [$id, $semester]);
    layout('index', function () use ($group, $students, $inactiveStudents, $semester, $options, $intro) { ?>
<section class="panel"><h2>Lerngruppe: <?= h($group['name']) ?></h2><form action="<?= route('/students/create', ['group_id' => $group['id']]) ?>" method="post" class="inline-form"><input type="text" name="full_name" placeholder="Schuelername" required><button type="submit">Schueler hinzufuegen</button></form></section>
<section class="panel"><h3>Halbjahrestext der Lerngruppe</h3><p>Dieser Einfuehrungstext wird im Lernbrief aller Schueler dieser Lerngruppe fuer das ausgewaehlte Halbjahr unterhalb des Headers eingefuegt.</p>
<form method="get" class="inline-form"><input type="hidden" name="r" value="/groups/show"><input type="hidden" name="id" value="<?= h($group['id']) ?>"><label for="semester">Halbjahr:</label><select id="semester" name="semester"><?php foreach ($options as $o): ?><option value="<?= h($o) ?>" <?= $o === $semester ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select><button type="submit">Laden</button></form>
<form method="post" action="<?= route('/groups/semester-text', ['id' => $group['id']]) ?>" class="grid-form"><input type="hidden" name="semester" value="<?= h($semester) ?>"><textarea name="semester_intro_text" class="semester-textarea" rows="4" placeholder="Optional"><?= h($intro['intro_text'] ?? '') ?></textarea><button type="submit">Halbjahrestext speichern</button></form></section>
<section class="panel"><h3>Aktive Schueler (<?= count($students) ?>)</h3><?= table_rows(['Name','Bewertung','Schuelerakte','Status'], $students, fn($s) => [h($s['full_name']), '<a class="button-link" href="'.route('/ratings', ['student_id'=>$s['id'], 'semester'=>default_semester()]).'">Oeffnen</a>', '<a class="button-link" href="'.route('/ratings', ['student_id'=>$s['id'], 'semester'=>default_semester()]).'#student-record">Anzeigen</a>', '<form method="post" action="'.route('/students/deactivate', ['group_id'=>$s['group_id']]).'"><input type="hidden" name="student_id" value="'.h($s['id']).'"><button type="submit" onclick="return confirm(\'Diesen Schueler deaktivieren?\')">Deaktivieren</button></form>'], 'Noch keine aktiven Schueler in dieser Gruppe.') ?></section>
<section class="panel"><h3>Deaktivierte Schueler (<?= count($inactiveStudents) ?>)</h3><?= table_rows(['Name','Status'], $inactiveStudents, fn($s) => [h($s['full_name']), '<form method="post" action="'.route('/students/reactivate', ['group_id'=>$s['group_id']]).'"><input type="hidden" name="student_id" value="'.h($s['id']).'"><button type="submit">Reaktivieren</button></form>'], 'Keine deaktivierten Schueler in dieser Gruppe.') ?></section><?php });
}

function page_competencies(): void
{
    $rows = query_competencies();
    layout('competencies', function () use ($rows) { ?>
<section class="panel"><h2>Kompetenzen verwalten</h2><form method="post" action="<?= route('/competencies/save') ?>" class="grid-form"><input type="hidden" name="action" value="create"><input type="text" name="name" placeholder="Kompetenzname" required><input type="text" name="description" placeholder="Beschreibung"><input type="number" name="sort_order" placeholder="Sortierung" value="0"><button type="submit">Kompetenz anlegen</button></form></section>
<section class="panel"><table><thead><tr><th>Reihenfolge</th><th>Name</th><th>Beschreibung</th><th>Aktion</th></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr><form method="post" action="<?= route('/competencies/save') ?>"><td><input type="number" name="sort_order" value="<?= h($c['sort_order']) ?>" required></td><td><input type="text" name="name" value="<?= h($c['name']) ?>" required></td><td><input type="text" name="description" value="<?= h($c['description']) ?>"></td><td><input type="hidden" name="action" value="update"><input type="hidden" name="competency_id" value="<?= h($c['id']) ?>"><button type="submit">Speichern</button></td></form></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="4">Keine Kompetenzen angelegt.</td></tr><?php endif; ?></tbody></table></section><?php });
}

function page_templates(): void
{
    $rows = all('SELECT st.id, st.grade, st.sentence, c.name AS competency_name, c.id AS competency_id FROM sentence_templates st JOIN competencies c ON c.id = st.competency_id WHERE st.grade BETWEEN 1 AND 5 ORDER BY c.sort_order ASC, c.name ASC, st.grade ASC');
    $competencies = query_competencies();
    layout('templates', function () use ($rows, $competencies) { ?>
<section class="panel"><h2>Satzbausteine nach Note</h2><p>Hinweis: Mit <strong>{name}</strong> kann der Schuelername im Satz verwendet werden.</p><a class="button-link" href="<?= route('/letter-templates') ?>">Zu den Lernbriefvorlagen</a></section>
<section class="panel"><h3>Neuen Satzbaustein hinzufuegen</h3><form method="post" action="<?= route('/templates/save') ?>" class="grid-form"><input type="hidden" name="action" value="create"><select name="competency_id" required><option value="">Kompetenz waehlen</option><?php foreach ($competencies as $comp): ?><option value="<?= h($comp['id']) ?>"><?= h($comp['name']) ?></option><?php endforeach; ?></select><select name="grade" required><option value="">Note</option><?php foreach (GRADE_OPTIONS as $grade): ?><option value="<?= $grade ?>"><?= $grade ?></option><?php endforeach; ?></select><textarea name="sentence" rows="2" placeholder="Satzbaustein" required></textarea><button type="submit">Hinzufuegen</button></form></section>
<section class="panel"><h3>Bestehende Satzbausteine</h3><table><thead><tr><th>Kompetenz</th><th>Note</th><th>Satz</th><th>Speichern</th><th>Loeschen</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= h($row['competency_name']) ?></td><td><?= h($row['grade']) ?></td><td><form method="post" action="<?= route('/templates/save') ?>" class="inline-row-form"><input type="hidden" name="action" value="update"><input type="hidden" name="template_id" value="<?= h($row['id']) ?>"><textarea name="sentence" rows="2" required><?= h($row['sentence']) ?></textarea></td><td><button type="submit">Speichern</button></form></td><td><form method="post" action="<?= route('/templates/save') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="template_id" value="<?= h($row['id']) ?>"><button type="submit">Loeschen</button></form></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="5">Noch keine Satzbausteine vorhanden.</td></tr><?php endif; ?></tbody></table></section><?php });
}

function page_letter_templates(): void
{
    $templates = all('SELECT * FROM letter_templates ORDER BY id ASC');
    $requested = (int)($_GET['template_id'] ?? 0);
    $selected = null;
    foreach ($templates as $tpl) {
        if (($requested && (int)$tpl['id'] === $requested) || (!$requested && (int)$tpl['is_active'] === 1)) {
            $selected = $tpl;
            break;
        }
    }
    $selected = $selected ?: ($templates[0] ?? null);
    layout('letter_templates', function () use ($templates, $selected) { ?>
<section class="panel"><h2>Lernbriefvorlagen</h2><p>Hier bearbeitest du komplette Lernbriefvorlagen mit Layout und Textstil. Platzhalter werden beim Generieren automatisch ersetzt.</p><form method="post" action="<?= route('/letter-templates/save') ?>" class="inline-form"><input type="hidden" name="action" value="create"><input type="text" name="new_template_name" placeholder="Neue Vorlagenbezeichnung" required><button type="submit">Vorlage anlegen</button></form></section>
<?php if ($selected): ?><section class="panel"><div class="template-tabs" role="tablist"><?php foreach ($templates as $tpl): ?><a class="template-tab <?= (int)$tpl['id'] === (int)$selected['id'] ? 'active' : '' ?>" href="<?= route('/letter-templates', ['template_id' => $tpl['id']]) ?>"><?= h($tpl['name']) ?><?= (int)$tpl['is_active'] ? ' (aktiv)' : '' ?></a><?php endforeach; ?></div>
<form method="post" action="<?= route('/letter-templates/save') ?>" class="template-config-form"><input type="hidden" name="template_id" value="<?= h($selected['id']) ?>"><div class="inline-form"><input type="text" name="name" value="<?= h($selected['name']) ?>" required><button type="submit" name="action" value="save">Vorlage speichern</button><button type="submit" name="action" value="activate">Als aktiv setzen</button><button type="submit" name="action" value="delete" onclick="return confirm('Diese Vorlage wirklich loeschen?')">Loeschen</button><a class="button-link" href="<?= route('/letter-templates/preview', ['template_id'=>$selected['id']]) ?>" target="_blank">Vorschau</a></div>
<div class="template-config-grid"><article class="template-card"><h4>Header</h4><p class="template-help">Position und Inhalt des Kopfbereichs.</p><label>Header-Position</label><select name="header_position"><option value="top" <?= $selected['header_position']==='top'?'selected':'' ?>>Ganz oben</option><option value="after_intro" <?= $selected['header_position']==='after_intro'?'selected':'' ?>>Nach Einleitung</option></select><?= rich_editor('header_html', (string)$selected['header_html'], true) ?><div class="placeholder-chips"><span>{name}</span><span>{full_name}</span><span>{group_name}</span><span>{semester}</span><span>{avg_grade}</span><span>{avg_text}</span></div></article>
<article class="template-card"><h4>Footer</h4><p class="template-help">Abschlussbereich und optionaler Durchschnittssatz.</p><label>Footer-Position</label><select name="footer_position"><option value="bottom" <?= $selected['footer_position']==='bottom'?'selected':'' ?>>Am Ende</option><option value="after_header" <?= $selected['footer_position']==='after_header'?'selected':'' ?>>Direkt nach Header</option></select><label class="toggle-row"><input type="checkbox" name="include_average_sentence" <?= (int)$selected['include_average_sentence'] ? 'checked' : '' ?>> Satz mit Durchschnitt anzeigen</label><label>Durchschnittssatz</label><textarea name="average_sentence_template" rows="3" required><?= h($selected['average_sentence_template']) ?></textarea><?= rich_editor('footer_html', (string)$selected['footer_html'], false) ?></article></div>
<section class="panel template-layout-panel"><h4>Einheitlicher Textstil fuer Satzbausteine</h4><div class="inline-form"><label for="body_font_family">Schriftart</label><select id="body_font_family" name="body_font_family"><?php foreach (['Arial','Georgia','Times New Roman','Verdana','Calibri'] as $font): ?><option value="<?= h($font) ?>" <?= $selected['body_font_family']===$font?'selected':'' ?>><?= h($font) ?></option><?php endforeach; ?></select><label for="body_font_size">Schriftgroesse</label><input id="body_font_size" type="number" name="body_font_size" min="4" max="28" value="<?= h($selected['body_font_size']) ?>"></div></section>
<section class="panel template-layout-panel"><h4>Export-Abschluss</h4><label class="toggle-row"><input type="checkbox" name="include_export_signature" <?= (int)($selected['include_export_signature'] ?? 1) ? 'checked' : '' ?>> Abschluss mit Datum / Unterschrift im PDF- und Word-Export anzeigen</label><label>Format</label><textarea name="export_signature_template" rows="4"><?= h($selected['export_signature_template'] ?? 'Datum: {date}<br><br>Unterschrift: ______________________________') ?></textarea><div class="placeholder-chips"><span>{date}</span><span>{iso_date}</span><span>{name}</span><span>{full_name}</span><span>{semester}</span><span>{template_name}</span></div></section></form></section><script src="static/rich_editor.js"></script><?php endif; ?><?php });
}

function rich_editor(string $name, string $html, bool $withSize): string
{
    ob_start(); ?><div class="rich-editor" data-editor="<?= h($name) ?>"><?= editor_toolbar($withSize) ?><div class="rich-content" contenteditable="true"><?= $html ?></div><input type="hidden" name="<?= h($name) ?>"></div><?php
    return ob_get_clean();
}

function editor_toolbar(bool $withSize = true): string
{
    ob_start(); ?><div class="rich-toolbar">
        <button type="button" data-cmd="undo" title="Rueckgaengig">Undo</button>
        <button type="button" data-cmd="redo" title="Wiederholen">Redo</button>
        <select data-cmd="formatBlock" title="Absatzformat">
            <option value="p">Absatz</option>
            <option value="h2">Ueberschrift</option>
            <option value="h3">Zwischenueberschrift</option>
        </select>
        <button type="button" data-cmd="bold" title="Fett">B</button>
        <button type="button" data-cmd="italic" title="Kursiv">I</button>
        <button type="button" data-cmd="underline" title="Unterstrichen">U</button>
        <button type="button" data-cmd="insertUnorderedList" title="Liste">Liste</button>
        <button type="button" data-cmd="justifyLeft" title="Linksbuendig">Links</button>
        <button type="button" data-cmd="justifyCenter" title="Zentriert">Mitte</button>
        <button type="button" data-cmd="justifyRight" title="Rechtsbuendig">Rechts</button>
        <button type="button" data-cmd="createLink" title="Link einfuegen">Link</button>
        <button type="button" data-cmd="removeFormat" title="Format entfernen">Format loeschen</button>
        <select data-cmd="fontName" title="Schriftart">
            <option value="Arial">Arial</option>
            <option value="Georgia">Georgia</option>
            <option value="Times New Roman">Times New Roman</option>
            <option value="Verdana">Verdana</option>
            <option value="Calibri">Calibri</option>
        </select>
        <?php if ($withSize): ?><select data-cmd="fontSize" title="Schriftgroesse">
            <option value="2">Klein</option>
            <option value="3" selected>Normal</option>
            <option value="4">Gross</option>
            <option value="5">Sehr gross</option>
        </select><?php endif; ?>
    </div><?php
    return ob_get_clean();
}

function letter_template_preview_html(array $tpl): string
{
    $avg = 2.4;
    $values = [
        'name' => 'Max Beispiel',
        'full_name' => 'Max Beispiel',
        'group_name' => 'Beispielgruppe 7a',
        'semester' => default_semester(),
        'avg_grade' => number_format($avg, 2, '.', ''),
        'avg_text' => avg_text($avg),
    ];
    $header = normalize_inline_html(safe_format((string)$tpl['header_html'], $values));
    $footerParts = [];
    if ((int)($tpl['include_average_sentence'] ?? 1) === 1) {
        $footerParts[] = ensure_sentence_punctuation(safe_format((string)$tpl['average_sentence_template'], $values));
    }
    $customFooter = normalize_inline_html(safe_format((string)($tpl['footer_html'] ?? ''), $values));
    if (trim($customFooter) !== '') {
        $footerParts[] = $customFooter;
    }
    $ratings = [
        '<p>In Fachwissen arbeitet Max sicher mit den Grundlagen und kann Gelerntes zunehmend selbststaendig anwenden.</p>',
        '<p>In Mitarbeit beteiligt sich Max regelmaessig und bringt passende Beitraege in Unterrichtsgespraeche ein.</p>',
        '<p>In Selbstorganisation gelingt es Max, Aufgaben sorgfaeltig zu planen und rechtzeitig abzugeben.</p>',
    ];
    $parts = [];
    $headerPosition = in_array($tpl['header_position'] ?? 'top', ['top', 'after_intro'], true) ? $tpl['header_position'] : 'top';
    $footerPosition = in_array($tpl['footer_position'] ?? 'bottom', ['bottom', 'after_header'], true) ? $tpl['footer_position'] : 'bottom';
    if ($headerPosition === 'top') {
        $parts[] = "<div class='letter-header'>" . ensure_block_html($header) . "</div>";
    }
    if ($footerPosition === 'after_header') {
        foreach ($footerParts as $part) {
            $parts[] = "<div class='letter-footer'>" . ensure_block_html($part) . "</div>";
        }
    }
    $parts[] = '<p>Dies ist ein Beispieltext fuer den Halbjahreseindruck der Lerngruppe.</p>';
    if ($headerPosition === 'after_intro') {
        $parts[] = "<div class='letter-header'>" . ensure_block_html($header) . "</div>";
    }
    $parts = array_merge($parts, $ratings);
    if ($footerPosition === 'bottom') {
        foreach ($footerParts as $part) {
            $parts[] = "<div class='letter-footer'>" . ensure_block_html($part) . "</div>";
        }
    }
    $signature = export_signature_html(['full_name' => 'Max Beispiel', 'semester' => default_semester(), 'template_name' => $tpl['name']], $tpl);
    if ($signature !== '') {
        $parts[] = $signature;
    }
    $font = $tpl['body_font_family'] ?: 'Georgia';
    $size = max(4, min(28, (int)($tpl['body_font_size'] ?? 16)));
    return "<div class='letter-preview-content' style='font-family:" . h($font) . ";font-size:{$size}px;line-height:1.35;'>" . implode('', $parts) . '</div>';
}

function page_letter_template_preview(): void
{
    $id = (int)($_GET['template_id'] ?? 0);
    $tpl = $id > 0 ? one('SELECT * FROM letter_templates WHERE id = ?', [$id]) : active_letter_template();
    if (!$tpl) {
        flash('Lernbriefvorlage nicht gefunden.', 'error');
        redirect_to('/letter-templates');
    }
    layout('letter_templates', function () use ($tpl) { ?>
<section class="panel"><h2>Vorschau: <?= h($tpl['name']) ?></h2><p>Diese Vorschau nutzt Beispieldaten und veraendert keine gespeicherten Lernbriefe.</p><div class="inline-form"><a class="button-link" href="<?= route('/letter-templates', ['template_id'=>$tpl['id']]) ?>">Zurueck zur Vorlage</a></div></section>
<section class="panel letter-preview"><?= letter_template_preview_html($tpl) ?></section><?php });
}

function build_student_semester_overview(int $studentId): array
{
    $semesterStats = all('
        SELECT semester, ROUND(AVG(grade), 2) AS avg_grade, COUNT(*) AS rating_count
        FROM ratings
        WHERE student_id = ?
        GROUP BY semester
        ORDER BY semester DESC
    ', [$studentId]);
    $letterStats = all('
        SELECT semester, COUNT(*) AS letter_count, MAX(created_at) AS last_letter_created_at
        FROM letters
        WHERE student_id = ?
        GROUP BY semester
    ', [$studentId]);
    $lettersBySemester = [];
    foreach ($letterStats as $row) {
        $lettersBySemester[(string)$row['semester']] = $row;
    }
    $overview = [];
    foreach ($semesterStats as $row) {
        $letterInfo = $lettersBySemester[(string)$row['semester']] ?? null;
        $overview[] = [
            'semester' => $row['semester'],
            'avg_grade' => $row['avg_grade'],
            'rating_count' => $row['rating_count'],
            'letter_count' => $letterInfo ? $letterInfo['letter_count'] : 0,
            'last_letter_created_at' => $letterInfo ? $letterInfo['last_letter_created_at'] : '-',
        ];
    }
    return $overview;
}

function build_competency_trends(int $studentId, array $competencies): array
{
    $rows = all('
        SELECT r.semester, r.competency_id, c.name AS competency_name, r.grade
        FROM ratings r
        JOIN competencies c ON c.id = r.competency_id
        WHERE r.student_id = ?
    ', [$studentId]);

    $semesterSet = [];
    $gradesByCompetency = [];
    foreach ($rows as $row) {
        $semester = (string)$row['semester'];
        if (parse_semester($semester)) {
            $semesterSet[$semester] = true;
        }
        $gradesByCompetency[(int)$row['competency_id']][$semester] = (int)$row['grade'];
    }
    $semesters = array_keys($semesterSet);
    usort($semesters, fn($a, $b) => semester_sort_value($a) <=> semester_sort_value($b));

    $trends = [];
    foreach ($competencies as $comp) {
        $points = [];
        $gradedValues = [];
        foreach ($semesters as $sem) {
            $grade = $gradesByCompetency[(int)$comp['id']][$sem] ?? null;
            if ($grade !== null) {
                $gradedValues[] = $grade;
            }
            $points[] = [
                'semester' => $sem,
                'grade' => $grade,
                'score_percent' => $grade === null ? 0 : max(0, min(100, round(((6 - $grade) / 5) * 100, 1))),
                'grade_class' => $grade === null ? 'grade-none' : 'grade-' . $grade,
            ];
        }

        $latest = $gradedValues ? $gradedValues[count($gradedValues) - 1] : null;
        $previous = count($gradedValues) >= 2 ? $gradedValues[count($gradedValues) - 2] : null;
        $deltaText = '-';
        $trendClass = 'trend-neutral';
        if ($latest !== null && $previous !== null) {
            $delta = $latest - $previous;
            if ($delta < 0) {
                $deltaText = 'Verbessert um ' . abs($delta);
                $trendClass = 'trend-up';
            } elseif ($delta > 0) {
                $deltaText = 'Verschlechtert um ' . $delta;
                $trendClass = 'trend-down';
            } else {
                $deltaText = 'Konstant';
            }
        }

        $trends[] = [
            'name' => $comp['name'],
            'points' => $points,
            'latest_grade' => $latest,
            'delta_text' => $deltaText,
            'trend_class' => $trendClass,
        ];
    }

    return ['semesters' => $semesters, 'trends' => $trends];
}

function page_ratings(): void
{
    $studentId = (int)($_GET['student_id'] ?? 0);
    $semester = normalize_semester($_REQUEST['semester'] ?? default_semester());
    $student = one('SELECT s.id, s.group_id, s.full_name, g.name AS group_name FROM students s JOIN groups g ON g.id = s.group_id WHERE s.id = ? AND COALESCE(g.is_active, 1) = 1 AND COALESCE(s.is_active, 1) = 1', [$studentId]);
    if (!$student) { flash('Schueler nicht gefunden.', 'error'); redirect_to('/'); }
    $archived = archived_semesters();
    $options = array_values(array_filter(school_semester_options(), fn($s) => !in_array($s, $archived, true)));
    if (!in_array($semester, $options, true)) $options[] = $semester;
    usort($options, fn($a, $b) => semester_sort_value($b) <=> semester_sort_value($a));
    $competencies = query_competencies();
    $ratings = all('SELECT competency_id, grade, note FROM ratings WHERE student_id = ? AND semester = ?', [$studentId, $semester]);
    $ratingsMap = [];
    foreach ($ratings as $r) $ratingsMap[(int)$r['competency_id']] = $r;
    $letters = all('SELECT id, semester, created_at FROM letters WHERE student_id = ? ORDER BY created_at DESC', [$studentId]);
    $history = all('SELECT r.semester, c.name AS competency_name, r.grade, r.note FROM ratings r JOIN competencies c ON c.id = r.competency_id WHERE r.student_id = ? ORDER BY r.semester DESC, c.sort_order ASC, c.name ASC', [$studentId]);
    $overview = build_student_semester_overview($studentId);
    $trendData = build_competency_trends($studentId, $competencies);
    $goal = one('SELECT goal_text FROM student_semester_goals WHERE student_id = ? AND semester = ?', [$studentId, $semester]);
    layout('index', function () use ($student, $semester, $options, $competencies, $ratingsMap, $letters, $history, $overview, $trendData, $goal) { ?>
<section class="panel"><h2>Bewertung: <?= h($student['full_name']) ?></h2><p>Lerngruppe: <?= h($student['group_name']) ?></p><form method="get" class="inline-form"><input type="hidden" name="r" value="/ratings"><input type="hidden" name="student_id" value="<?= h($student['id']) ?>"><label for="semester">Halbjahr:</label><select id="semester" name="semester"><?php foreach ($options as $o): ?><option value="<?= h($o) ?>" <?= $o===$semester?'selected':'' ?>><?= h($o) ?></option><?php endforeach; ?></select><button type="submit">Laden</button></form>
<form method="post" action="<?= route('/ratings/save', ['student_id'=>$student['id']]) ?>"><input type="hidden" name="action" value="save_ratings"><input type="hidden" name="semester" value="<?= h($semester) ?>"><table><thead><tr><th>Kompetenz</th><th>Note</th><th>Zusatz / Beobachtung</th></tr></thead><tbody><?php foreach ($competencies as $comp): $r=$ratingsMap[(int)$comp['id']] ?? null; ?><tr><td><?= h($comp['name']) ?></td><td><select name="grade_<?= h($comp['id']) ?>" required><option value="">-</option><?php foreach (GRADE_OPTIONS as $grade): ?><option value="<?= $grade ?>" <?= $r && (int)$r['grade']===$grade?'selected':'' ?>><?= $grade ?></option><?php endforeach; ?></select></td><td><textarea name="note_<?= h($comp['id']) ?>" class="rating-note" rows="2" placeholder="Optional"><?= h($r['note'] ?? '') ?></textarea></td></tr><?php endforeach; ?></tbody></table><button type="submit">Bewertungen speichern</button></form></section>
<section class="panel"><h3>Halbjahresziele (Schueler)</h3><form method="post" action="<?= route('/ratings/save', ['student_id'=>$student['id']]) ?>" class="grid-form"><input type="hidden" name="action" value="save_student_goal"><input type="hidden" name="semester" value="<?= h($semester) ?>"><label for="semester_goal_text">Halbjahresziele fuer <?= h($student['full_name']) ?></label><textarea id="semester_goal_text" name="semester_goal_text" class="semester-textarea" rows="4" placeholder="Optional"><?= h($goal['goal_text'] ?? '') ?></textarea><button type="submit">Halbjahresziel speichern</button></form></section>
<section class="panel"><h3>Lernbrief erzeugen</h3><form method="post" action="<?= route('/letters/generate', ['student_id'=>$student['id']]) ?>" class="inline-form"><input type="hidden" name="semester" value="<?= h($semester) ?>"><button type="submit">Lernbrief fuer dieses Halbjahr generieren</button></form><h4>Bereits gespeicherte Lernbriefe</h4><?= table_rows(['Halbjahr','Erstellt am','Anzeigen','PDF','Word (.doc)','Loeschen'], $letters, fn($l) => [h($l['semester']), h($l['created_at']), '<a class="button-link" href="'.route('/letters/show', ['id'=>$l['id']]).'">Anzeigen</a>', '<a class="button-link" href="'.route('/letters/pdf', ['id'=>$l['id']]).'">Export</a>', '<a class="button-link" href="'.route('/letters/word', ['id'=>$l['id']]).'">Export</a>', '<form method="post" action="'.route('/letters/delete', ['id'=>$l['id']]).'"><input type="hidden" name="next" value="ratings"><button type="submit">Loeschen</button></form>'], 'Noch keine Lernbriefe vorhanden.') ?></section>
<section class="panel" id="student-record"><h3>Bisherige Bewertungen (alle Halbjahre)</h3><?= table_rows(['Halbjahr','Kompetenz','Note','Zusatz'], $history, fn($r) => [h($r['semester']), h($r['competency_name']), h($r['grade']), h($r['note'] ?: '-')], 'Noch keine bisherigen Bewertungen vorhanden.') ?></section>
<section class="panel"><h3>Kompetenzentwicklung ueber Halbjahre</h3><?php if ($trendData['semesters']): ?><table><thead><tr><th>Kompetenz</th><th>Verlauf</th><th>Letzte Note</th><th>Trend</th></tr></thead><tbody><?php foreach ($trendData['trends'] as $row): ?><tr><td><?= h($row['name']) ?></td><td><div class="trend-track"><?php foreach ($row['points'] as $point): ?><div class="trend-point"><span class="trend-semester"><?= h($point['semester']) ?></span><div class="trend-bar-bg"><div class="trend-bar <?= h($point['grade_class']) ?>" style="height: <?= h($point['score_percent']) ?>%;"></div></div><span class="trend-grade"><?= $point['grade'] !== null ? h($point['grade']) : '-' ?></span></div><?php endforeach; ?></div></td><td><?= $row['latest_grade'] !== null ? h($row['latest_grade']) : '-' ?></td><td><span class="trend-pill <?= h($row['trend_class']) ?>"><?= h($row['delta_text']) ?></span></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p>Fuer diese Schuelerakte sind noch keine Halbjahresverlaeufe vorhanden.</p><?php endif; ?></section>
<section class="panel"><h3>Halbjahresuebersicht</h3><?= table_rows(['Halbjahr','Durchschnitt','Anzahl Bewertungen','Anzahl Lernbriefe','Letzter Lernbrief'], $overview, fn($r) => [h($r['semester']), h($r['avg_grade']), h($r['rating_count']), h($r['letter_count']), h($r['last_letter_created_at'])], 'Noch keine Halbjahresdaten vorhanden.') ?></section><?php });
}

function page_letter_show(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $letter = one('SELECT l.*, s.full_name FROM letters l JOIN students s ON s.id = l.student_id WHERE l.id = ?', [$id]);
    if (!$letter) { flash('Lernbrief nicht gefunden.', 'error'); redirect_to('/'); }
    layout('index', function () use ($letter) { ?>
<section class="panel"><h2>Lernbrief: <?= h($letter['full_name']) ?></h2><p>Halbjahr: <?= h($letter['semester']) ?> | Erstellt am: <?= h($letter['created_at']) ?></p><p>Vorlage: <?= h($letter['template_name'] ?: 'Standard') ?></p><div class="inline-form"><a class="button-link" href="<?= route('/letters/pdf', ['id'=>$letter['id']]) ?>">Als PDF exportieren</a><a class="button-link" href="<?= route('/letters/word', ['id'=>$letter['id']]) ?>">Als Word (.doc) exportieren</a></div><form method="post" action="<?= route('/letters/update', ['id'=>$letter['id']]) ?>" class="grid-form" data-letter-editor><label>Lernbriefinhalt bearbeiten</label><?= editor_toolbar(true) ?><div class="rich-content letter-edit-content" contenteditable="true"><?= str_replace("\n", '<br>', (string)$letter['content']) ?></div><input type="hidden" name="content_html"><button type="submit">Aenderungen speichern</button></form><form method="post" action="<?= route('/letters/delete', ['id'=>$letter['id']]) ?>" class="inline-form"><input type="hidden" name="next" value="ratings"><button type="submit">Lernbrief loeschen</button></form></section><script src="static/rich_editor.js"></script><?php });
}

function page_data(): void
{
    $archived = archived_semesters();
    $exportChecks = export_system_checks();
    $auditRows = all('SELECT actor, action, entity_type, entity_id, details, created_at FROM audit_logs ORDER BY id DESC LIMIT 30');
    $rows = all('SELECT sem.semester, COALESCE(r.rating_count,0) AS rating_count, COALESCE(l.letter_count,0) AS letter_count FROM (SELECT semester FROM ratings UNION SELECT semester FROM letters) sem LEFT JOIN (SELECT semester, COUNT(*) AS rating_count FROM ratings GROUP BY semester) r ON r.semester = sem.semester LEFT JOIN (SELECT semester, COUNT(*) AS letter_count FROM letters GROUP BY semester) l ON l.semester = sem.semester ORDER BY sem.semester DESC');
    $activeGroups = all('
        SELECT g.id, g.name, COUNT(s.id) AS student_count
        FROM groups g
        LEFT JOIN students s ON s.group_id = g.id AND COALESCE(s.is_active, 1) = 1
        WHERE COALESCE(g.is_active, 1) = 1
        GROUP BY g.id, g.name
        ORDER BY g.name ASC
    ');
    $inactiveGroups = all('
        SELECT g.id, COALESCE(g.archived_name, g.name) AS display_name, g.name AS internal_name, COUNT(s.id) AS student_count
        FROM groups g
        LEFT JOIN students s ON s.group_id = g.id AND COALESCE(s.is_active, 1) = 1
        WHERE COALESCE(g.is_active, 1) = 0
        GROUP BY g.id, g.name, g.archived_name
        ORDER BY display_name ASC
    ');
    layout('data', function () use ($rows, $archived, $exportChecks, $auditRows, $activeGroups, $inactiveGroups) { ?>
<section class="panel"><h2>Daten & Archiv</h2><p>Abgeschlossene Halbjahre archivieren und Datenbank-Backup sichern oder wiederherstellen.</p></section>
<section class="panel"><h3>Export-Systemcheck</h3><?= table_rows(['Pruefung','Status','Details'], $exportChecks, fn($c) => [h($c['label']), '<span class="status-pill '.($c['ok']?'status-active':'status-archived').'">'.($c['ok']?'OK':'Fehlt').'</span>', h($c['detail'])], 'Keine Exportpruefungen vorhanden.') ?></section>
<section class="panel"><h3>Archivmodus fuer Halbjahre</h3><p>Aktuell archiviert: <strong><?= count($archived) ?></strong></p><table><thead><tr><th>Halbjahr</th><th>Bewertungen</th><th>Lernbriefe</th><th>Status</th><th>Aktion</th></tr></thead><tbody><?php foreach ($rows as $row): $is=in_array($row['semester'], $archived, true); ?><tr><td><?= h($row['semester']) ?></td><td><?= h($row['rating_count']) ?></td><td><?= h($row['letter_count']) ?></td><td><span class="status-pill <?= $is?'status-archived':'status-active' ?>"><?= $is?'Archiviert':'Aktiv' ?></span></td><td><form method="post" action="<?= route('/data/archive-toggle') ?>"><input type="hidden" name="semester" value="<?= h($row['semester']) ?>"><input type="hidden" name="action" value="<?= $is?'unarchive':'archive' ?>"><button type="submit"><?= $is?'Reaktivieren':'Archivieren' ?></button></form></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="5">Noch keine Halbjahresdaten vorhanden.</td></tr><?php endif; ?></tbody></table></section>
<section class="panel"><h3>Lerngruppen deaktivieren</h3><p>Deaktivierte Lerngruppen verschwinden aus Dashboard, Suche und Uebersicht. Ihre Schueler, Bewertungen und Lernbriefe bleiben erhalten. Der urspruengliche Gruppenname kann danach neu verwendet werden.</p><h4>Aktive Lerngruppen</h4><table><thead><tr><th>Lerngruppe</th><th>Schueler</th><th>Aktion</th></tr></thead><tbody><?php foreach ($activeGroups as $group): ?><tr><td><?= h($group['name']) ?></td><td><?= h($group['student_count']) ?></td><td><form method="post" action="<?= route('/groups/deactivate') ?>"><input type="hidden" name="group_id" value="<?= h($group['id']) ?>"><button type="submit" onclick="return confirm('Diese Lerngruppe deaktivieren?')">Deaktivieren</button></form></td></tr><?php endforeach; ?><?php if (!$activeGroups): ?><tr><td colspan="3">Keine aktiven Lerngruppen vorhanden.</td></tr><?php endif; ?></tbody></table><h4>Deaktivierte Lerngruppen</h4><table><thead><tr><th>Frueherer Name</th><th>Interner Name</th><th>Schueler</th><th>Aktion</th></tr></thead><tbody><?php foreach ($inactiveGroups as $group): ?><tr><td><?= h($group['display_name']) ?></td><td><?= h($group['internal_name']) ?></td><td><?= h($group['student_count']) ?></td><td><form method="post" action="<?= route('/groups/reactivate') ?>"><input type="hidden" name="group_id" value="<?= h($group['id']) ?>"><button type="submit">Reaktivieren</button></form></td></tr><?php endforeach; ?><?php if (!$inactiveGroups): ?><tr><td colspan="4">Keine deaktivierten Lerngruppen vorhanden.</td></tr><?php endif; ?></tbody></table></section>
<section class="panel data-actions-grid"><article class="data-action-card"><h3>Backup erstellen</h3><p>Datei: lernbrief_hub.db (ca. <?= file_exists(DB_PATH) ? (int)(filesize(DB_PATH)/1024) : 0 ?> KB)</p><a class="button-link" href="<?= route('/data/backup') ?>">Backup herunterladen</a></article><article class="data-action-card"><h3>Backup wiederherstellen</h3><p>Wiederherstellung ersetzt die aktuelle Datenbank. Vorher wird automatisch eine Sicherungskopie angelegt.</p><form method="post" action="<?= route('/data/restore') ?>" enctype="multipart/form-data" class="grid-form"><input type="file" name="backup_file" accept=".db,.sqlite,.sqlite3" required><button type="submit">Wiederherstellen</button></form></article></section>
<section class="panel"><h3>Protokoll</h3><?= table_rows(['Zeit','Benutzer','Aktion','Objekt','Details'], $auditRows, fn($r) => [h($r['created_at']), h($r['actor'] ?: '-'), h($r['action']), h($r['entity_type'] . ($r['entity_id'] !== null ? ' #' . $r['entity_id'] : '')), h($r['details'] ?: '-')], 'Noch keine Protokolleintraege vorhanden.') ?></section><?php });
}

function handle_actions(string $r): void
{
    $db = db();
    if ($r === '/groups/create') {
        $name = post('name');
        if ($name === '') flash('Bitte einen Gruppennamen eingeben.', 'error');
        else try { $db->prepare('INSERT INTO groups (name) VALUES (?)')->execute([$name]); audit_log('create', 'group', (int)$db->lastInsertId(), ['name'=>$name]); flash('Lerngruppe erstellt.'); } catch (Throwable) { flash('Diese Lerngruppe existiert bereits.', 'error'); }
        redirect_to('/');
    }
    if ($r === '/groups/deactivate') {
        $groupId = (int)post('group_id');
        $group = one('SELECT id, name FROM groups WHERE id = ? AND COALESCE(is_active, 1) = 1', [$groupId]);
        if (!$group) {
            flash('Lerngruppe nicht gefunden oder bereits deaktiviert.', 'error');
            redirect_to('/data');
        }
        $archivedName = (string)$group['name'];
        $internalName = $archivedName . ' (deaktiviert #' . $groupId . ')';
        $db->prepare('UPDATE groups SET is_active = 0, archived_name = ?, name = ? WHERE id = ?')
            ->execute([$archivedName, $internalName, $groupId]);
        audit_log('deactivate', 'group', $groupId, ['name'=>$archivedName]);
        flash('Lerngruppe wurde deaktiviert. Der Name kann nun neu verwendet werden.');
        redirect_to('/data');
    }
    if ($r === '/groups/reactivate') {
        $groupId = (int)post('group_id');
        $group = one('SELECT id, name, archived_name FROM groups WHERE id = ? AND COALESCE(is_active, 1) = 0', [$groupId]);
        if (!$group) {
            flash('Deaktivierte Lerngruppe nicht gefunden.', 'error');
            redirect_to('/data');
        }
        $targetName = trim((string)($group['archived_name'] ?: $group['name']));
        if ($targetName === '') {
            flash('Lerngruppe kann nicht reaktiviert werden: frueherer Name fehlt.', 'error');
            redirect_to('/data');
        }
        if (one('SELECT id FROM groups WHERE name = ? AND id <> ?', [$targetName, $groupId])) {
            flash('Lerngruppe kann nicht reaktiviert werden, weil der fruehere Name inzwischen wieder verwendet wird.', 'error');
            redirect_to('/data');
        }
        $db->prepare('UPDATE groups SET is_active = 1, name = ?, archived_name = NULL WHERE id = ?')
            ->execute([$targetName, $groupId]);
        audit_log('reactivate', 'group', $groupId, ['name'=>$targetName]);
        flash('Lerngruppe wurde reaktiviert.');
        redirect_to('/data');
    }
    if ($r === '/students/create') {
        $gid = (int)($_GET['group_id'] ?? 0);
        $name = post('full_name');
        if ($name === '') flash('Bitte einen Schuelernamen eingeben.', 'error');
        else { $db->prepare('INSERT INTO students (group_id, full_name) VALUES (?, ?)')->execute([$gid, $name]); audit_log('create', 'student', (int)$db->lastInsertId(), ['group_id'=>$gid,'name'=>$name]); flash('Schueler hinzugefuegt.'); }
        redirect_to('/groups/show', ['id' => $gid]);
    }
    if ($r === '/students/deactivate' || $r === '/students/reactivate') {
        $gid = (int)($_GET['group_id'] ?? 0);
        $studentId = (int)post('student_id');
        $active = $r === '/students/reactivate' ? 1 : 0;
        $student = one('SELECT id, full_name FROM students WHERE id = ? AND group_id = ?', [$studentId, $gid]);
        if (!$student) {
            flash('Schueler nicht gefunden.', 'error');
            redirect_to('/groups/show', ['id'=>$gid]);
        }
        $db->prepare('UPDATE students SET is_active = ? WHERE id = ?')->execute([$active, $studentId]);
        audit_log($active ? 'reactivate' : 'deactivate', 'student', $studentId, ['group_id'=>$gid,'name'=>$student['full_name']]);
        flash($active ? 'Schueler wurde reaktiviert.' : 'Schueler wurde deaktiviert.');
        redirect_to('/groups/show', ['id'=>$gid]);
    }
    if ($r === '/groups/semester-text') {
        $gid = (int)($_GET['id'] ?? 0); $sem = normalize_semester(post('semester')); $text = post('semester_intro_text');
        if (in_array($sem, archived_semesters(), true)) { flash('Archivierte Halbjahre sind schreibgeschuetzt.', 'error'); redirect_to('/groups/show', ['id'=>$gid,'semester'=>$sem]); }
        if ($text !== '') $db->prepare('INSERT INTO group_semester_intros (group_id, semester, intro_text) VALUES (?, ?, ?) ON CONFLICT(group_id, semester) DO UPDATE SET intro_text = excluded.intro_text')->execute([$gid,$sem,$text]);
        else $db->prepare('DELETE FROM group_semester_intros WHERE group_id = ? AND semester = ?')->execute([$gid,$sem]);
        audit_log($text !== '' ? 'save_intro' : 'delete_intro', 'group', $gid, ['semester'=>$sem]);
        flash('Halbjahrestext der Lerngruppe gespeichert.'); redirect_to('/groups/show', ['id'=>$gid,'semester'=>$sem]);
    }
    if ($r === '/competencies/save') {
        $action = post('action', 'create'); $name = post('name'); $desc = post('description'); $sort = (int)post('sort_order', '0');
        try {
            if ($action === 'update') { $cid = (int)post('competency_id'); $db->prepare('UPDATE competencies SET name = ?, description = ?, sort_order = ? WHERE id = ?')->execute([$name,$desc,$sort,$cid]); audit_log('update', 'competency', $cid, ['name'=>$name]); }
            else { $db->prepare('INSERT INTO competencies (name, description, sort_order) VALUES (?, ?, ?)')->execute([$name,$desc,$sort]); $cid=(int)$db->lastInsertId(); foreach (GRADE_OPTIONS as $g) $db->prepare('INSERT INTO sentence_templates (competency_id, grade, semester, sentence) VALUES (?, ?, ?, ?)')->execute([$cid,$g,'*',"In {$name} erreicht {name} aktuell die Note {$g}."]); audit_log('create', 'competency', $cid, ['name'=>$name]); }
            flash($action === 'update' ? 'Kompetenz aktualisiert.' : 'Kompetenz erstellt.');
        } catch (Throwable) { flash('Diese Kompetenz existiert bereits oder ist ungueltig.', 'error'); }
        redirect_to('/competencies');
    }
    if ($r === '/templates/save') {
        $action = post('action');
        if ($action === 'create') {
            $grade = (int)post('grade');
            if (!is_valid_grade($grade)) {
                flash('Bitte eine gueltige Note zwischen 1 und 5 auswaehlen.', 'error');
                redirect_to('/templates');
            }
            $db->prepare('INSERT INTO sentence_templates (competency_id, grade, semester, sentence) VALUES (?, ?, ?, ?)')->execute([(int)post('competency_id'), $grade, '*', post('sentence')]);
            audit_log('create', 'sentence_template', (int)$db->lastInsertId(), ['grade'=>$grade]);
        }
        elseif ($action === 'delete') { $tid = (int)post('template_id'); $db->prepare('DELETE FROM sentence_templates WHERE id = ?')->execute([$tid]); audit_log('delete', 'sentence_template', $tid); }
        else { $tid = (int)post('template_id'); $db->prepare('UPDATE sentence_templates SET sentence = ? WHERE id = ?')->execute([post('sentence'), $tid]); audit_log('update', 'sentence_template', $tid); }
        flash('Satzbausteine aktualisiert.'); redirect_to('/templates');
    }
    if ($r === '/letter-templates/save') {
        save_letter_template_action(); return;
    }
    if ($r === '/ratings/save') {
        save_ratings_action((int)($_GET['student_id'] ?? 0)); return;
    }
    if ($r === '/letters/generate') {
        $sid = (int)($_GET['student_id'] ?? 0); $sem = normalize_semester(post('semester'));
        if (in_array($sem, archived_semesters(), true)) { flash('Fuer archivierte Halbjahre koennen keine neuen Lernbriefe erzeugt werden.', 'error'); redirect_to('/ratings', ['student_id'=>$sid,'semester'=>$sem]); }
        try {
            $tpl = active_letter_template();
            $db->prepare('INSERT INTO letters (student_id, semester, content, created_at, template_name, body_font_family, body_font_size) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$sid, $sem, build_letter($sid, $sem), date('Y-m-d\TH:i:s'), $tpl['name'], $tpl['body_font_family'], (int)$tpl['body_font_size']]);
            audit_log('generate', 'letter', (int)$db->lastInsertId(), ['student_id'=>$sid,'semester'=>$sem,'template'=>$tpl['name'] ?? '']);
            flash('Lernbrief wurde generiert und gespeichert.');
        } catch (Throwable $e) { flash($e->getMessage(), 'error'); }
        redirect_to('/ratings', ['student_id'=>$sid,'semester'=>$sem]);
    }
    if ($r === '/letters/update') {
        $id = (int)($_GET['id'] ?? 0); $content = post('content_html');
        if ($content === '') flash('Lernbriefinhalt darf nicht leer sein.', 'error'); else { $db->prepare('UPDATE letters SET content = ? WHERE id = ?')->execute([$content,$id]); audit_log('update', 'letter', $id); flash('Lernbrief wurde gespeichert.'); }
        redirect_to('/letters/show', ['id'=>$id]);
    }
    if ($r === '/letters/delete') {
        $id = (int)($_GET['id'] ?? 0); $letter = one('SELECT student_id, semester FROM letters WHERE id = ?', [$id]);
        $db->prepare('DELETE FROM letters WHERE id = ?')->execute([$id]); audit_log('delete', 'letter', $id, $letter ?: []); flash('Lernbrief wurde geloescht.');
        if (post('next') === 'ratings' && $letter) redirect_to('/ratings', ['student_id'=>$letter['student_id'], 'semester'=>$letter['semester']]);
        redirect_to('/');
    }
    if ($r === '/data/archive-toggle') {
        $sem = post('semester'); $archived = archived_semesters();
        if (post('action') === 'archive') $archived[] = $sem; else $archived = array_values(array_diff($archived, [$sem]));
        set_archived_semesters($archived); audit_log(post('action') === 'archive' ? 'archive' : 'unarchive', 'semester', null, ['semester'=>$sem]); flash('Archivstatus gespeichert.'); redirect_to('/data');
    }
    if ($r === '/data/restore') {
        restore_backup_action(); return;
    }
}

function save_letter_template_action(): void
{
    $db = db(); $action = post('action'); $id = (int)post('template_id');
    if ($action === 'create') {
        try { $db->prepare('INSERT INTO letter_templates (name, header_html, footer_html, include_average_sentence, average_sentence_template, header_position, footer_position, body_font_family, body_font_size, include_export_signature, export_signature_template, is_active) VALUES (?, ?, ?, 1, ?, "top", "bottom", "Georgia", 16, 1, ?, 0)')->execute([post('new_template_name'), 'Lernbrief fuer {name}<br>Lerngruppe: {group_name}<br>Halbjahr: {semester}', '', get_setting('letter_average_sentence_template'), 'Datum: {date}<br><br>Unterschrift: ______________________________']); audit_log('create', 'letter_template', (int)$db->lastInsertId(), ['name'=>post('new_template_name')]); flash('Neue Lernbriefvorlage erstellt.'); } catch (PDOException $e) { flash($e->getCode() === '23000' ? 'Eine Vorlage mit diesem Namen existiert bereits.' : 'Vorlage konnte nicht erstellt werden: ' . $e->getMessage(), 'error'); }
        redirect_to('/letter-templates');
    }
    if ($action === 'activate') { $db->exec('UPDATE letter_templates SET is_active = 0'); $db->prepare('UPDATE letter_templates SET is_active = 1 WHERE id = ?')->execute([$id]); audit_log('activate', 'letter_template', $id); flash('Vorlage als aktiv gesetzt.'); redirect_to('/letter-templates', ['template_id'=>$id]); }
    if ($action === 'delete') {
        if ((int)$db->query('SELECT COUNT(*) FROM letter_templates')->fetchColumn() <= 1) { flash('Mindestens eine Lernbriefvorlage muss erhalten bleiben.', 'error'); redirect_to('/letter-templates', ['template_id'=>$id]); }
        $was = one('SELECT is_active FROM letter_templates WHERE id = ?', [$id]); $db->prepare('DELETE FROM letter_templates WHERE id = ?')->execute([$id]);
        if ($was && (int)$was['is_active'] === 1) $db->exec('UPDATE letter_templates SET is_active = 1 WHERE id = (SELECT id FROM letter_templates ORDER BY id ASC LIMIT 1)');
        audit_log('delete', 'letter_template', $id);
        flash('Vorlage geloescht.'); redirect_to('/letter-templates');
    }
    $name = post('name'); $header = post('header_html') ?: 'Lernbrief fuer {name}<br>Lerngruppe: {group_name}<br>Halbjahr: {semester}';
    if ($id <= 0 || !one('SELECT id FROM letter_templates WHERE id = ?', [$id])) {
        flash('Bitte eine gueltige Vorlage auswaehlen.', 'error');
        redirect_to('/letter-templates');
    }
    if ($name === '') {
        flash('Der Vorlagenname darf nicht leer sein.', 'error');
        redirect_to('/letter-templates', ['template_id' => $id]);
    }
    if (one('SELECT id FROM letter_templates WHERE name = ? AND id <> ?', [$name, $id])) {
        flash('Eine andere Vorlage mit diesem Namen existiert bereits.', 'error');
        redirect_to('/letter-templates', ['template_id' => $id]);
    }
    $avg = post('average_sentence_template') ?: get_setting('letter_average_sentence_template');
    $exportSignature = post('export_signature_template') ?: 'Datum: {date}<br><br>Unterschrift: ______________________________';
    try {
        $db->prepare('UPDATE letter_templates SET name=?, header_html=?, footer_html=?, include_average_sentence=?, average_sentence_template=?, header_position=?, footer_position=?, body_font_family=?, body_font_size=?, include_export_signature=?, export_signature_template=? WHERE id=?')
            ->execute([$name, $header, post('footer_html'), isset($_POST['include_average_sentence']) ? 1 : 0, $avg, in_array(post('header_position'), ['top','after_intro'], true) ? post('header_position') : 'top', in_array(post('footer_position'), ['bottom','after_header'], true) ? post('footer_position') : 'bottom', post('body_font_family', 'Georgia'), max(4, min(28, (int)post('body_font_size', '16'))), isset($_POST['include_export_signature']) ? 1 : 0, $exportSignature, $id]);
        audit_log('update', 'letter_template', $id, ['name'=>$name]);
        flash('Lernbriefvorlage gespeichert.');
    } catch (PDOException $e) { flash($e->getCode() === '23000' ? 'Eine Vorlage mit diesem Namen existiert bereits.' : 'Vorlage konnte nicht gespeichert werden: ' . $e->getMessage(), 'error'); }
    redirect_to('/letter-templates', ['template_id'=>$id]);
}

function save_ratings_action(int $studentId): void
{
    $sem = normalize_semester(post('semester'));
    if (in_array($sem, archived_semesters(), true)) { flash('Archivierte Halbjahre sind schreibgeschuetzt.', 'error'); redirect_to('/ratings', ['student_id'=>$studentId,'semester'=>$sem]); }
    if (post('action') === 'save_student_goal') {
        $text = post('semester_goal_text');
        if ($text !== '') db()->prepare('INSERT INTO student_semester_goals (student_id, semester, goal_text) VALUES (?, ?, ?) ON CONFLICT(student_id, semester) DO UPDATE SET goal_text = excluded.goal_text')->execute([$studentId,$sem,$text]);
        else db()->prepare('DELETE FROM student_semester_goals WHERE student_id = ? AND semester = ?')->execute([$studentId,$sem]);
        audit_log($text !== '' ? 'save_goal' : 'delete_goal', 'student', $studentId, ['semester'=>$sem]);
        flash('Halbjahresziel gespeichert.'); redirect_to('/ratings', ['student_id'=>$studentId,'semester'=>$sem]);
    }
    foreach (query_competencies() as $comp) {
        $grade = $_POST['grade_'.$comp['id']] ?? '';
        if ($grade === '') continue;
        $gradeValue = (int)$grade;
        if (!is_valid_grade($gradeValue)) {
            flash('Bitte nur Noten zwischen 1 und 5 speichern.', 'error');
            redirect_to('/ratings', ['student_id'=>$studentId,'semester'=>$sem]);
        }
        db()->prepare('INSERT INTO ratings (student_id, competency_id, semester, grade, note) VALUES (?, ?, ?, ?, ?) ON CONFLICT(student_id, competency_id, semester) DO UPDATE SET grade = excluded.grade, note = excluded.note')
            ->execute([$studentId, $comp['id'], $sem, $gradeValue, trim((string)($_POST['note_'.$comp['id']] ?? ''))]);
    }
    audit_log('save_ratings', 'student', $studentId, ['semester'=>$sem]);
    flash('Bewertungen gespeichert.'); redirect_to('/ratings', ['student_id'=>$studentId,'semester'=>$sem]);
}

function restore_backup_action(): void
{
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        flash('Bitte eine Backup-Datei auswaehlen.', 'error'); redirect_to('/data');
    }
    $tmp = __DIR__ . '/../lernbrief_hub_restore_' . time() . '.tmp';
    move_uploaded_file($_FILES['backup_file']['tmp_name'], $tmp);
    try {
        $test = new PDO('sqlite:' . $tmp);
        $tables = array_column($test->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['groups','students','competencies','ratings','sentence_templates','letters','app_settings'] as $required) {
            if (!in_array($required, $tables, true)) throw new RuntimeException('missing');
        }
        if (file_exists(DB_PATH)) copy(DB_PATH, __DIR__ . '/../lernbrief_hub_pre_restore_' . date('Ymd_His') . '.bak');
        rename($tmp, DB_PATH); flash('Backup wurde erfolgreich wiederhergestellt.');
    } catch (Throwable) {
        if (file_exists($tmp)) unlink($tmp);
        flash('Wiederherstellung fehlgeschlagen. Bitte Backup-Datei pruefen.', 'error');
    }
    redirect_to('/data');
}

function export_pdf(int $id): never
{
    try {
        $letter = one('SELECT l.*, s.full_name FROM letters l JOIN students s ON s.id = l.student_id WHERE l.id = ?', [$id]);
        if (!$letter) { flash('Lernbrief nicht gefunden.', 'error'); redirect_to('/'); }
        if (class_exists('\\Mpdf\\Mpdf') && extension_loaded('mbstring')) {
            try {
                export_pdf_mpdf($letter);
            } catch (Throwable $e) {
                export_debug_log('mPDF export failed: ' . $e->getMessage());
            }
        }
        export_pdf_builtin($letter);
    } catch (Throwable $e) {
        export_debug_log('PDF export failed: ' . $e->getMessage());
        export_failure_response('PDF', $e->getMessage());
    }
}

function export_pdf_builtin(array $letter): never
{
    $stream = pdf_stream_from_blocks(html_blocks_from_content((string)$letter['content'] . export_signature_html($letter)));
    $objects = [
        "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj",
        "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj",
        "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R /F4 7 0 R >> >> /Contents 8 0 R >> endobj",
        "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >> endobj",
        "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >> endobj",
        "6 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >> endobj",
        "7 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-BoldOblique /Encoding /WinAnsiEncoding >> endobj",
        "8 0 obj << /Length " . strlen($stream) . " >> stream\n{$stream}\nendstream endobj",
    ];
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $obj) { $offsets[] = strlen($pdf); $pdf .= $obj . "\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 9\n0000000000 65535 f \n";
    for ($i=1; $i<=8; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    $pdf .= "trailer << /Size 9 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    download_bytes(filename($letter['full_name'], $letter['semester'], 'pdf'), 'application/pdf', $pdf);
}

function export_debug_log(string $message): void
{
    $dir = __DIR__ . '/../tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($dir . '/export-debug.log', $line, FILE_APPEND);
    error_log($message);
}

function export_failure_response(string $type, string $message): never
{
    http_response_code(500);
    $checks = export_system_checks();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="de"><head><meta charset="utf-8"><title><?= h($type) ?>-Export fehlgeschlagen</title><link rel="stylesheet" href="static/style.css"></head><body><main class="container"><section class="panel"><h1><?= h($type) ?>-Export fehlgeschlagen</h1><p>Der Export konnte nicht abgeschlossen werden. Die technische Meldung wurde in <code>../tmp/export-debug.log</code> protokolliert.</p><p><strong>Fehler:</strong> <?= h($message) ?></p><p><a class="button-link" href="<?= route('/data') ?>">Systemcheck oeffnen</a> <a class="button-link" href="<?= route('/') ?>">Zum Dashboard</a></p></section><section class="panel"><h2>Export-Systemcheck</h2><?= table_rows(['Pruefung','Status','Details'], $checks, fn($c) => [h($c['label']), '<span class="status-pill '.($c['ok']?'status-active':'status-archived').'">'.($c['ok']?'OK':'Fehlt').'</span>', h($c['detail'])], 'Keine Exportpruefungen vorhanden.') ?></section></main></body></html><?php
    exit;
}

function export_word(int $id): never
{
    try {
        $letter = one('SELECT l.*, s.full_name FROM letters l JOIN students s ON s.id = l.student_id WHERE l.id = ?', [$id]);
        if (!$letter) { flash('Lernbrief nicht gefunden.', 'error'); redirect_to('/'); }
        // Legacy Word HTML is more robust in older Word versions and LibreOffice.
        download_bytes(
            filename($letter['full_name'], $letter['semester'], 'doc'),
            'application/msword',
            export_word_compatible_html((string)$letter['content'], $letter)
        );
    } catch (Throwable $e) {
        export_debug_log('Word (.doc) export failed: ' . $e->getMessage());
        export_failure_response('Word', $e->getMessage());
    }
}

function export_pdf_mpdf(array $letter): never
{
    $tempDir = __DIR__ . '/../tmp/mpdf';
    if (!is_dir($tempDir) && !mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
        throw new RuntimeException('mPDF temp directory could not be created: ' . $tempDir);
    }

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 20,
        'margin_right' => 20,
        'margin_top' => 20,
        'margin_bottom' => 20,
        'default_font' => 'dejavusans',
        'tempDir' => $tempDir,
    ]);
    $html = export_document_html((string)$letter['content'], $letter);
    $mpdf->WriteHTML($html);
    download_bytes(
        filename($letter['full_name'], $letter['semester'], 'pdf'),
        'application/pdf',
        $mpdf->Output('', 'S')
    );
}

function export_document_html(string $content, array $letter = []): string
{
    $body = normalize_export_html($content);
    $signature = export_signature_html($letter);
    return '<!doctype html><html><head><meta charset="utf-8"><style>
        @page { margin: 20mm 18mm 22mm 18mm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; line-height: 1.35; }
        p { margin: 0 0 4pt 0; }
        h1, h2, h3 { margin: 0 0 12pt 0; font-weight: bold; }
        ul, ol { margin: 0 0 6pt 22pt; }
        .letter-header { margin-bottom: 10pt; }
        .letter-footer { margin-top: 10pt; }
        .export-meta { margin-top: 22pt; padding-top: 12pt; border-top: 1px solid #777; }
        .signature-line { margin-top: 26pt; }
    </style></head><body>' . $body . $signature . '</body></html>';
}

function export_word_compatible_html(string $content, array $letter = []): string
{
    $body = normalize_export_html($content);
    $signature = export_signature_html($letter);
    $font = trim((string)($letter['body_font_family'] ?? ''));
    $font = preg_replace('/[^a-zA-Z0-9,\s"\-]/', '', $font) ?? $font;
    $font = $font !== '' ? $font : 'Times New Roman';
    $sizePt = max(9, min(16, (int)($letter['body_font_size'] ?? 12)));

    return '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">'
        . '<head>'
        . '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
        . '<meta name="ProgId" content="Word.Document">'
        . '<meta name="Generator" content="Lernbrief-Hub">'
        . '<style>'
        . 'body, p, li, td { font-family: ' . h($font) . ', serif; font-size: ' . $sizePt . 'pt; line-height: 1.3; }'
        . 'h1, h2, h3 { margin: 0 0 12pt 0; font-weight: bold; }'
        . 'p { margin: 0 0 4pt 0; }'
        . 'ul, ol { margin-top: 0; margin-bottom: 6pt; }'
        . '.letter-header { margin-bottom: 10pt; }'
        . '.letter-footer { margin-top: 10pt; }'
        . '.export-meta { margin-top: 20pt; padding-top: 10pt; border-top: 1px solid #777; }'
        . '</style>'
        . '</head>'
        . '<body>' . $body . $signature . '</body></html>';
}

function normalize_export_html(string $html): string
{
    $html = preg_replace('/<br\s*\/?>/i', '</p><p>', $html) ?? $html;
    $html = preg_replace('/<p>\s*<\/p>/i', '', $html) ?? $html;
    $html = preg_replace('/<\/p>\s*<p>/i', '</p><p>', $html) ?? $html;
    return $html;
}

function pdf_stream_from_blocks(array $blocks): string
{
    $stream = '';
    $x = 50;
    $y = 790;
    $lineHeight = 15;
    foreach ($blocks as $block) {
        $line = [];
        $lineLen = 0;
        foreach ($block['runs'] as $run) {
            $words = preg_split('/(\s+)/u', (string)$run['text'], -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                if ($word === "\n") {
                    pdf_flush_line($stream, $line, $x, $y);
                    $line = [];
                    $lineLen = 0;
                    $y -= $lineHeight;
                    continue;
                }
                $len = strlen($word);
                if ($lineLen + $len > 88 && $line) {
                    pdf_flush_line($stream, $line, $x, $y);
                    $line = [];
                    $lineLen = 0;
                    $y -= $lineHeight;
                }
                $line[] = [$word, $run];
                $lineLen += $len;
            }
        }
        pdf_flush_line($stream, $line, $x, $y);
        $y -= 24;
        if ($y < 60) {
            break;
        }
    }
    return $stream;
}

function pdf_flush_line(string &$stream, array $line, int $x, int $y): void
{
    if (!$line) {
        return;
    }
    $stream .= "BT {$x} {$y} Td ";
    foreach ($line as [$text, $run]) {
        $font = !empty($run['bold']) && !empty($run['italic']) ? 'F4' : (!empty($run['bold']) ? 'F2' : (!empty($run['italic']) ? 'F3' : 'F1'));
        $size = max(8, min(24, (int)(($run['size'] ?? 22) / 2)));
        $stream .= "/{$font} {$size} Tf (" . pdf_escape((string)$text) . ') Tj ';
    }
    $stream .= "ET\n";
}

function pdf_escape(string $text): string
{
    $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
    $text = $converted === false ? $text : $converted;
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function filename(string $name, string $semester, string $ext): string
{
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', "Lernbrief_{$name}_{$semester}") ?: 'Lernbrief';
    return trim($base, '_') . '.' . $ext;
}

function download_bytes(string $name, string $type, string $bytes): never
{
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

$r = (string)($_GET['r'] ?? '/');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($r === '/data/restore') {
        restore_backup_action();
    }
    db();
    handle_actions($r);
}
db();
if ($r === '/data/backup') {
    if (!file_exists(DB_PATH)) { flash('Keine Datenbank fuer Backup gefunden.', 'error'); redirect_to('/data'); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="lernbrief_hub_backup_' . date('Ymd_His') . '.db"');
    readfile(DB_PATH);
    exit;
}
if ($r === '/letters/pdf') export_pdf((int)($_GET['id'] ?? 0));
if ($r === '/letters/word') export_word((int)($_GET['id'] ?? 0));
if ($r === '/letters/docx') export_word((int)($_GET['id'] ?? 0)); // Legacy route alias

match ($r) {
    '/' => page_index(),
    '/overview' => page_overview(),
    '/groups/new' => page_new_group(),
    '/groups/show' => page_group_show(),
    '/competencies' => page_competencies(),
    '/templates' => page_templates(),
    '/letter-templates' => page_letter_templates(),
    '/letter-templates/preview' => page_letter_template_preview(),
    '/ratings' => page_ratings(),
    '/letters/show' => page_letter_show(),
    '/data' => page_data(),
    default => page_index(),
};
