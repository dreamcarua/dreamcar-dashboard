#!/usr/bin/env python3
"""
Apply dashboard_projects refactor to docs/index.html

Replaces hardcoded project list with dynamic loader from dashboard_projects table.
When user selects a project, auto-fills date range from project.date_start..date_end
and filters deals by project.deal_project_values array.

Run from ~/DreamCar.AI/dashboard-dreamcar:
    python3 apply_projects_patch.py
    git add docs/index.html
    git commit -m "feat(dashboard): projects from dashboard_projects table"
    git push
"""
import re, sys, pathlib

p = pathlib.Path(__file__).parent / 'docs' / 'index.html'
if not p.exists():
    print('docs/index.html not found')
    sys.exit(1)

s = p.read_text()
orig_len = len(s)

# Patch 1: Add __projectsList after MAIN_PROJECTS
old1 = "const MAIN_PROJECTS = ['OLD','VOLVO','Q7','Mercedes','DreamCar AI','AUDI E-TRON','BMW','Контакт'];"
new1 = old1 + "\nlet __projectsList = []; // cached from dashboard_projects table"
if '__projectsList' in s:
    print('  ok patch 1 already applied')
else:
    if old1 not in s:
        print('FAIL patch 1: anchor not found'); sys.exit(1)
    s = s.replace(old1, new1, 1)
    print('  ok patch 1 applied')

# Patch 2: Rewrite loadProjects()
if 'getSelectedProject()' in s and '_fmtProjDate' in s:
    print('  ok patch 2 already applied')
else:
    old2_pattern = re.compile(
        r"async function loadProjects\(\) \{[\s\S]*?\n\}\s*\n\nasync function loadTariffs",
        re.MULTILINE
    )
    new2 = """function _fmtProjDate(s) {
  if (!s) return '';
  const [y,m,d] = s.split('-');
  return `${d}.${m}.${y.slice(2)}`;
}

async function loadProjects() {
  try {
    const { data: projData } = await sb
      .from('dashboard_projects')
      .select('*')
      .order('sort_order', { ascending: true });
    __projectsList = projData || [];

    const { data: dealProjData } = await sb
      .from('dashboard_deals')
      .select('project')
      .not('project','is',null)
      .limit(5000);
    const dealProjSet = new Set();
    (dealProjData || []).forEach(r => { if (r.project) dealProjSet.add(r.project); });

    const officialValues = new Set();
    __projectsList.forEach(p => (p.deal_project_values || []).forEach(v => officialValues.add(v)));
    const others = Array.from(dealProjSet).filter(p => !officialValues.has(p)).sort((a,b) => a.localeCompare(b));

    const sel = $('#f-project');
    const cur = filters.project;
    const mainOpts = __projectsList.map(p => {
      const dr = `${_fmtProjDate(p.date_start)}-${_fmtProjDate(p.date_end)}`;
      return `<option value="${escapeHtml(p.code)}">${escapeHtml(p.name)} (${dr})</option>`;
    }).join('');
    sel.innerHTML =
      '<option value="">- Усі проекти -</option>' +
      (mainOpts ? `<optgroup label="Основні проекти">${mainOpts}</optgroup>` : '') +
      (others.length ? '<optgroup label="Інші">' +
        others.map(p => `<option value="raw::${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('') +
      '</optgroup>' : '');
    if (cur) sel.value = cur;
  } catch (e) { console.warn('projects load failed', e); }
}

function getSelectedProject() {
  if (!filters.project) return null;
  if (filters.project.startsWith('raw::')) return null;
  return __projectsList.find(p => p.code === filters.project) || null;
}

async function loadTariffs"""
    s, n = old2_pattern.subn(new2, s, 1)
    if n == 0:
        print('FAIL patch 2: regex did not match'); sys.exit(1)
    print('  ok patch 2 applied')

