import { toFa } from './jalali';

export interface ExportColumn { key: string; label: string; formatter?: (v: any, row: any) => string; }

function escapeCsv(v: any): string {
  const s = v == null ? '' : String(v);
  if (s.includes(',') || s.includes('"') || s.includes('\n')) return '"' + s.replace(/"/g, '""') + '"';
  return s;
}

export function downloadFile(name: string, content: string | Blob, mime = 'text/plain') {
  const blob = content instanceof Blob ? content : new Blob([content], { type: mime + ';charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = name;
  document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 100);
}

function getFontsBase(): string {
  try {
    const u = (window as any).CPTTF_ERP?.assets?.fontsBase;
    if (u && typeof u === 'string') return u.replace(/\/$/, '') + '/';
  } catch {}
  return '/wp-content/plugins/client-project-tracker/assets/fonts/';
}

function buildFontCss(): string {
  const base = getFontsBase();
  return `
@font-face { font-family: 'Dana'; src: url('${base}Dana/Dana-FaNum-Regular.ttf') format('truetype'); font-weight: 400; font-style: normal; font-display: block; }
@font-face { font-family: 'Dana'; src: url('${base}Dana/Dana-FaNum-Medium.ttf') format('truetype'); font-weight: 500; font-style: normal; font-display: block; }
@font-face { font-family: 'Dana'; src: url('${base}Dana/Dana-FaNum-Bold.ttf') format('truetype'); font-weight: 700; font-style: normal; font-display: block; }
@font-face { font-family: 'Vazirmatn'; src: url('${base}Vazirmatn-Regular.ttf') format('truetype'); font-weight: 400; font-style: normal; font-display: block; }
@font-face { font-family: 'Vazirmatn'; src: url('${base}Vazirmatn-Bold.ttf') format('truetype'); font-weight: 700; font-style: normal; font-display: block; }
`;
}
const FONT_CSS = buildFontCss();

export function exportCsv(filename: string, columns: ExportColumn[], rows: any[]) {
  const head = columns.map(c => escapeCsv(c.label)).join(',');
  const body = rows.map(r => columns.map(c => {
    const v = r[c.key];
    return escapeCsv(c.formatter ? c.formatter(v, r) : v);
  }).join(',')).join('\n');
  downloadFile(filename + '.csv', '\uFEFF' + head + '\n' + body, 'text/csv');
}

export function exportExcel(filename: string, columns: ExportColumn[], rows: any[], title?: string) {
  const head = columns.map(c => `<th>${c.label}</th>`).join('');
  const body = rows.map(r => '<tr>' + columns.map(c => {
    const v = r[c.key];
    const text = c.formatter ? c.formatter(v, r) : (v ?? '');
    return `<td>${text}</td>`;
  }).join('') + '</tr>').join('');
  const html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
  <head><meta charset="utf-8"><style>${FONT_CSS} body{font-family:'Dana','Vazirmatn',Tahoma,sans-serif; direction:rtl;}</style></head>
  <body dir="rtl">
  ${title ? `<h3>${title}</h3>` : ''}
  <table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;">
    <thead><tr style="background:#eef2ff;font-weight:bold;color:#312e81;">${head}</tr></thead>
    <tbody>${body}</tbody>
  </table>
  </body></html>`;
  downloadFile(filename + '.xls', '\uFEFF' + html, 'application/vnd.ms-excel');
}

const PRINT_STYLES = `
${FONT_CSS}
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: 'Dana', 'Vazirmatn', Tahoma, Arial, sans-serif; padding: 24px; color: #1e293b; direction: rtl; background: #fff; margin: 0; }
.cptt-print-header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 16px; border-bottom: 2px solid #4f46e5; margin-bottom: 20px; }
.cptt-print-header h1 { font-size: 20px; font-weight: 700; color: #4f46e5; margin: 0; }
.cptt-print-header .meta { font-size: 11px; color: #64748b; text-align: left; }
.cptt-print-extra { font-size: 11px; color: #64748b; margin-bottom: 12px; }
table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 8px; }
th, td { border: 1px solid #cbd5e1; padding: 7px 10px; text-align: right; }
th { background: #eef2ff; color: #312e81; font-weight: 700; }
tbody tr:nth-child(even) { background: #f8fafc; }
tfoot td { background: #fef3c7; font-weight: 700; }
.num { font-feature-settings: 'tnum' 1; font-variant-numeric: tabular-nums; }
.cptt-print-footer { margin-top: 24px; padding-top: 12px; border-top: 1px dashed #cbd5e1; font-size: 10px; color: #94a3b8; text-align: center; }
@media print { @page { margin: 1.5cm; size: A4; } .cptt-print-header { border-color: #4f46e5; } }
`;

function getCompanyName(): string {
  try { return (window as any).CPTTF_ERP?.company?.name || 'هماهنگ پرو'; } catch { return 'هماهنگ پرو'; }
}

export function printReport(title: string, columns: ExportColumn[], rows: any[], extraHead?: string) {
  const win = window.open('', '_blank');
  if (!win) return;
  const head = columns.map(c => `<th>${c.label}</th>`).join('');
  const body = rows.map(r => '<tr>' + columns.map(c => {
    const v = r[c.key];
    const text = c.formatter ? c.formatter(v, r) : (v ?? '');
    return `<td>${text}</td>`;
  }).join('') + '</tr>').join('');
  win.document.write(`<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>${title}</title>
    <style>${PRINT_STYLES}</style></head><body>
    <div class="cptt-print-header">
      <div>
        <h1>${title}</h1>
        <div style="color:#64748b;font-size:11px;margin-top:4px;">${getCompanyName()}</div>
      </div>
      <div class="meta">
        تاریخ چاپ: ${toFa(new Date().toLocaleDateString('fa-IR'))}<br>
        ساعت: ${toFa(new Date().toLocaleTimeString('fa-IR'))}
      </div>
    </div>
    ${extraHead ? `<div class="cptt-print-extra">${extraHead}</div>` : ''}
    <table>
      <thead><tr>${head}</tr></thead>
      <tbody>${body}</tbody>
    </table>
    <div class="cptt-print-footer">تولید‌شده توسط پلتفرم مالی هماهنگ ERP</div>
    <script>
      (function(){
        function doPrint(){ try { window.focus(); window.print(); } catch(e) {} }
        var fonts = document.fonts;
        if (!fonts || !fonts.load) { window.onload = function(){ setTimeout(doPrint, 800); }; return; }
        Promise.all([
          fonts.load("400 14px Dana"), fonts.load("500 14px Dana"), fonts.load("700 14px Dana"),
          fonts.load("400 14px Vazirmatn"), fonts.load("700 14px Vazirmatn")
        ]).then(function(){ return fonts.ready; }).catch(function(){}).then(function(){ setTimeout(doPrint, 300); });
      })();
    </script>
  </body></html>`);
  win.document.close();
}

export function exportPdf(_filename: string, columns: ExportColumn[], rows: any[], title: string) {
  printReport(title, columns, rows, 'برای ذخیره به‌عنوان PDF، در پنجره چاپ گزینه «ذخیره به PDF» را انتخاب کنید.');
}
