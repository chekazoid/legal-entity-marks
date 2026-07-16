# -*- coding: utf-8 -*-
"""Обновление встроенных реестров legal-entity-marks.
Повторяет логику LEM_Importer (fetch_inoagents / fetch_undesirable_orgs /
parse_minjust_list / parse_generic_list), сохраняя курированные алиасы
из текущих bundled-файлов."""
import json, os, re, ssl, urllib.request, html as htmllib
from html.parser import HTMLParser

DATA = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'data')
CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE

def norm_key(name):
    s = re.sub(r'[«»"""\'“”]', '', name.lower())
    return re.sub(r'\s+', ' ', s).strip()

def load_existing(fname):
    with open(f'{DATA}/{fname}', encoding='utf-8') as f:
        return json.load(f)

def alias_map(entries):
    m = {}
    for e in entries:
        k = norm_key(e['name'])
        m.setdefault(k, set()).update(e.get('aliases') or [])
    return m

def distinctive(term, min_cyr_len=None):
    """Однословные чисто кириллические алиасы без дефиса - частые ложные
    срабатывания (ГОЛОС, Таганрог, Агентство). Берем только отличительные."""
    if re.search(r'[\sA-Za-z0-9-]', term):
        return True
    return min_cyr_len is not None and len(term) >= min_cyr_len

def gen_org_aliases(name):
    """Алиасы: содержимое «...» и имя без внешних кавычек."""
    out = []
    for q in re.findall(r'«([^«»]{5,})»', name):
        q = q.strip()
        if q and norm_key(q) != norm_key(name) and distinctive(q):
            out.append(q)
    stripped = re.sub(r'^[«"]+|[»"]+$', '', name).strip()
    if stripped != name and len(stripped) >= 5:
        out.append(stripped)
    return out

def gen_person_alias(name):
    """Псевдонимы из кавычек (в т.ч. незакрытых) + 'Фамилия Имя'.
    Зеркалит LEM_Importer::fetch_inoagents."""
    out = []
    m = re.search(r'[«"](.+?)[»"]?$', name)
    if m:
        for pseudo in re.split(r'[()«»"]+', m.group(1)):
            pseudo = pseudo.strip(' \t,;')
            if len(pseudo) >= 3 and distinctive(pseudo, min_cyr_len=6):
                out.append(pseudo)
    clean = re.sub(r'\s*[«"].*$', ' ', name)
    parts = clean.split()
    if len(parts) >= 2:
        out.append(f'{parts[0]} {parts[1]}')
    return out

def merged_aliases(generated, curated, name):
    seen, out = {norm_key(name)}, []
    for a in list(generated) + sorted(curated):
        k = norm_key(a)
        if k and k not in seen:
            seen.add(k)
            out.append(a)
    return out

def rest_fetch(grid):
    url = f'https://reestrs.minjust.gov.ru/rest/registry/{grid}/values'
    items, offset, limit = [], 0, 500
    while True:
        req = urllib.request.Request(url,
            data=json.dumps({'offset': offset, 'limit': limit, 'search': ''}).encode(),
            headers={'Content-Type': 'application/json'})
        with urllib.request.urlopen(req, context=CTX, timeout=60) as r:
            data = json.load(r)
        vals = data.get('values', [])
        items.extend(vals)
        if len(vals) < limit or offset + limit >= data.get('size', 0):
            break
        offset += limit
    return items

# ---------------- Иноагенты ----------------
def update_inoagents():
    curated = alias_map(load_existing('foreign-agents-raw.json'))
    entries, seen = [], set()
    for item in rest_fetch('39b95df9-9a68-6b6d-e1e3-e6388507067e'):
        name = (item.get('field_2_s') or '').strip()
        if len(name) < 3:
            continue
        # срез декоративных кавычек только у имён, начинающихся с кавычки (зеркалит плагин)
        if re.match(r'^[«"]', name):
            name = re.sub(r'^[«"]+|[»"]+$', '', name).strip()
        k = norm_key(name)
        if k in seen:
            continue
        seen.add(k)
        is_person = 1 if item.get('field_7_s') == 'Физические лица' else 0
        gen = gen_person_alias(name) if is_person else gen_org_aliases(name)
        e = {'name': name, 'type': 'inoagent',
             'aliases': merged_aliases(gen, curated.get(k, set()), name),
             'is_person': is_person}
        if item.get('field_4_s'):
            e['dateIn'] = item['field_4_s']
        if item.get('field_5_s'):
            e['dateOut'] = item['field_5_s']
        entries.append(e)
    return entries

# ---------------- Нежелательные ----------------
def update_undesirable():
    curated = alias_map(load_existing('undesirable-orgs.json'))
    entries, seen = [], set()
    for item in rest_fetch('c2d1692e-a9f6-5a79-13ee-5da5b42980df'):
        name = (item.get('field_5_s') or '').strip()
        if not (5 <= len(name) <= 500):
            continue
        k = norm_key(name)
        if k in seen:
            continue
        seen.add(k)
        e = {'name': name, 'type': 'undesirable',
             'aliases': merged_aliases(gen_org_aliases(name), curated.get(k, set()), name),
             'is_person': False}
        if item.get('field_2_s'):
            e['dateIn'] = item['field_2_s']
        if item.get('field_10_s') == 'Исключена':
            e['dateOut'] = item.get('field_8_s') or ''
        entries.append(e)
    return entries