# Patch 3: #f-project change handler auto-fills date range
old3 = "$('#f-project').addEventListener('change', e => { filters.project = e.target.value; });"
new3 = """$('#f-project').addEventListener('change', e => {
    filters.project = e.target.value;
    const sp = getSelectedProject();
    if (sp) {
      filters.from = sp.date_start;
      filters.to = sp.date_end;
      const cf = $('#cd-from'); if (cf) cf.value = sp.date_start;
      const ct = $('#cd-to'); if (ct) ct.value = sp.date_end;
      const dp = $('#date-preset'); if (dp) dp.value = 'custom';
    }
    renderRoute();
  });"""
if 'getSelectedProject();\n    if (sp) {\n      filters.from' in s:
    print('  ok patch 3 already applied')
elif old3 not in s:
    print('FAIL patch 3: anchor not found'); sys.exit(1)
else:
    s = s.replace(old3, new3, 1)
    print('  ok patch 3 applied')

# Patch 4: fetchDealsRange — use deal_project_values + date intersection
old4 = """async function fetchDealsRange(offset, limit) {
  let q = sb.from('dashboard_deals')
    .select('id,sendpulse_deal_id,status,amount,currency,project,utm_source,utm_medium,utm_campaign,utm_term,utm_content,customer_email,customer_type,tariff,pay_provider,created_at,paid_at')
    .gte('created_at', filters.from + 'T00:00:00Z')
    .lte('created_at', filters.to + 'T23:59:59Z')
    .order('created_at', { ascending: false })
    .range(offset, offset + limit - 1);
  if (filters.project) q = q.eq('project', filters.project);
  if (filters.status) q = q.eq('status', filters.status);
  if (filters.customer_type) q = q.eq('customer_type', filters.customer_type);
  if (filters.tariff) q = q.eq('tariff', filters.tariff);
  if (filters.pay_provider) q = q.eq('pay_provider', filters.pay_provider);
  // Active model — narrow project by substring
  const m = getActiveModel();
  if (m && m.match) q = q.ilike('project', '%' + m.match + '%');
  return q;
}"""
new4 = """async function fetchDealsRange(offset, limit) {
  const sp = getSelectedProject();
  let fromDate = filters.from, toDate = filters.to;
  if (sp) {
    if (sp.date_start > fromDate) fromDate = sp.date_start;
    if (sp.date_end < toDate) toDate = sp.date_end;
  }
  let q = sb.from('dashboard_deals')
    .select('id,sendpulse_deal_id,status,amount,currency,project,utm_source,utm_medium,utm_campaign,utm_term,utm_content,customer_email,customer_type,tariff,pay_provider,created_at,paid_at')
    .gte('created_at', fromDate + 'T00:00:00Z')
    .lte('created_at', toDate + 'T23:59:59Z')
    .order('created_at', { ascending: false })
    .range(offset, offset + limit - 1);
  if (sp) {
    if (sp.deal_project_values && sp.deal_project_values.length) {
      q = q.in('project', sp.deal_project_values);
    }
  } else if (filters.project && filters.project.startsWith('raw::')) {
    q = q.eq('project', filters.project.slice(5));
  }
  if (filters.status) q = q.eq('status', filters.status);
  if (filters.customer_type) q = q.eq('customer_type', filters.customer_type);
  if (filters.tariff) q = q.eq('tariff', filters.tariff);
  if (filters.pay_provider) q = q.eq('pay_provider', filters.pay_provider);
  const m = getActiveModel();
  if (m && m.match && !sp) q = q.ilike('project', '%' + m.match + '%');
  return q;
}"""
if 'const sp = getSelectedProject();\n  let fromDate' in s:
    print('  ok patch 4 already applied')
elif old4 not in s:
    print('FAIL patch 4: anchor not found'); sys.exit(1)
else:
    s = s.replace(old4, new4, 1)
    print('  ok patch 4 applied')

p.write_text(s)
print(f'\nDONE. {orig_len} -> {len(s)} bytes (+{len(s)-orig_len})')
print('Next:')
print('  git add docs/index.html')
print('  git commit -m "feat(dashboard): projects from dashboard_projects table"')
print('  git push')
