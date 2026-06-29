<?php
require_once __DIR__ . '/config.php';
require_login();

// ── OOXML namespace constants ──────────────────────────────────────────────
define('NS_W', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
define('NS_A', 'http://schemas.openxmlformats.org/drawingml/2006/main');
define('NS_R', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
define('NS_PKG', 'http://schemas.openxmlformats.org/package/2006/relationships');

$user     = current_user();
$type     = (string)($_GET['type'] ?? '');
$id       = (int)($_GET['id']   ?? 0);
$inline   = ($_GET['view'] ?? '0') === '1'; // ?view=1 = open in browser, default = download

$resource = null;
$courseId = null;
$filePath = null;
$fileName = null;
$fileType = null;

if ($type === 'document') {
    $stmt = db()->prepare('
        SELECT d.*, c.id AS course_id
        FROM documents d
        JOIN topics t  ON t.id = d.topic_id
        JOIN courses c ON c.id = t.course_id
        WHERE d.id = ?
    ');
    $stmt->execute([$id]);
    $resource = $stmt->fetch();
    if ($resource) {
        $courseId = (int)$resource['course_id'];
        $filePath = $resource['file_path'];
        $fileName = $resource['title'];
        $fileType = $resource['file_type'];
    }
} elseif ($type === 'section') {
    $stmt = db()->prepare('
        SELECT sr.*, c.id AS course_id
        FROM section_resources sr
        JOIN course_sections cs ON cs.id = sr.section_id
        JOIN courses c          ON c.id  = cs.course_id
        WHERE sr.id = ?
    ');
    $stmt->execute([$id]);
    $resource = $stmt->fetch();
    if ($resource) {
        $courseId = (int)$resource['course_id'];
        $filePath = $resource['file_path'];
        $fileName = $resource['title'];
        $fileType = $resource['file_type'];
    }
}

if (!$resource || !$filePath || !$courseId || !enrolled_or_staff_access($courseId, $user)) {
    http_response_code(403);
    error_log("[LCS] download.php Forbidden: type=$type id=$id user={$user['id']} course=$courseId "
        . 'resource=' . ($resource ? 'ok' : 'none')
        . ' filePath=' . ($filePath ? 'ok' : 'none')
        . ' courseId=' . ($courseId ?: 'none')
        . ' access=' . (enrolled_or_staff_access($courseId, $user) ? 'ok' : 'denied'));
    exit('Forbidden');
}

$abs = PRIVATE_UPLOAD_ROOT . '/' . ltrim($filePath, '/');
if (!is_file($abs)) {
    http_response_code(404);
    exit('File not found');
}

// ── Determine MIME type for inline serving ────────────────────────────────────
$ext      = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName ?: basename($abs)) . '.' . $ext;

$inlineMimes = [
    'pdf'  => 'application/pdf',
    'txt'  => 'text/plain',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

// Inline-viewable types open in browser; everything else shows a preview page
$canInline = isset($inlineMimes[$ext]);
$mime      = $inlineMimes[$ext] ?? 'application/octet-stream';
$fileSize  = filesize($abs);

// ── For non-inline types with ?view=1 ────────────────────────────────────────
// DOCX/DOC: extract text via ZipArchive and render as readable HTML page.
// Other types (PPTX, ZIP etc.): show a download-only preview page.
if ($inline && !$canInline) {

    $sizeLabel = $fileSize > 1048576
        ? round($fileSize / 1048576, 1) . ' MB'
        : round($fileSize / 1024) . ' KB';

    // ── DOCX viewer — full OOXML formatting (bold, headings, tables, images) ──
    if (in_array($ext, ['docx', 'doc']) && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $docHtml  = '';
        $hasError = false;

        if ($zip->open($abs) === true) {
            $xml        = $zip->getFromName('word/document.xml');
            $relsXml    = $zip->getFromName('word/_rels/document.xml.rels');
            $numXml     = $zip->getFromName('word/numbering.xml');

            // ── Extract images from relationships ────────────────────────────────
            $images = [];
            if ($relsXml) {
                $relsDoc = new DOMDocument();
                @$relsDoc->loadXML($relsXml);
                foreach ($relsDoc->getElementsByTagNameNS(NS_PKG, 'Relationship') as $rel) {
                    $type   = $rel->getAttribute('Type');
                    $target = $rel->getAttribute('Target');
                    $relId  = $rel->getAttribute('Id');
                    if (str_contains($type, 'image')) {
                        $imgPath = 'word/' . ltrim($target, '/');
                        $imgData = $zip->getFromName($imgPath);
                        if ($imgData) {
                            $ext2  = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                            $mime2 = match ($ext2) {
                                'png'  => 'image/png',
                                'gif'  => 'image/gif',
                                'webp' => 'image/webp',
                                default => 'image/jpeg',
                            };
                            $images[$relId] = 'data:' . $mime2 . ';base64,' . base64_encode($imgData);
                        }
                    }
                }
            }

            // ── Parse numbering (lists) ───────────────────────────────────────
            $numFmts = [];
            if ($numXml) {
                $numDoc = new DOMDocument();
                @$numDoc->loadXML($numXml);
                foreach ($numDoc->getElementsByTagNameNS(NS_W, 'abstractNum') as $aNum) {
                    $aId = $aNum->getAttributeNS(NS_W, 'abstractNumId');
                    if (!$aId) continue;
                    foreach ($aNum->childNodes as $lvl) {
                        if ($lvl->localName !== 'lvl') continue;
                        $ilvl  = $lvl->getAttributeNS(NS_W, 'ilvl');
                        $fmtEl = $lvl->getElementsByTagNameNS(NS_W, 'numFmt')->item(0);
                        $fmt   = $fmtEl ? ($fmtEl->getAttributeNS(NS_W, 'val') ?: 'bullet') : 'bullet';
                        $numFmts["a{$aId}_{$ilvl}"] = $fmt;
                    }
                }
                $numMap = [];
                foreach ($numDoc->getElementsByTagNameNS(NS_W, 'num') as $num) {
                    $numId   = $num->getAttributeNS(NS_W, 'numId');
                    $absRef  = $num->getElementsByTagNameNS(NS_W, 'abstractNumId')->item(0);
                    $absId   = $absRef ? $absRef->getAttributeNS(NS_W, 'val') : '';
                    $numMap[$numId] = $absId;
                }
            }

            // ── Main XML parse ────────────────────────────────────────────────
            if ($xml) {
                $dom = new DOMDocument();
                @$dom->loadXML($xml);
                $body = $dom->getElementsByTagNameNS(NS_W, 'body')->item(0);

                if ($body) {
                    // Helper: render images from a drawing/pict element
                    $drawingImages = function (DOMElement $node) use ($images): string {
                        $out = '';
                        // Method 1: DOM search for a:blip descendants
                        foreach ($node->ownerDocument->getElementsByTagNameNS(NS_A, 'blip') as $blip) {
                            if (!$blip->parentNode) continue;
                            $p = $blip->parentNode;
                            while ($p && $p !== $node) $p = $p->parentNode;
                            if ($p !== $node) continue;
                            $embed = $blip->getAttributeNS(NS_R, 'embed') ?: $blip->getAttributeNS(NS_R, 'link');
                            if ($embed && isset($images[$embed])) {
                                $out .= '<img src="' . $images[$embed] . '" style="max-width:100%;height:auto;display:block;margin:8px 0;" alt="Document image">';
                            }
                        }
                        // Method 2: regex fallback on the node's raw XML
                        if (!$out) {
                            $xml = $node->ownerDocument->saveXML($node);
                            if ($xml) {
                                preg_match_all('/r:embed="([^"]+)"/', $xml, $m);
                                foreach ($m[1] as $rid) {
                                    if (isset($images[$rid])) {
                                        $out .= '<img src="' . $images[$rid] . '" style="max-width:100%;height:auto;display:block;margin:8px 0;" alt="Document image">';
                                    }
                                }
                            }
                        }
                        // Method 3: VML imagedata (legacy w:pict format)
                        if (!$out) {
                            foreach ($node->getElementsByTagNameNS('urn:schemas-microsoft-com:vml', 'imagedata') as $img) {
                                $rid = $img->getAttributeNS(NS_R, 'id');
                                if ($rid && isset($images[$rid])) {
                                    $out .= '<img src="' . $images[$rid] . '" style="max-width:100%;height:auto;display:block;margin:8px 0;" alt="Document image">';
                                }
                            }
                        }
                        return $out;
                    };

                    // Helper: get all text from a run element
                    $runText = function (DOMElement $run) use ($drawingImages): string {
                        $text = '';
                        $bold   = false;
                        $italic = false;
                        $under  = false;
                        $rPr = $run->getElementsByTagNameNS(NS_W, 'rPr')->item(0);
                        if ($rPr) {
                            $bold   = $rPr->getElementsByTagNameNS(NS_W, 'b')->length > 0;
                            $italic = $rPr->getElementsByTagNameNS(NS_W, 'i')->length > 0;
                            $under  = $rPr->getElementsByTagNameNS(NS_W, 'u')->length > 0;
                        }
                        foreach ($run->childNodes as $child) {
                            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                            $ln = $child->localName;
                            if ($ln === 't') {
                                $text .= htmlspecialchars($child->nodeValue, ENT_QUOTES, 'UTF-8');
                            } elseif ($ln === 'br') {
                                $text .= '<br>';
                            } elseif ($ln === 'tab') {
                                $text .= '&nbsp;&nbsp;&nbsp;&nbsp;';
                            } elseif ($ln === 'drawing' || $ln === 'pict') {
                                $text .= $drawingImages($child);
                            }
                        }
                        if ($under  && $text) $text = "<u>$text</u>";
                        if ($italic && $text) $text = "<em>$text</em>";
                        if ($bold   && $text) $text = "<strong>$text</strong>";
                        return $text;
                    };

                    // Helper: get all inline content from a paragraph
                    $paraContent = function (DOMElement $para) use ($runText, $drawingImages): string {
                        $html = '';
                        foreach ($para->childNodes as $child) {
                            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                            $ln = $child->localName;
                            if ($ln === 'r') {
                                $html .= $runText($child);
                            } elseif ($ln === 'hyperlink') {
                                $linkText = '';
                                foreach ($child->childNodes as $rc) {
                                    if ($rc->localName === 'r') $linkText .= $runText($rc);
                                }
                                $html .= $linkText;
                            } elseif ($ln === 'ins') {
                                foreach ($child->childNodes as $rc) {
                                    if ($rc->localName === 'r') $html .= $runText($rc);
                                }
                            } elseif ($ln === 'drawing' || $ln === 'pict') {
                                $html .= $drawingImages($child);
                            }
                        }
                        // Also find anchored/floating drawings/picts not inside a run
                        foreach ($para->getElementsByTagNameNS(NS_W, 'drawing') as $d) {
                            $parent = $d->parentNode;
                            if ($parent && $parent->localName === 'r') continue;
                            $html .= $drawingImages($d);
                        }
                        foreach ($para->getElementsByTagNameNS(NS_W, 'pict') as $d) {
                            $parent = $d->parentNode;
                            if ($parent && $parent->localName === 'r') continue;
                            $html .= $drawingImages($d);
                        }
                        return $html;
                    };

                    $docHtml = '';
                    $openList = null;

                    foreach ($body->childNodes as $block) {
                        if ($block->nodeType !== XML_ELEMENT_NODE) continue;
                        $ln = $block->localName;

                        if ($ln === 'p') {
                            $pPr    = $block->getElementsByTagNameNS(NS_W, 'pPr')->item(0);
                            $pStyle = '';
                            $numId  = null;
                            $ilvl   = 0;
                            $jc     = '';
                            if ($pPr) {
                                $styleEl = $pPr->getElementsByTagNameNS(NS_W, 'pStyle')->item(0);
                                if ($styleEl) $pStyle = strtolower($styleEl->getAttributeNS(NS_W, 'val'));
                                $numPr = $pPr->getElementsByTagNameNS(NS_W, 'numPr')->item(0);
                                if ($numPr) {
                                    $numIdEl = $numPr->getElementsByTagNameNS(NS_W, 'numId')->item(0);
                                    $ilvlEl  = $numPr->getElementsByTagNameNS(NS_W, 'ilvl')->item(0);
                                    $numId   = $numIdEl ? $numIdEl->getAttributeNS(NS_W, 'val') : null;
                                    $ilvl    = $ilvlEl  ? (int)($ilvlEl->getAttributeNS(NS_W, 'val')) : 0;
                                }
                                $jcEl = $pPr->getElementsByTagNameNS(NS_W, 'jc')->item(0);
                                if ($jcEl) $jc = $jcEl->getAttributeNS(NS_W, 'val');
                            }

                            $text = $paraContent($block);
                            $align = match ($jc) {
                                'center' => ' style="text-align:center"',
                                'right' => ' style="text-align:right"',
                                default => ''
                            };

                            // Is this a list item?
                            if ($numId && $numId !== '0') {
                                $absId = $numMap[$numId] ?? '';
                                $fmt   = $numFmts["a{$absId}_{$ilvl}"] ?? 'bullet';
                                $isList = true;
                                $listType = in_array($fmt, ['decimal', 'lowerLetter', 'upperLetter', 'lowerRoman', 'upperRoman']) ? 'ol' : 'ul';
                                if ($openList !== $listType) {
                                    if ($openList) $docHtml .= "</$openList>\n";
                                    $docHtml .= "<$listType>\n";
                                    $openList = $listType;
                                }
                                $indent = str_repeat('<ul style="margin:0">', $ilvl);
                                $indentClose = str_repeat('</ul>', $ilvl);
                                $docHtml .= ($ilvl > 0 ? $indent : '') . "<li>$text</li>\n" . ($ilvl > 0 ? $indentClose : '');
                            } else {
                                // Close any open list
                                if ($openList) {
                                    $docHtml .= "</$openList>\n";
                                    $openList = null;
                                }

                                // Heading or paragraph
                                if (preg_match('/^heading(\d)$/', $pStyle, $hm)) {
                                    $level = min((int)$hm[1], 6);
                                    $docHtml .= "<h$level$align>$text</h$level>\n";
                                } elseif ($pStyle === 'title') {
                                    $docHtml .= "<h1 class=\"doc-title\"$align>$text</h1>\n";
                                } elseif ($pStyle === 'subtitle') {
                                    $docHtml .= "<p class=\"doc-subtitle\"$align>$text</p>\n";
                                } elseif (trim(strip_tags($text)) === '') {
                                    $docHtml .= "<p>&nbsp;</p>\n";
                                } else {
                                    $docHtml .= "<p$align>$text</p>\n";
                                }
                            }
                        } elseif ($ln === 'tbl') {
                            // Close list
                            if ($openList) {
                                $docHtml .= "</$openList>\n";
                                $openList = null;
                            }

                            $docHtml .= '<div class="doc-table-wrap"><table class="doc-table">' . "\n";
                            foreach ($block->childNodes as $row) {
                                if ($row->nodeType !== XML_ELEMENT_NODE || $row->localName !== 'tr') continue;
                                $docHtml .= '<tr>';
                                foreach ($row->childNodes as $cell) {
                                    if ($cell->nodeType !== XML_ELEMENT_NODE || !in_array($cell->localName, ['tc', 'th'])) continue;
                                    $cellHtml = '';
                                    foreach ($cell->childNodes as $cp) {
                                        if ($cp->localName === 'p') $cellHtml .= $paraContent($cp) . ' ';
                                    }
                                    $docHtml .= '<td>' . trim($cellHtml) . '</td>';
                                }
                                $docHtml .= "</tr>\n";
                            }
                            $docHtml .= "</table></div>\n";
                        }
                    }
                    if ($openList) $docHtml .= "</$openList>\n";
                }
            }
            $zip->close();
        } else {
            $hasError = true;
        }
?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?php echo h($fileName); ?> — KWASU LCS</title>
            <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
            <link rel="stylesheet" href="assets/style.css">
            <script src="assets/theme.js" defer></script>
            <style>
                .doc-body {
                    max-width: 820px;
                    margin: 0 auto;
                    background: #fff;
                    border-radius: 18px;
                    padding: 48px 56px;
                    box-shadow: 0 4px 24px rgba(15, 23, 42, .08);
                    font-family: 'Calibri', 'Segoe UI', Arial, sans-serif;
                    font-size: 15px;
                    line-height: 1.8;
                    color: #1a1a1a;
                }

                .doc-body h1 {
                    font-size: 2em;
                    margin: .4em 0 .6em;
                }

                .doc-body h2 {
                    font-size: 1.5em;
                    margin: 1em 0 .4em;
                    border-bottom: 2px solid #e2e8f0;
                    padding-bottom: .2em;
                }

                .doc-body h3 {
                    font-size: 1.2em;
                    margin: .8em 0 .3em;
                }

                .doc-body h4,
                .doc-body h5,
                .doc-body h6 {
                    font-size: 1em;
                    margin: .6em 0 .2em;
                    font-weight: 700;
                }

                .doc-body p {
                    margin: 0 0 .8em;
                }

                .doc-body p:has(> strong:only-child) {
                    font-weight: 700;
                }

                .doc-body ul,
                .doc-body ol {
                    margin: .4em 0 .8em 1.6em;
                    padding: 0;
                }

                .doc-body li {
                    margin-bottom: .3em;
                }

                .doc-body strong {
                    font-weight: 700;
                }

                .doc-body em {
                    font-style: italic;
                }

                .doc-body u {
                    text-decoration: underline;
                }

                .doc-title {
                    font-size: 2.2em;
                    font-weight: 900;
                    text-align: center;
                    margin-bottom: .2em;
                }

                .doc-subtitle {
                    font-size: 1.1em;
                    color: #64748b;
                    text-align: center;
                    margin-bottom: 1.6em;
                }

                .doc-table-wrap {
                    overflow-x: auto;
                    margin: 1em 0;
                }

                .doc-table {
                    border-collapse: collapse;
                    width: 100%;
                    font-size: 14px;
                }

                .doc-table td,
                .doc-table th {
                    border: 1px solid #cbd5e1;
                    padding: 8px 12px;
                    text-align: left;
                    vertical-align: top;
                }

                .doc-table tr:nth-child(even) td {
                    background: #f8fafc;
                }

                .doc-table tr:first-child td {
                    background: #f1f5f9;
                    font-weight: 700;
                }

                @media(max-width:600px) {
                    .doc-body {
                        padding: 24px 18px;
                    }
                }
            </style>
        </head>

        <body class="app-body">
            <header class="topbar">
                <div>
                    <div class="eyebrow">Document Viewer</div>
                    <h1><?php echo h($fileName); ?></h1>
                    <p class="muted">Word Document · <?php echo $sizeLabel; ?></p>
                </div>
                <div class="topbar-actions">
                    <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
                    <a class="btn secondary" href="download.php?type=<?php echo h($type); ?>&id=<?php echo $id; ?>" target="_blank">⬇ Download</a>
                    <a class="btn secondary" href="course.php?id=<?php echo (int)$courseId; ?>">← Back to Course</a>
                </div>
            </header>
            <main class="page">
                <?php if ($hasError): ?>
                    <div class="panel" style="max-width:500px;margin:0 auto;padding:48px 32px;text-align:center;">
                        <div style="font-size:64px;margin-bottom:16px;">📝</div>
                        <h2>Could not open document</h2>
                        <p class="muted">This file may be password-protected or corrupted.</p>
                        <a class="btn primary" href="download.php?type=<?php echo h($type); ?>&id=<?php echo $id; ?>" target="_blank" style="margin-top:16px;">⬇ Download Instead</a>
                    </div>
                <?php elseif ($docHtml): ?>
                    <div class="doc-body"><?php echo $docHtml; ?></div>
                <?php else: ?>
                    <div class="panel" style="max-width:500px;margin:0 auto;padding:48px 32px;text-align:center;">
                        <div style="font-size:64px;margin-bottom:16px;">📄</div>
                        <h2>Empty document</h2>
                        <p class="muted">This document appears to have no content.</p>
                        <a class="btn primary" href="download.php?type=<?php echo h($type); ?>&id=<?php echo $id; ?>" target="_blank" style="margin-top:16px;">⬇ Download Instead</a>
                    </div>
                <?php endif; ?>
            </main>
        </body>

        </html>
    <?php
        exit();
    }

    // ── All other non-viewable types (PPTX, ZIP etc.) ─────────────────────────
    $iconMap = ['ppt' => '📊', 'pptx' => '📊', 'zip' => '🗜️', 'rar' => '🗜️', 'xls' => '📈', 'xlsx' => '📈'];
    $icon    = $iconMap[$ext] ?? '📁';
    $typeLabels = [
        'pptx' => 'PowerPoint Presentation',
        'ppt' => 'PowerPoint Presentation',
        'xlsx' => 'Excel Spreadsheet',
        'xls' => 'Excel Spreadsheet',
        'zip' => 'ZIP Archive',
        'rar' => 'RAR Archive'
    ];
    $typeLabel = $typeLabels[$ext] ?? strtoupper($ext) . ' File';
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title><?php echo h($fileName); ?> — KWASU LCS</title>
        <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
        <link rel="stylesheet" href="assets/style.css">
        <script src="assets/theme.js" defer></script>
    </head>

    <body class="app-body">
        <header class="topbar">
            <div>
                <div class="eyebrow">Document</div>
                <h1><?php echo h($fileName); ?></h1>
                <p class="muted"><?php echo $typeLabel; ?> · <?php echo $sizeLabel; ?></p>
            </div>
            <div class="topbar-actions">
                <button class="theme-btn" onclick="toggleTheme()" title="Dark mode">🌙</button>
                <a class="btn secondary" href="course.php?id=<?php echo (int)$courseId; ?>">← Back to Course</a>
            </div>
        </header>
        <main class="page">
            <div class="panel" style="max-width:560px;margin:0 auto;padding:48px 32px;text-align:center;">
                <div style="font-size:72px;margin-bottom:16px;line-height:1;"><?php echo $icon; ?></div>
                <h2 style="margin:0 0 6px;"><?php echo h($fileName); ?></h2>
                <p class="muted" style="margin:0 0 24px;"><?php echo $typeLabel; ?> · <?php echo $sizeLabel; ?></p>
                <p class="muted" style="margin:0 0 28px;font-size:14px;">
                    This file type cannot be previewed in the browser.<br>
                    Download it to open with the appropriate application.
                </p>
                <a class="btn primary"
                    href="download.php?type=<?php echo h($type); ?>&id=<?php echo $id; ?>"
                    target="_blank"
                    style="font-size:16px;padding:14px 32px;">
                    ⬇ Download <?php echo strtoupper($ext); ?>
                </a>
            </div>
        </main>
    </body>

    </html>
    <?php
    exit();
}

// ── Serve the file ────────────────────────────────────────────────────────────
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

// ── Stream mode: serves raw PDF bytes for PDF.js to fetch ───────────────────
// Called internally by the PDF.js viewer page — same auth check applies.
if (isset($_GET['stream']) && $_GET['stream'] === '1' && $ext === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, max-age=3600');
    header('Accept-Ranges: bytes');
    readfile($abs);
    exit();
}

if ($inline && $canInline) {
    // ── PDF: serve via PDF.js viewer for best rendering of all PDF types ─────
    if ($ext === 'pdf') {
        $sizeLabel = $fileSize > 1048576
            ? round($fileSize / 1048576, 1) . ' MB'
            : round($fileSize / 1024) . ' KB';
        // The PDF is streamed via ?stream=1 — no base64 encoding needed
        $streamUrl = "download.php?type=" . urlencode($type) . "&id={$id}&stream=1";
    ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($fileName); ?> — KWASU LCS</title>
            <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
            <link rel="stylesheet" href="assets/style.css">
            <script src="assets/theme.js" defer></script>
            <style>
                body {
                    margin: 0;
                    display: flex;
                    flex-direction: column;
                    height: 100vh;
                }

                .pdf-topbar {
                    background: #0f172a;
                    color: #fff;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 10px 18px;
                    gap: 14px;
                    flex-shrink: 0;
                }

                .pdf-topbar h2 {
                    margin: 0;
                    font-size: 15px;
                    font-weight: 700;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }

                .pdf-topbar p {
                    margin: 0;
                    font-size: 12px;
                    opacity: .6;
                }

                .pdf-topbar-btns {
                    display: flex;
                    gap: 8px;
                    flex-shrink: 0;
                }

                .pdf-btn {
                    padding: 7px 14px;
                    border-radius: 8px;
                    font-size: 13px;
                    font-weight: 700;
                    text-decoration: none;
                    border: none;
                    cursor: pointer;
                    font-family: inherit;
                }

                .pdf-btn.dl {
                    background: #07a701;
                    color: #fff;
                }

                .pdf-btn.bk {
                    background: rgba(255, 255, 255, .12);
                    color: #fff;
                }

                #pdf-canvas-wrap {
                    flex: 1;
                    overflow-y: auto;
                    background: #525659;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    padding: 16px;
                    gap: 12px;
                }

                canvas {
                    box-shadow: 0 4px 24px rgba(0, 0, 0, .4);
                    display: block;
                    max-width: 100%;
                }

                #pdf-controls {
                    position: fixed;
                    bottom: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    background: rgba(15, 23, 42, .92);
                    color: #fff;
                    border-radius: 30px;
                    padding: 8px 18px;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    font-size: 14px;
                    backdrop-filter: blur(8px);
                    box-shadow: 0 4px 20px rgba(0, 0, 0, .3);
                }

                #pdf-controls button {
                    background: rgba(255, 255, 255, .15);
                    border: none;
                    color: #fff;
                    width: 32px;
                    height: 32px;
                    border-radius: 50%;
                    cursor: pointer;
                    font-size: 16px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: background .15s;
                }

                #pdf-controls button:hover {
                    background: rgba(255, 255, 255, .3);
                }

                #pdf-controls button:disabled {
                    opacity: .35;
                    cursor: not-allowed;
                }

                #pdf-loading {
                    color: #fff;
                    text-align: center;
                    padding: 60px 20px;
                }

                .pdf-spinner {
                    width: 40px;
                    height: 40px;
                    border: 3px solid rgba(255, 255, 255, .2);
                    border-top-color: #07a701;
                    border-radius: 50%;
                    animation: spin .7s linear infinite;
                    margin: 0 auto 14px;
                }

                @keyframes spin {
                    to {
                        transform: rotate(360deg);
                    }
                }
            </style>
        </head>

        <body>
            <div class="pdf-topbar">
                <div>
                    <h2><?php echo htmlspecialchars($fileName); ?></h2>
                    <p>PDF Document · <?php echo $sizeLabel; ?></p>
                </div>
                <div class="pdf-topbar-btns">
                    <button class="theme-btn" onclick="toggleTheme()" title="Dark mode" style="color:#fff;">🌙</button>
                    <a class="pdf-btn dl"
                        href="download.php?type=<?php echo htmlspecialchars($type); ?>&id=<?php echo $id; ?>"
                        target="_blank">
                        ⬇ Download
                    </a>
                    <a class="pdf-btn bk" href="course.php?id=<?php echo (int)$courseId; ?>">← Back to Course</a>
                </div>
            </div>

            <div id="pdf-canvas-wrap">
                <div id="pdf-loading">
                    <div class="pdf-spinner"></div>
                    <p>Loading document…</p>
                </div>
            </div>

            <div id="pdf-controls" style="display:none;">
                <button id="prev-btn" title="Previous page">‹</button>
                <span id="page-info">Page 1 of 1</span>
                <button id="next-btn" title="Next page">›</button>
                <span style="opacity:.4;">|</span>
                <button id="zoom-out" title="Zoom out">−</button>
                <span id="zoom-label">100%</span>
                <button id="zoom-in" title="Zoom in">+</button>
            </div>

            <!-- PDF.js from CDN — no installation needed -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
            <script>
                // PDF loaded via secure stream — fast, no base64 encoding overhead
                const PDF_URL = '<?php echo $streamUrl; ?>';

                pdfjsLib.GlobalWorkerOptions.workerSrc =
                    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

                let pdfDoc = null;
                let pageNum = 1;
                let scale = 1.4;

                async function loadPdf() {
                    pdfDoc = await pdfjsLib.getDocument(PDF_URL).promise;
                    document.getElementById('pdf-loading').style.display = 'none';
                    document.getElementById('pdf-controls').style.display = 'flex';
                    await renderAllPages();
                    updateControls();
                }

                async function renderAllPages() {
                    const wrap = document.getElementById('pdf-canvas-wrap');
                    wrap.innerHTML = '';
                    for (let i = 1; i <= pdfDoc.numPages; i++) {
                        const page = await pdfDoc.getPage(i);
                        const viewport = page.getViewport({
                            scale
                        });
                        const canvas = document.createElement('canvas');
                        canvas.width = viewport.width;
                        canvas.height = viewport.height;
                        canvas.id = 'page-' + i;
                        wrap.appendChild(canvas);
                        const ctx = canvas.getContext('2d');
                        await page.render({
                            canvasContext: ctx,
                            viewport
                        }).promise;
                    }
                }

                function updateControls() {
                    document.getElementById('page-info').textContent =
                        `Page ${pageNum} of ${pdfDoc.numPages}`;
                    document.getElementById('prev-btn').disabled = pageNum <= 1;
                    document.getElementById('next-btn').disabled = pageNum >= pdfDoc.numPages;
                    document.getElementById('zoom-label').textContent = Math.round(scale * 100) + '%';
                }

                function scrollToPage(n) {
                    const el = document.getElementById('page-' + n);
                    if (el) el.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }

                // Track current page based on scroll
                document.getElementById('pdf-canvas-wrap').addEventListener('scroll', function() {
                    if (!pdfDoc) return;
                    for (let i = 1; i <= pdfDoc.numPages; i++) {
                        const el = document.getElementById('page-' + i);
                        if (!el) continue;
                        const rect = el.getBoundingClientRect();
                        if (rect.top >= 0 && rect.top < window.innerHeight / 2) {
                            pageNum = i;
                            updateControls();
                            break;
                        }
                    }
                });

                document.getElementById('prev-btn').addEventListener('click', () => {
                    if (pageNum > 1) {
                        pageNum--;
                        scrollToPage(pageNum);
                        updateControls();
                    }
                });
                document.getElementById('next-btn').addEventListener('click', () => {
                    if (pageNum < pdfDoc.numPages) {
                        pageNum++;
                        scrollToPage(pageNum);
                        updateControls();
                    }
                });
                document.getElementById('zoom-in').addEventListener('click', async () => {
                    if (scale >= 3) return;
                    scale += 0.25;
                    await renderAllPages();
                    updateControls();
                });
                document.getElementById('zoom-out').addEventListener('click', async () => {
                    if (scale <= 0.5) return;
                    scale -= 0.25;
                    await renderAllPages();
                    updateControls();
                });

                loadPdf().catch(err => {
                    document.getElementById('pdf-loading').innerHTML =
                        '<p style="color:#f87171;">Could not load PDF: ' + err.message + '</p>' +
                        '<a href="download.php?type=<?php echo htmlspecialchars($type); ?>&id=<?php echo $id; ?>" style="color:#07a701;">⬇ Download instead</a>';
                });
            </script>
        </body>

        </html>
<?php
        exit();
    }

    // Other inline types (txt, images) — serve directly
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . $safeName . '"');
} else {
    // Force download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Description: File Transfer');
    header('Pragma: public');
}

readfile($abs);
exit();
