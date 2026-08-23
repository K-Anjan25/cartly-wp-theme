#!/usr/bin/env python3
"""
Structural linter for the Cartly WordPress theme.

No PHP runtime is available in this workspace, so this walks each template with
a small PHP-aware scanner (it understands <?php ?> islands, single/double
quotes, heredocs and the three comment styles) and checks the things that
actually break a theme: unbalanced delimiters, mismatched alternative-syntax
blocks, missing ABSPATH guards and stray closing tags.

Usage: python3 wordpress/cartly/bin/lint-php.py
"""
import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def strip_php(source: str):
    """Return only executable PHP code, with strings/comments/HTML removed."""
    out = []
    i, n = 0, len(source)
    in_php = False

    while i < n:
        if not in_php:
            j = source.find("<?php", i)
            k = source.find("<?=", i)
            if j == -1 and k == -1:
                break
            if j == -1 or (k != -1 and k < j):
                i, in_php = k + 3, True
            else:
                i, in_php = j + 5, True
            continue

        c = source[i]

        if source.startswith("?>", i):
            in_php = False
            i += 2
            continue

        if source.startswith("//", i) or c == "#":
            end = source.find("\n", i)
            i = n if end == -1 else end
            continue

        if source.startswith("/*", i):
            end = source.find("*/", i + 2)
            i = n if end == -1 else end + 2
            continue

        if source.startswith("<<<", i):
            m = re.match(r"<<<\s*(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\1\r?\n", source[i:])
            if m:
                label = m.group(2)
                end = re.search(r"^\s*%s\b" % re.escape(label), source[i + m.end():], re.M)
                i = n if not end else i + m.end() + end.end()
                out.append('""')
                continue

        if c in "'\"":
            quote = c
            i += 1
            while i < n:
                if source[i] == "\\":
                    i += 2
                    continue
                if source[i] == quote:
                    i += 1
                    break
                i += 1
            out.append('""')
            continue

        out.append(c)
        i += 1

    return "".join(out)


def check(path: str):
    src = io.open(path, encoding="utf-8").read()
    rel = os.path.relpath(path, ROOT)
    errs = []

    if not src.lstrip().startswith("<?php"):
        errs.append("does not start with <?php")

    if src.rstrip().endswith("?>"):
        errs.append("ends with a stray closing ?> tag")

    if "ABSPATH" not in src:
        errs.append("missing ABSPATH guard")

    code = strip_php(src)

    for op, cl, name in (("{", "}", "braces"), ("(", ")", "parens"), ("[", "]", "brackets")):
        a, b = code.count(op), code.count(cl)
        if a != b:
            errs.append(f"unbalanced {name} ({a} open, {b} close)")

    # Alternative syntax used heavily in templates.
    pairs = (
        (r"\bif\s*\([^;{]*\)\s*:", r"\bendif\b", "if/endif"),
        (r"\bforeach\s*\([^;{]*\)\s*:", r"\bendforeach\b", "foreach/endforeach"),
        (r"\bwhile\s*\([^;{]*\)\s*:", r"\bendwhile\b", "while/endwhile"),
        (r"\bfor\s*\([^;{]*\)\s*:", r"\bendfor\b", "for/endfor"),
        (r"\bswitch\s*\([^;{]*\)\s*:", r"\bendswitch\b", "switch/endswitch"),
    )
    for start_re, end_re, name in pairs:
        a = len(re.findall(start_re, code))
        b = len(re.findall(end_re, code))
        if a != b:
            errs.append(f"{name} mismatch ({a} vs {b})")

    return errs


def main():
    files = []
    for base, dirs, names in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ("node_modules", ".git")]
        files += [os.path.join(base, f) for f in names if f.endswith(".php")]

    failures = 0
    for path in sorted(files):
        errs = check(path)
        if errs:
            failures += 1
            print(f"  \u2717 {os.path.relpath(path, ROOT)}")
            for e in errs:
                print(f"      {e}")

    print(f"checked {len(files)} PHP files")
    if failures:
        print(f"  {failures} file(s) with issues")
        return 1
    print("  \u2713 all structural checks pass")
    return 0


if __name__ == "__main__":
    sys.exit(main())
