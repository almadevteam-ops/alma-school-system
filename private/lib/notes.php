<?php
declare(strict_types=1);
// יומן הערות לתלמידה: הערות נוספות בלבד (אין עריכה ואין מחיקה), עם תאריך, כותבת וקבצים מצורפים מוצפנים.
const NOTE_MAX_FILES = 5;
const NOTE_MAX_LEN = 5000;

function note_files_store(array $files, int $noteId): array {
  $ok = 0; $errs = [];
  if (!is_array($files['name'] ?? null)) return [0, []];
  foreach ($files['name'] as $i => $n) {
    if (($files['error'][$i] ?? 4) === UPLOAD_ERR_NO_FILE) continue;
    $name = clean_name((string)$n);
    if ($ok >= NOTE_MAX_FILES) { $errs[] = $name . ': עד ' . NOTE_MAX_FILES . ' קבצים להערה.'; continue; }
    if ($files['error'][$i] !== UPLOAD_ERR_OK) { $errs[] = $name . ': ' . upload_error_text((int)$files['error'][$i]); continue; }
    $tmp = $files['tmp_name'][$i];
    if (!is_uploaded_file($tmp) && !(defined('TESTING_UPLOADS') && TESTING_UPLOADS)) { $errs[] = $name . ': קובץ לא תקין.'; continue; }
    $size = (int)filesize($tmp);
    if ($size <= 0) { $errs[] = $name . ': הקובץ ריק.'; continue; }
    if ($size > FILE_MAX) { $errs[] = $name . ': הקובץ גדול מדי (עד 10MB).'; continue; }
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset(FILE_MIMES[$mime])) { $errs[] = $name . ': סוג לא נתמך (JPG/PNG/PDF).'; continue; }
    $stored = bin2hex(random_bytes(16)); $path = PRIV . '/storage/' . $stored . '.bin';
    if (file_put_contents($path, enc_bytes((string)file_get_contents($tmp), 'note:' . $stored), LOCK_EX) === false) { $errs[] = $name . ': השמירה נכשלה.'; continue; }
    @chmod($path, 0600);
    try { db()->prepare('INSERT INTO note_files (note_id, orig_name, mime, size, stored, created_at) VALUES (?,?,?,?,?,?)')->execute([$noteId, $name, $mime, $size, $stored, now()]); }
    catch (Throwable $t) { @unlink($path); throw $t; }
    $ok++;
  }
  return [$ok, $errs];
}
/** קבצים מצורפים של הערות שייפגעו ממחיקת תלמידה (לפני המחיקה). */
function note_stored_for_student(int $sid): array {
  $q = db()->prepare('SELECT nf.stored FROM note_files nf JOIN student_notes n ON n.id = nf.note_id WHERE n.student_id = ?'); $q->execute([$sid]);
  return array_column($q->fetchAll(), 'stored');
}
/** כרטיס הערות. $studs = [['id'=>, 'name'=>], ...]; ההערה האחרונה ראשונה. */
function notes_card(array $studs, string $back): void {
  if (!$studs) return;
  $ids = array_map(fn($s) => (int)$s['id'], $studs); $names = array_column($studs, 'name', 'id');
  $in = implode(',', $ids);
  $notes = db()->query("SELECT * FROM student_notes WHERE student_id IN ($in) ORDER BY created_at DESC, id DESC")->fetchAll();
  $files = [];
  if ($notes) { $nid = implode(',', array_map(fn($n) => (int)$n['id'], $notes));
    foreach (db()->query("SELECT id, note_id, orig_name, size FROM note_files WHERE note_id IN ($nid) ORDER BY id")->fetchAll() as $f) $files[(int)$f['note_id']][] = $f; }
  ?>
<div class="card">
  <h2>הערות ושיחות</h2>
  <form method="post" action="note_add.php" enctype="multipart/form-data" class="notef">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
    <?php if (count($studs) > 1): ?><label class="mu">על איזו תלמידה?<select name="student_id"><?php foreach ($studs as $s) echo '<option value="' . (int)$s['id'] . '">' . e($s['name']) . '</option>'; ?></select></label>
    <?php else: ?><input type="hidden" name="student_id" value="<?= (int)$studs[0]['id'] ?>"><?php endif; ?>
    <label class="mu">הערה חדשה (למשל: סיכום שיחת טלפון)<textarea name="body" rows="3" class="w100" maxlength="<?= NOTE_MAX_LEN ?>" required></textarea></label>
    <label class="mu">צילומי מסך / קבצים (JPG, PNG, PDF; עד <?= NOTE_MAX_FILES ?>, כל אחד עד 10MB)<input type="file" name="f[]" multiple accept="image/jpeg,image/png,image/webp,application/pdf"></label>
    <p><button>שמירת הערה</button> <span class="mu">הערה שנשמרה לא ניתנת לעריכה או למחיקה. נשמרים התאריך ושם הכותבת.</span></p>
  </form>
  <?php if (!$notes) echo '<p class="mu">אין עדיין הערות.</p>'; foreach ($notes as $n): ?>
  <div class="note<?= $n['legacy'] ? ' old' : '' ?>">
    <div class="mu"><b><?= e($n['author_name'] ?: 'לא ידוע') ?></b> · <?= e(date('d/m/Y H:i', strtotime((string)$n['created_at']))) ?><?= count($studs) > 1 ? ' · ' . e($names[$n['student_id']] ?? '') : '' ?></div>
    <div class="nb"><?= nl2br(e($n['body'])) ?></div>
    <?php foreach ($files[(int)$n['id']] ?? [] as $f): ?><a class="btn sec sm" href="note_file.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= e($f['orig_name']) ?></a> <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php }
