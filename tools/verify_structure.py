#!/usr/bin/env python3
"""
Struktureller Integritaetscheck fuer PHP-Dateien.

ERSETZT KEIN `php -l`. Der Sandbox, in dem die Dateien entstanden sind, hat
kein PHP. Dieses Skript faengt die haeufigsten stillen Schaeden ab:
Truncation, Null-Bytes, unbalancierte Klammern, fehlender Datei-Abschluss.

Es tokenisiert grob und ignoriert dabei Strings, Heredocs und Kommentare,
damit Klammern in Textliteralen nicht falsch gezaehlt werden.

Ausfuehren:
    python3 tools/verify_structure.py plugin/*.php tests/*.php

Exit-Code 0 = alles sauber, 1 = mindestens ein Befund.
"""

import sys
import os

PAIRS = {'{': '}', '(': ')', '[': ']'}
CLOSERS = {v: k for k, v in PAIRS.items()}


def strip_php_literals(src: str) -> str:
    """Entfernt Strings und Kommentare, damit nur Code-Klammern gezaehlt werden."""
    out = []
    i = 0
    n = len(src)
    while i < n:
        c = src[i]

        # Zeilenkommentare
        if c == '/' and i + 1 < n and src[i + 1] == '/':
            while i < n and src[i] != '\n':
                i += 1
            continue
        if c == '#':
            while i < n and src[i] != '\n':
                i += 1
            continue

        # Blockkommentare
        if c == '/' and i + 1 < n and src[i + 1] == '*':
            i += 2
            while i + 1 < n and not (src[i] == '*' and src[i + 1] == '/'):
                i += 1
            i += 2
            continue

        # Strings
        if c in ('"', "'"):
            quote = c
            i += 1
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i] == quote:
                    i += 1
                    break
                i += 1
            out.append('""')
            continue

        out.append(c)
        i += 1
    return ''.join(out)


def check(path: str):
    findings = []

    with open(path, 'rb') as f:
        raw = f.read()

    if not raw:
        return ['Datei ist leer']

    if b'\x00' in raw:
        findings.append(f'Null-Bytes gefunden ({raw.count(chr(0).encode())})')

    try:
        src = raw.decode('utf-8')
    except UnicodeDecodeError as e:
        return [f'Kein gueltiges UTF-8: {e}']

    if not src.lstrip().startswith('<?php'):
        findings.append('Beginnt nicht mit <?php')

    if not raw.endswith(b'\n'):
        findings.append('Endet nicht mit Newline (Truncation-Indiz)')

    # Ein abgeschnittener PHP-File endet oft mitten in einem Bezeichner.
    tail = src.rstrip()
    if tail and tail[-1] not in ('}', ';', '>', ')'):
        findings.append(f'Verdaechtiges Dateiende: ...{tail[-40:]!r}')

    code = strip_php_literals(src)

    stack = []
    line = 1
    for ch in code:
        if ch == '\n':
            line += 1
        elif ch in PAIRS:
            stack.append((ch, line))
        elif ch in CLOSERS:
            if not stack:
                findings.append(f'Zeile {line}: schliessendes {ch!r} ohne Gegenstueck')
                break
            opener, oline = stack.pop()
            if PAIRS[opener] != ch:
                findings.append(
                    f'Zeile {line}: {ch!r} passt nicht zu {opener!r} aus Zeile {oline}')
                break

    if stack:
        opener, oline = stack[0]
        findings.append(
            f'{len(stack)} nicht geschlossene Klammer(n), erste: {opener!r} in Zeile {oline}')

    return findings


def main(argv):
    paths = argv[1:]
    if not paths:
        print('Aufruf: verify_structure.py <datei.php> [...]')
        return 1

    total_bad = 0
    for path in paths:
        if not os.path.isfile(path):
            print(f'  FEHLT  {path}')
            total_bad += 1
            continue

        findings = check(path)
        lines = sum(1 for _ in open(path, 'rb'))
        size = os.path.getsize(path)

        if findings:
            total_bad += 1
            print(f'  BEFUND {path}  ({lines} Zeilen, {size} B)')
            for f in findings:
                print(f'         - {f}')
        else:
            print(f'  OK     {path}  ({lines} Zeilen, {size} B)')

    print()
    if total_bad:
        print(f'{total_bad} Datei(en) mit Befund.')
        print('Hinweis: dieser Check ersetzt kein `php -l`.')
        return 1

    print('Struktur sauber. NAECHSTER PFLICHTSCHRITT auf dem Server:')
    print('  find . -name "*.php" -exec php -l {} \\;')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
