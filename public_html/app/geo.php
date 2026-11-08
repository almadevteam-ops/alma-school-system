<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
$err = ''; $msg = ''; $badList = null;
$tooBig = $_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;   // חרג מ-post_max_size: PHP מרוקן הכול
if ($tooBig) $err = 'הקובץ גדול מדי להעלאה בשרת. יש לדחוס אותו ל-ZIP (קליק ימני על הקובץ ← שליחה אל ← תיקייה דחוסה) ולהעלות את ה-ZIP.';
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'rematch') {
  csrf_check();
  [$upd, $badList] = geo_rematch_students(); $msg = 'עודכנו ' . $upd . ' תלמידות לשמות הרשמיים.'; audit('geo_rematch', 'geo', '', $upd . ' עודכנו, ' . count($badList) . ' לא הותאמו');
}
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $f = $_FILES['csv'] ?? null;
  if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = in_array((int)($f['error'] ?? 4), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'הקובץ גדול מדי להעלאה בשרת. יש לדחוס אותו ל-ZIP (קליק ימני על הקובץ ← שליחה אל ← תיקייה דחוסה) ולהעלות את ה-ZIP.' : upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
  elseif ($f['size'] > GEO_MAX_BYTES) $err = 'הקובץ גדול מדי.';
  elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
  else {
    $src = $f['tmp_name']; $tmpz = null;
    if (is_file($src) && file_get_contents($src, false, null, 0, 2) === 'PK') {   // קובץ ZIP: מחלצים את ה-CSV שבפנים
      $z = class_exists('ZipArchive') ? new ZipArchive() : null;
      if (!$z || $z->open($src) !== true) $err = 'לא ניתן לפתוח את קובץ ה-ZIP. אפשר להעלות את ה-CSV עצמו.';
      else {
        $name = null; for ($i = 0; $i < $z->numFiles; $i++) { $st = $z->statIndex($i); if ($st && preg_match('/\.csv$/i', $st['name']) && $st['size'] <= 60 * 1048576) { $name = $st['name']; break; } }
        if ($name === null) $err = 'לא נמצא קובץ CSV בתוך ה-ZIP.';
        else { $tmpz = tempnam(sys_get_temp_dir(), 'geo'); file_put_contents($tmpz, (string)$z->getFromName($name)); $src = $tmpz; }
        $z->close();
      }
    }
    $r = $err ? $err : geo_import($src);
    if ($tmpz) @unlink($tmpz);
    if (is_string($r)) $err = $r;
    else {
      audit('geo_import', 'geo', '', $r[0] . ' ערים, ' . $r[1] . ' רחובות');
      [$upd, $badList] = geo_rematch_students();
      $msg = 'נטענו ' . number_format($r[0]) . ' ערים ו-' . number_format($r[1]) . ' רחובות. הכתובות הקיימות של התלמידות הותאמו לשמות הרשמיים (' . $upd . ' עודכנו, ' . count($badList) . ' דורשות טיפול).';
    }
  }
}
[$nc, $ns] = geo_stats();
if ($badList === null && $ns > 0) { $dry = []; foreach (db()->query("SELECT id, name, city, addr FROM students WHERE archived_at IS NULL AND (city <> '' OR addr <> '')")->fetchAll() as $x) { $why = []; $c = geo_match_city((string)$x['city']); if ($x['city'] !== '' && $c === null) $why[] = 'עיר "' . $x['city'] . '"'; elseif ($x['addr'] !== '') { $cid = geo_city_id($c ?? ''); if (!$cid || geo_match_street($cid, (string)$x['addr']) === null) $why[] = 'רחוב "' . $x['addr'] . '"'; } if ($why) $dry[] = ['id' => (int)$x['id'], 'name' => $x['name'], 'why' => implode(', ', $why)]; } $badList = $dry; }
page_head('ערים ורחובות');
?>
<h1>ערים ורחובות <span class="mu">(ספריית כתובות)</span></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <p><?= $ns > 0 ? 'בספרייה כרגע <b>' . number_format($nc) . '</b> ערים ו-<b>' . number_format($ns) . '</b> רחובות. בכרטיס התלמידה, שדה העיר והכתובת מושלמים אוטומטית ונבדקים מול הרשימה.' : 'הספרייה עדיין ריקה, ולכן שדות העיר והכתובת עובדים כרגיל, בלי השלמה ובלי בדיקה.' ?></p>
  <p class="mu">איך טוענים: להוריד מאתר data.gov.il (חיפוש "רחובות בישראל" / "רשימת רחובות") את הקובץ בפורמט CSV, ולהעלות אותו כאן. הקובץ צריך לכלול עמודות "שם ישוב" ו"שם רחוב". אפשר להעלות גם ZIP שמכיל את ה-CSV (קטן בהרבה, מומלץ אם ההעלאה נכשלת בגלל גודל). הקובץ יכול להיות בקידוד UTF-8 או עברית של Windows, והוא כולל גם שמות נרדפים שלא נטענים, רק השמות הרשמיים. הטעינה מחליפה את כל הספרייה הקיימת, ואפשר לחזור עליה בכל עת כדי לעדכן.</p>
  <form method="post" enctype="multipart/form-data" class="bar">
    <?= csrf_field() ?><input type="hidden" name="act" value="load">
    <label>קובץ CSV (עד 12MB)<input type="file" name="csv" accept=".csv,text/csv,text/plain" required></label>
    <button>טעינת הספרייה</button>
  </form>
</div>
<?php if ($ns > 0): ?>
<div class="card">
  <h2>התאמת כתובות קיימות</h2>
  <p class="mu">הכתובות הישנות נשמרו כטקסט אחד. עדכון מסד הנתונים כבר הפריד מהן את מספר הבית (המקור נשמר), והתאמה זו מחליפה עיר ורחוב בשם הרשמי מהספרייה. היא רצה אוטומטית אחרי כל טעינה, ואפשר להריץ אותה שוב.</p>
  <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="act" value="rematch"><button class="sec">הרצת התאמה עכשיו</button></form>
  <?php if ($badList): ?>
  <h2>תלמידות שהכתובת שלהן לא הותאמה (<?= count($badList) ?>)</h2>
  <p class="mu">פותחים את הכרטיס ובוחרים עיר ורחוב מהרשימה. הטקסט המקורי לא נמחק עד שמעדכנים.</p>
  <div class="sc"><table><tr><th>שם</th><th>מה לא זוהה</th><th></th></tr>
  <?php foreach (array_slice($badList, 0, 300) as $b): ?><tr><td><?= e($b['name']) ?></td><td><?= e($b['why']) ?></td><td><a class="btn sec" href="student.php?id=<?= (int)$b['id'] ?>">פתיחת כרטיס</a></td></tr><?php endforeach; ?>
  </table></div>
  <?php elseif ($badList !== null): ?><p class="ok">כל הכתובות הקיימות תואמות לרשימה.</p><?php endif; ?>
</div>
<?php endif; ?>
<?php page_foot();