# ---------------- HTML-парсинг ----------------
class TextExtractor(HTMLParser):
    """Собирает тексты узлов по простым правилам."""
    def __init__(self):
        super().__init__()
        self.paras, self.cells = [], []
        self._stack = []          # открытые контейнеры
        self._buf = None
        self._td_index = 0
        self._in_doc = 0

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'div' and 'doc' in (a.get('class') or '').split():
            self._in_doc += 1
        if tag == 'p' and self._in_doc:
            self._buf = []
            self._stack.append('p')
        if tag == 'tr':
            self._td_index = 0
        if tag == 'td':
            self._td_index += 1
            if self._td_index == 2:
                self._buf = []
                self._stack.append('td2')

    def handle_endtag(self, tag):
        if tag == 'p' and self._stack and self._stack[-1] == 'p':
            self._stack.pop()
            self.paras.append(' '.join(''.join(self._buf).split()))
            self._buf = None
        if tag == 'td' and self._stack and self._stack[-1] == 'td2':
            self._stack.pop()
            self.cells.append(' '.join(''.join(self._buf).split()))
            self._buf = None
        if tag == 'div' and self._in_doc and not self._stack:
            pass

    def handle_data(self, data):
        if self._buf is not None:
            self._buf.append(data)

def fetch_html(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 (compatible; LegalEntityMarksBot/1.0)'})
    with urllib.request.urlopen(req, context=CTX, timeout=60) as r:
        return r.read().decode('utf-8', errors='replace')

def clean_list_text(text, generic=False):
    text = re.sub(r'^\d+[\.\)]\s*', '', text).strip()
    if len(text) < 5:
        return None
    if re.match(r'^Наименование\s+организации', text, re.I):
        return None
    if generic:
        name = re.split(r'\s*[-–—]\s*(?:решени|призна|Верх|на основании)', text, flags=re.I)[0]
        name = re.sub(r'\s*\((?:решени|призна).*$', '', name, flags=re.I)
    else:
        name = re.split(r'\s*[-–—]\s*(?:решени|на основании|Верх|Реш)', text, flags=re.I)[0]
        name = re.sub(r'\s*\(.*$', '', name)
    name = name.strip()
    if re.match(r'^(Решение|Приговор|Определение|Постановление)\b', name):
        return None
    if len(name) < 5 or (generic and len(name) >= 300):
        return None
    return name

def update_from_html(url, typ, existing_file, generic):
    existing = load_existing(existing_file)
    curated = alias_map(existing)
    ex = TextExtractor()
    ex.feed(fetch_html(url))
    nodes = ex.cells if generic else ex.paras
    entries, seen = [], set()
    for raw in nodes:
        name = clean_list_text(htmllib.unescape(raw), generic)
        if not name:
            continue
        k = norm_key(name)
        if k in seen:
            continue
        seen.add(k)
        entries.append({'name': name, 'type': typ,
                        'aliases': merged_aliases(gen_org_aliases(name), curated.get(k, set()), name),
                        'is_person': False})
    # сохраняем прежние записи, не найденные при новом парсинге (кроме мусора старого парсера)
    kept = 0
    for e in existing:
        k = norm_key(e['name'])
        n = e['name']
        is_noise = (re.match(r'^[а-яё]', n) or
                    re.match(r'^(Решение|Приговор|Определение|Постановление)\b', n))
        if k not in seen and not is_noise:
            seen.add(k)
            entries.append(e)
            kept += 1
    return entries, kept

def save(fname, entries):
    with open(f'{DATA}/{fname}', 'w', encoding='utf-8') as f:
        json.dump(entries, f, ensure_ascii=False, indent=2)
        f.write('\n')

if __name__ == '__main__':
    ino = update_inoagents()
    save('foreign-agents-raw.json', ino)
    active = sum(1 for e in ino if not e.get('dateOut'))
    print(f'иноагенты: {len(ino)} всего, {active} активных')

    und = update_undesirable()
    save('undesirable-orgs.json', und)
    active = sum(1 for e in und if not e.get('dateOut'))
    print(f'нежелательные: {len(und)} всего, {active} активных')

    extr, kept = update_from_html('https://minjust.gov.ru/ru/documents/7822/', 'extremist', 'extremist-orgs.json', generic=False)
    save('extremist-orgs.json', extr)
    print(f'экстремистские: {len(extr)} (из них сохранено старых: {kept})')

    terr, kept = update_from_html('http://www.fsb.ru/fsb/npd/terror.htm', 'terrorist', 'terrorist-orgs.json', generic=True)
    save('terrorist-orgs.json', terr)
    print(f'террористические: {len(terr)} (из них сохранено старых: {kept})')
