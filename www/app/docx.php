<?php
// Word (.docx) fájlok beolvasása külső könyvtár nélkül (ZipArchive + DOM).
// Eredmény: a text.php egyszerű jelölése (bekezdések, **félkövér**, *dőlt*).

const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

/**
 * A dokumentum bekezdései sorban; az üres bekezdések üres szövegként szerepelnek.
 * @return string[]
 */
function docx_paragraphs(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Nem nyitható meg (sérült .docx?)');
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) throw new RuntimeException('Nincs benne szöveg (word/document.xml)');

    $dom = new DOMDocument();
    $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', W_NS);

    $out = [];
    foreach ($xp->query('//w:body//w:p') as $p) {
        $segments = []; // [text, bold, italic]
        foreach ($xp->query('.//w:r', $p) as $r) {
            $bold = docx_flag($xp, $r, 'b');
            $italic = docx_flag($xp, $r, 'i');
            $text = '';
            foreach ($r->childNodes as $n) {
                if (!($n instanceof DOMElement) || $n->namespaceURI !== W_NS) continue;
                switch ($n->localName) {
                    case 't': $text .= $n->textContent; break;
                    case 'tab': $text .= ' '; break;
                    case 'br': case 'cr': $text .= "\n"; break;
                    case 'noBreakHyphen': $text .= '-'; break;
                }
            }
            if ($text === '') continue;
            $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last][1] === $bold && $segments[$last][2] === $italic) {
                $segments[$last][0] .= $text;
            } else {
                $segments[] = [$text, $bold, $italic];
            }
        }
        $line = '';
        foreach ($segments as [$text, $bold, $italic]) {
            $line .= docx_wrap($text, $bold, $italic);
        }
        $out[] = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line));
    }
    return $out;
}

function docx_flag(DOMXPath $xp, DOMElement $run, string $tag): bool
{
    $el = $xp->query("w:rPr/w:$tag", $run)->item(0);
    if (!$el) return false;
    $val = $el->getAttributeNS(W_NS, 'val');
    return !in_array($val, ['0', 'false', 'none'], true);
}

/** Jelölés a szöveg köré úgy, hogy a szélső szóközök kívül maradjanak. */
function docx_wrap(string $text, bool $bold, bool $italic): string
{
    if ((!$bold && !$italic) || trim($text) === '') return $text;
    preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m);
    $mark = ($bold ? '**' : '') . ($italic ? '*' : '');
    $close = ($italic ? '*' : '') . ($bold ? '**' : '');
    // több soros formázott szövegnél soronként zárjuk
    $inner = implode("$close\n$mark", explode("\n", $m[2]));
    return $m[1] . $mark . $inner . $close . $m[3];
}

/** Próza: a nem üres bekezdések, üres sorral elválasztva. */
function docx_prose(string $path): string
{
    return implode("\n\n", array_values(array_filter(docx_paragraphs($path), fn($p) => $p !== '')));
}

/**
 * Vers: a Wordben minden sor külön bekezdés, a versszakokat üres bekezdés választja el.
 * Eredmény: sortörés soronként, üres sor a versszakok között.
 */
function docx_poem(string $path): string
{
    $text = implode("\n", docx_paragraphs($path)); // a soron belüli sortörés (Shift+Enter) is sor marad
    return trim(preg_replace("/\n{2,}/", "\n\n", $text));
}
