"""The site's Markdown pages: docs/TECHNICAL.md -> docs/technical.html, docs/INDEX.md -> docs/contents.html.

    python3 tools/md2page.py

A small converter for the Markdown these two files use (headings, paragraphs, lists, tables, fenced
code, images, links, bold, italics, code), wrapped in the site's header and stylesheet. Links to other
Markdown pages become their HTML pages; links into the repository (../src/...) go to GitHub, since
GitHub Pages serves only docs/. No dependencies.
"""
import html
import os
import re

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
DOCS = os.path.join(ROOT, "docs")
REPO = "https://github.com/vonglurt/parliamentchess"
PAGES = {"TECHNICAL.md": ("technical.html", "How it works", "Technical"), "INDEX.md": ("contents.html", "Contents", "Contents")}
MD_TO_HTML = {"TECHNICAL.md": "technical.html", "INDEX.md": "contents.html", "parliament-chess-lab-report.md": "parliament-chess-lab-report.html"}


def slug(text):
    s = re.sub(r"<[^>]+>", "", text).strip().lower()
    s = re.sub(r"[^\w\- ]", "", s)
    return s.replace(" ", "-")


def href(u):
    if re.match(r"^(https?:|mailto:|#)", u):
        return u
    path, _, frag = u.partition("#")
    if path in MD_TO_HTML:
        return MD_TO_HTML[path] + ("#" + frag if frag else "")
    if path in (".", "./"):
        return f"{REPO}/tree/main/docs"
    if path.startswith("../"):
        rel = os.path.normpath(path[3:])
        kind = "tree" if os.path.isdir(os.path.join(ROOT, rel)) else "blob"
        return f"{REPO}/{kind}/main/{rel}" + ("#" + frag if frag else "")
    if os.path.isdir(os.path.join(DOCS, path)):
        return f"{REPO}/tree/main/docs/{os.path.normpath(path)}"
    return u


def inline(s):
    codes = []

    def keep(m):  # code spans are set aside first, so nothing inside them is formatted
        codes.append(f"<code>{html.escape(m.group(1))}</code>")
        return f"\x00{len(codes) - 1}\x00"
    t = html.escape(re.sub(r"`([^`]+)`", keep, s), quote=False)
    t = re.sub(r"!\[([^\]]*)\]\(([^)]+)\)", lambda m: f'<img src="{href(m.group(2))}" alt="{m.group(1)}">', t)
    t = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", lambda m: f'<a href="{href(m.group(2))}">{m.group(1)}</a>', t)
    t = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", t)
    t = re.sub(r"(?<![\w*])\*([^*\n]+)\*(?![\w*])", r"<i>\1</i>", t)
    return re.sub(r"\x00(\d+)\x00", lambda m: codes[int(m.group(1))], t)


def convert(md):
    lines = md.split("\n")
    out, i, title = [], 0, ""
    while i < len(lines):
        ln = lines[i]
        if ln.startswith("```"):
            j = i + 1
            while j < len(lines) and not lines[j].startswith("```"):
                j += 1
            out.append("<pre><code>" + html.escape("\n".join(lines[i + 1:j])) + "</code></pre>")
            i = j + 1
            continue
        m = re.match(r"^(#{1,6}) (.*)$", ln)
        if m:
            n, text = len(m.group(1)), m.group(2)
            if n == 1 and not title:
                title = text
            out.append(f'<h{n} id="{slug(text)}">{inline(text)}</h{n}>')
            i += 1
            continue
        if ln.strip() == "---":
            out.append("<hr>")
            i += 1
            continue
        if ln.startswith("|"):
            rows = []
            while i < len(lines) and lines[i].startswith("|"):
                rows.append([c.strip() for c in lines[i].strip().strip("|").split("|")])
                i += 1
            head, body = rows[0], [r for r in rows[1:] if not all(re.match(r"^:?-+:?$", c) for c in r)]
            t = ['<div class="table-wrap"><table>', "<tr>" + "".join(f"<th>{inline(c)}</th>" for c in head) + "</tr>"]
            t += ["<tr>" + "".join(f"<td>{inline(c)}</td>" for c in r) + "</tr>" for r in body]
            out.append("\n".join(t) + "</table></div>")
            continue
        m = re.match(r"^(\s*)(-|\d+\.) (.*)$", ln)
        if m:
            ordered = m.group(2) != "-"
            items = []
            while i < len(lines):
                mm = re.match(r"^(\s*)(-|\d+\.) (.*)$", lines[i])
                if mm:
                    items.append(mm.group(3))
                elif lines[i].startswith("  ") and lines[i].strip() and items:
                    items[-1] += " " + lines[i].strip()
                else:
                    break
                i += 1
            tag = "ol" if ordered else "ul"
            out.append(f"<{tag}>" + "".join(f"<li>{inline(x)}</li>" for x in items) + f"</{tag}>")
            continue
        if not ln.strip():
            i += 1
            continue
        para = [ln]
        i += 1
        while i < len(lines) and lines[i].strip() and not re.match(r"^(#|```|\||\s*(-|\d+\.) |---)", lines[i]):
            para.append(lines[i])
            i += 1
        text = " ".join(p.strip() for p in para)
        if re.fullmatch(r"!\[[^\]]*\]\([^)]+\)", text):
            out.append(f'<figure class="chart">{inline(text)}</figure>')
        else:
            out.append(f"<p>{inline(text)}</p>")
    return title, "\n".join(out)


NAV = [("guide.html", "Play"), ("about.html", "The project"), ("net.html", "The net"), ("technical.html", "Technical"), ("contents.html", "Contents"),
       (REPO, "GitHub")]


def page(name, md):
    out_name, title, nav_on = PAGES[name]
    t, body = convert(md)
    nav = "\n".join(f'  <a class="nav{" on" if label == nav_on else ""}" href="{u}">{label}</a>' for u, label in NAV)
    return f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{html.escape(title)} · Parliament Chess</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='12' fill='%230f2c24'/%3E%3Ctext x='32' y='47' font-size='42' text-anchor='middle' fill='%23d4a62a'%3E%E2%99%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="site.css">
<style>main h1 {{ margin-top: 44px; }} main h2 {{ margin-top: 44px; }}</style>
</head>
<body>
<header class="top"><nav>
  <a class="brand" href="./"><span>&#9818;&#xFE0E;</span>Parliament Chess</a>
{nav}
</nav></header>
<main>
<!-- Made from docs/{name} by tools/md2page.py: edit the Markdown, then run the script. -->
{body}
<footer><span><a href="./">Parliament Chess</a> &middot; this page is <a href="{REPO}/blob/main/docs/{name}">docs/{name}</a> on GitHub</span><span>MIT License</span></footer>
</main>
</body>
</html>
"""


if __name__ == "__main__":
    for name, (out_name, _, _) in PAGES.items():
        src = os.path.join(DOCS, name)
        open(os.path.join(DOCS, out_name), "w").write(page(name, open(src).read()))
        print(f"docs/{name} -> docs/{out_name}")
