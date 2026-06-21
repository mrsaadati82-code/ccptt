// Jalali calendar helpers used across the ERP UI.
// Self-contained: no external dependency. Algorithm is the standard
// Khayyam intercalation (Gregorian↔Jalali) ported from PHP CPTT_Core.

export const FA_MONTHS = [
  'فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور',
  'مهر','آبان','آذر','دی','بهمن','اسفند',
];
export const FA_DAYS_SHORT = ['ش','ی','د','س','چ','پ','ج'];

const FA_DIGITS = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];

export function toFa(input: string | number): string {
  return String(input).replace(/\d/g, d => FA_DIGITS[+d]);
}
export function toEn(input: string | number): string {
  let s = String(input);
  for (let i = 0; i < 10; i++) s = s.replace(new RegExp(FA_DIGITS[i], 'g'), String(i));
  s = s.replace(/[٠١٢٣٤٥٦٧٨٩]/g, d => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));
  return s;
}

function div(a: number, b: number) { return Math.floor(a / b); }

export function gregorianToJalali(gy: number, gm: number, gd: number): [number, number, number] {
  const g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
  const jy = (gy <= 1600) ? 0 : 979;
  const gy2 = gy - ((gy <= 1600) ? 621 : 1600);
  const gy3 = (gm > 2) ? gy2 + 1 : gy2;
  let days = (365 * gy2) + div(gy3 + 3, 4) - div(gy3 + 99, 100) + div(gy3 + 399, 400) - 80 + gd + g_d_m[gm - 1];
  let jy2 = jy + 33 * div(days, 12053);
  days %= 12053;
  jy2 += 4 * div(days, 1461);
  days %= 1461;
  if (days > 365) { jy2 += div(days - 1, 365); days = (days - 1) % 365; }
  const jm = (days < 186) ? 1 + div(days, 31) : 7 + div(days - 186, 30);
  const jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
  return [jy2, jm, jd];
}

export function jalaliToGregorian(jy: number, jm: number, jd: number): [number, number, number] {
  const jy0 = (jy <= 979) ? 0 : 1600;
  const jy2 = (jy <= 979) ? jy : jy - 979;
  let days = (365 * jy2) + (div(jy2, 33) * 8) + div((jy2 % 33) + 3, 4) + 78 + jd
    + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);
  let gy = 400 * div(days, 146097);
  days %= 146097;
  let leap = true;
  if (days >= 36525) {
    days--;
    gy += 100 * div(days, 36524);
    days %= 36524;
    if (days >= 365) days++;
    else leap = false;
  }
  gy += 4 * div(days, 1461);
  days %= 1461;
  if (days >= 366) {
    leap = false;
    days--;
    gy += div(days, 365);
    days %= 365;
  }
  const sal_a = [0,31,(leap ? 29 : 28),31,30,31,30,31,31,30,31,30,31];
  let gm = 0;
  for (gm = 0; gm < 13; gm++) {
    const v = sal_a[gm];
    if (days < v) break;
    days -= v;
  }
  const gd = days + 1;
  return [gy + jy0, gm, gd];
}

export function todayJalali() {
  const d = new Date();
  const [jy, jm, jd] = gregorianToJalali(d.getFullYear(), d.getMonth() + 1, d.getDate());
  return { jy, jm, jd };
}

export function daysInJalaliMonth(jy: number, jm: number): number {
  if (jm <= 6) return 31;
  if (jm <= 11) return 30;
  // اسفند
  // Khayyam leap rule
  const r = ((jy - (jy > 0 ? 474 : 473)) % 2820 + 474 + 38) * 682;
  const leap = ((r % 2816) < 682);
  return leap ? 30 : 29;
}
