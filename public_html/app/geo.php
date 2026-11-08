<?php
require __DIR__ . '/../../private/lib/bootstrap.php';
$u = require_role(['admin']);
$err = ''; $msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $f = $_FILES['csv'] ?? null;
  if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $err = upload_error_text((int)($f['error'] ?? UPLOAD_ERR_NO_FILE));
  elseif ($f['size'] > GEO_MAX_BYTES) $err = 'הקובץ גדול מדי.';
  elseif (!is_uploaded_file($f['tmp_name']) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) $err = 'קובץ לא תקין.';
  else {
    $r = geo_import($f['tmp_name']);
    if (is_string($r)) $err = $r;
    else { $msg = 'נטענו ' . number_format($r[0]) . ' ערים ו-' . number_format($r[1]) . ' רחובות.'; audit('geo_import', 'geo', '', $r[0] . ' ערים, ' . $r[1] . ' רחובות'); }
  }
}
[$nc, $ns] = geo_stats();
page_head('ערים ורחובות');
?>
<h1>ערים ורחובות <span class="mu">(ספריית כתובות)</span></h1>
<?php if ($msg) echo '<p class="ok">' . e($msg) . '</p>'; if ($err) echo '<p class="er">' . e($err) . '</p>'; ?>
<div class="card">
  <p><?= $ns > 0 ? 'בספרייה כרגע <b>' . number_format($nc) . '</b> ערים ו-<b>' . number_format($ns) . '</b> רחובות. בכרטיס התלמידה, שדה העיר והכתובת מושלמים אוטומטית ונבדקים מול הרשימה.' : 'הספרייה עדיין ריקה, ולכן שדות העיר והכתובת עובדים כרגיל, בלי השלמה ובלי בדיקה.' ?></p>
  <p class="mu">איך טוענים: להוריד מאתר data.gov.il (חיפוש "רחובות בישראל" / "רשימת רחובות") את הקובץ בפורמט CSV, ולהעלות אותו כאן. הקובץ צריך לכלול עמודות "שם ישוב" ו"שם רחוב". הטעינה מחליפה את כל הספרייה הקיימת, ואפשר לחזור עליה בכל עת כדי לעדכן.</p>
  <form method="post" enctype="multipart/form-data" class="bar">
    <?= csrf_field() ?>
    <label>קובץ CSV (עד 12MB)<input type="file" name="csv" accept=".csv,text/csv,text/plain" required></label>
    <button>טעינת הספרייה</button>
  </form>
</div>
<?php page_foot();
