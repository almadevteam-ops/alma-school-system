<?php
// קוד Google Apps Script שמודבק פעם אחת בחשבון הדרייב (דף "גיבויים"). הוא לא שומר ולא קורא כלום מעבר לתיקיית הגיבויים.
return <<<'JS'
// עלמא – קבלת גיבויים בתיקיית דרייב. מדביקים, ממלאים שתי שורות, ומפרסמים כ-Web App.
const SECRET = 'הדביקו כאן סיסמה ארוכה שהמערכת הציעה';
const FOLDER_ID = 'הדביקו כאן את מזהה התיקיה';

function out_(o) { return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON); }

function doPost(e) {
  try {
    const r = JSON.parse(e.postData.contents);
    if (!r.secret || r.secret !== SECRET) return out_({ ok: false, error: 'סיסמה שגויה' });
    const folder = DriveApp.getFolderById(FOLDER_ID);
    if (r.action === 'check') return out_({ ok: true, name: folder.getName() });
    if (r.action === 'init') {
      if (!/^alma-(backup|delta)-\d{8}-\d{6}\.almb$/.test(String(r.name))) return out_({ ok: false, error: 'שם קובץ לא מורשה' });
      const resp = UrlFetchApp.fetch('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', {
        method: 'post', contentType: 'application/json; charset=UTF-8', muteHttpExceptions: true,
        headers: { Authorization: 'Bearer ' + ScriptApp.getOAuthToken(), 'X-Upload-Content-Type': 'application/octet-stream', 'X-Upload-Content-Length': String(r.size) },
        payload: JSON.stringify({ name: r.name, parents: [FOLDER_ID] })
      });
      const h = resp.getAllHeaders(); const loc = h['Location'] || h['location'];
      if (resp.getResponseCode() !== 200 || !loc) return out_({ ok: false, error: 'פתיחת העלאה נכשלה (' + resp.getResponseCode() + ')' });
      return out_({ ok: true, url: loc });
    }
    if (r.action === 'list') {
      const files = []; const it = folder.getFiles();
      while (it.hasNext()) { const f = it.next(); if (/^alma-(backup|delta)-/.test(f.getName())) files.push({ id: f.getId(), name: f.getName(), size: f.getSize() }); }
      return out_({ ok: true, files: files });
    }
    if (r.action === 'delete') {
      const f = DriveApp.getFileById(String(r.id)); let inside = false; const ps = f.getParents();
      while (ps.hasNext()) { if (ps.next().getId() === FOLDER_ID) inside = true; }
      if (!inside || !/^alma-(backup|delta)-/.test(f.getName())) return out_({ ok: false, error: 'הקובץ אינו קובץ גיבוי בתיקיה' });
      f.setTrashed(true); return out_({ ok: true });
    }
    return out_({ ok: false, error: 'פעולה לא מוכרת' });
  } catch (err) { return out_({ ok: false, error: String(err) }); }
}
JS;
