from pathlib import Path

landing = Path(__file__).resolve().parents[1] / "public" / "landing-spa.php"
lines = landing.read_text(encoding="utf-8").splitlines(keepends=True)

start = end = None
for i, line in enumerate(lines):
    if '<body class="fvd-theme antialiased">' in line:
        start = i
    if start is not None and '<script type="text/x-template"' in line:
        end = i
        break
if start is None or end is None:
    raise SystemExit("body markers not found")

app_div = '    <motion id="app" class="landing-dynamic-root bg-white"></motion>\n'
app_div = app_div.replace("motion", "div")

new_body = [
    '<body class="fvd-theme antialiased">\n',
    "    <?php require __DIR__ . '/includes/landing_static_shell.php'; ?>\n",
    app_div,
    "\n",
]

text = "".join(lines[:start]) + "".join(new_body) + "".join(lines[end:])

tpl_start = text.find('<script type="text/x-template" id="landing-template">')
doc_marker = "            <!-- Documentos oficiales de dominó -->"
tpl_doc = text.find(doc_marker, tpl_start)
inner_start = text.find('<motion class="min-h-screen flex flex-col">', tpl_start)
if inner_start == -1:
    inner_start = text.find('<div class="min-h-screen flex flex-col">', tpl_start)
if tpl_doc == -1 or inner_start == -1:
    raise SystemExit("template trim markers not found")

text = text[:inner_start] + '        <div class="min-h-screen flex flex-col">\n' + text[tpl_doc:]
text = text.replace("        [v-cloak] { display: none !important; }\n", "")

vue_line = "    <script src=\"<?= htmlspecialchars($landing_asset_url('assets/vendor/vue/vue.global.prod.js')) ?>\"></script>"
inject = """    <script>
    (function () {
        var btn = document.getElementById('landing-mobile-menu-btn');
        var menu = document.getElementById('landing-mobile-menu');
        if (!btn || !menu) return;
        btn.addEventListener('click', function () {
            var open = menu.hasAttribute('hidden');
            if (open) {
                menu.removeAttribute('hidden');
                btn.setAttribute('aria-expanded', 'true');
            } else {
                menu.setAttribute('hidden', '');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    })();
    </script>
"""
if inject.strip() not in text:
    text = text.replace(vue_line, inject + vue_line, 1)

landing.write_text(text, encoding="utf-8")
print("ok")
