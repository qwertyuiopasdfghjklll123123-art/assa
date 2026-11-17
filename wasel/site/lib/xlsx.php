<?php
/**
 * استخراج الأرقام من العمود الأول لملف Excel (.xlsx) بدون أي مكتبة خارجية،
 * أو من ملف CSV/نصي. (نفس منطق النسخة القديمة: أي خلية فيها 8 أرقام أو أكثر)
 */

function numbers_from_text(string $text): array
{
    $out = [];
    foreach (preg_split('/[,\n\r]+/', $text) as $part) {
        $part = trim($part);
        $d = preg_replace('/\D/', '', $part);
        // مكتوب برمز دولي (+ أو 00): نعلّمه بـ + حتى ما ينضاف له رمز الدولة الافتراضي
        if (preg_match('/^(\+|00)/', $part)) {
            $d = preg_replace('/^00/', '', $d);
            if (strlen($d) >= 8) {
                $out[] = '+' . $d;
            }
            continue;
        }
        if (strlen($d) >= 8) {
            $out[] = $d;
        }
    }
    return $out;
}

function cell_to_digits(string $value): string
{
    $value = trim($value);
    // أرقام كبيرة قد تنحفظ بصيغة علمية مثل 9.6477E+11
    if (preg_match('/^\d+(\.\d+)?E\+\d+$/i', $value)) {
        $value = number_format((float)$value, 0, '', '');
    } elseif (preg_match('/^(\d+)\.0+$/', $value, $m)) {
        $value = $m[1];
    }
    return preg_replace('/\D/', '', $value);
}

/** يرجع مصفوفة أرقام، أو يرمي استثناء لو الملف غير صالح */
function numbers_from_upload(string $path, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (in_array($ext, ['csv', 'txt'], true)) {
        $out = [];
        foreach (file($path) as $line) {
            $first = str_getcsv($line)[0] ?? '';
            $d = cell_to_digits((string)$first);
            if (strlen($d) >= 8) {
                $out[] = $d;
            }
        }
        return $out;
    }
    return numbers_from_xlsx($path);
}

function numbers_from_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('امتداد zip غير مفعّل بـ PHP على الاستضافة');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('ملف Excel غير صالح');
    }

    // النصوص المشتركة (الخلايا النصية تشير لها برقم)
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $txt = '';
                    foreach ($si->r as $r) {
                        $txt .= (string)$r->t;
                    }
                    $shared[] = $txt;
                }
            }
        }
    }

    // أول ورقة بالملف (نفس wb.active بالنسخة القديمة تقريباً)
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wbRels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $wb = $zip->getFromName('xl/workbook.xml');
    if ($wbRels !== false && $wb !== false) {
        $wbx = @simplexml_load_string($wb);
        $relx = @simplexml_load_string($wbRels);
        if ($wbx && $relx && isset($wbx->sheets->sheet[0])) {
            $rid = (string)$wbx->sheets->sheet[0]->attributes('r', true)->id;
            foreach ($relx->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
    }
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('ما لقيت ورقة بيانات داخل الملف');
    }
    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) {
        throw new RuntimeException('تعذرت قراءة الملف');
    }

    $out = [];
    foreach ($sheet->sheetData->row as $row) {
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            // العمود الأول فقط (A)
            if ($ref !== '' && !preg_match('/^A\d+$/', $ref)) {
                continue;
            }
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)$c->is->t;
            } else {
                $val = (string)$c->v;
            }
            $d = cell_to_digits($val);
            if (strlen($d) >= 8) {
                $out[] = $d;
            }
            break;
        }
    }
    return $out;
}
