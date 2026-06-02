#!/usr/bin/env python3
"""
Fix: пропустити customer_type / tariff / pay_provider у RPC calls
"""
import re, sys, pathlib

p = pathlib.Path(__file__).parent / 'docs' / 'index.html'
if not p.exists():
    print('docs/index.html not found'); sys.exit(1)

s = p.read_text()
orig_len = len(s)

new_rpc_params = '''function _rpcParams() {
  const sp = getSelectedProject();
  let fromDate = filters.from, toDate = filters.to;
  let projectValues = null;
  if (sp) {
    if (sp.date_start > fromDate) fromDate = sp.date_start;
    if (sp.date_end < toDate) toDate = sp.date_end;
    if (sp.deal_project_values && sp.deal_project_values.length) projectValues = sp.deal_project_values;
  } else if (filters.project && filters.project.startsWith('raw::')) {
    projectValues = [filters.project.slice(5)];
  }
  return {
    p_from: fromDate + 'T00:00:00Z',
    p_to: toDate + 'T23:59:59Z',
    p_project_values: projectValues,
    p_customer_type: filters.customer_type || null,
    p_tariff: filters.tariff || null,
    p_pay_provider: filters.pay_provider || null
  };
}'''

if 'p_customer_type: filters.customer_type' in s:
    print('  ok _rpcParams already has filters')
else:
    pat = re.compile(r"function _rpcParams\(\) \{[\s\S]*?\n\}", re.MULTILINE)
    m = pat.search(s)
    if not m:
        print('FAIL: _rpcParams not found'); sys.exit(1)
    s = s[:m.start()] + new_rpc_params + s[m.end():]
    print('  ok _rpcParams patched')

# Add p_traffic_type to aggViaRPC params
old_agg = '''async function aggViaRPC(field) {
  const params = { p_field: field, ..._rpcParams() };
  const { data, error } = await sb.rpc(\'dashboard_agg_deals_with_traffic\', params);'''
new_agg = '''async function aggViaRPC(field) {
  const params = { p_field: field, ..._rpcParams(), p_traffic_type: filters.traffic_type || null };
  const { data, error } = await sb.rpc(\'dashboard_agg_deals_with_traffic\', params);'''
if 'p_traffic_type: filters.traffic_type' in s:
    print('  ok aggViaRPC already has traffic_type')
elif old_agg in s:
    s = s.replace(old_agg, new_agg, 1)
    print('  ok aggViaRPC patched')

p.write_text(s)
print(f'\nDONE. {orig_len} -> {len(s)} bytes (+{len(s)-orig_len})')
print('\nNext: git add docs/index.html && git commit -m "fix(dashboard): pass all filters to RPC" && git push')
