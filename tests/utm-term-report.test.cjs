const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const test = require('node:test');
const html = fs.readFileSync('docs/index.html', 'utf8');
const helper = html.slice(html.indexOf('function utmReportKey('), html.indexOf('async function renderUtmField('));
const render = html.slice(html.indexOf('async function renderUtmField('), html.indexOf('/* ---------------- CAMPAIGNS ---------------- */'));
async function run(field, deals, ads, sourceFilter = '') {
  let table;
  const c = { innerHTML: '' };
  const query = new Proxy({}, { get: (_, k) => () => query });
  const ctx = { Map, Number, console, filters: { source_filter: sourceFilter },
    $: () => c, escapeHtml: String, loadingHTML: () => '',
    aggViaRPC: async () => structuredClone(deals), adsBaseRange: () => ({fromD:'2026-11-14',toD:'2026-11-14'}),
    sb: { from: () => query }, narrowAdsByUtm: x => x, narrowAdsByProject: x => x,
    fetchAllAdsPaged: async () => ads, doughnutChart: () => {}, barChart: () => {},
    fmtNum: String, fmtMoney: String, fmtPct: String, errorHTML: e => { throw e; },
    buildTable: (_, options) => { table = options; }
  };
  vm.createContext(ctx); vm.runInContext(helper + render, ctx);
  await ctx.renderUtmField(field, '🔑 Виконавець', 'executor.csv');
  assert.ok(table, 'table rendered');
  return JSON.parse(JSON.stringify(table.rows));
}
const deal = (key, leads, paid, sum) => ({key,leads,paid,sum,fail:0,pending:leads-paid,buyers:paid,conv:paid/leads*100,avg:sum/paid,traffic_type:'💸 Платний'});
test('screenshot regression: CRM lowercase and spend uppercase yield one correct row', async () => {
 const rows=await run('utm_term',[deal('gpt_vadym',14,12,5888)],[{utm_term:'GPT_VADYM',spend:4120}]);
 assert.equal(rows.length,1); const r=rows[0];
 assert.equal(r.key,'gpt_vadym'); assert.equal(r.sum,5888); assert.equal(r.spend,4120); assert.equal(r.profit,1768);
 assert.equal(r.roas,5888/4120); assert.equal(r.roi,1768/4120*100); assert.equal(r.cpa,4120/12);
});
test('multiple casing variants on both sides conserve all totals and recompute ratios',async()=>{
 const rows=await run('utm_term',[deal('GPT_VADYM',2,1,299),deal('gpt_vadym',3,2,998),deal('Other',1,1,100)],[{utm_term:'Gpt_Vadym',spend:200},{utm_term:'GPT_VADYM',spend:300},{utm_term:'Other',spend:20}]);
 assert.equal(rows.length,2); const r=rows.find(r=>r.key==='gpt_vadym');
 assert.equal(r.leads,5);assert.equal(r.paid,3);assert.equal(r.sum,1297);assert.equal(r.spend,500);assert.equal(r.profit,797);assert.equal(r.avg,1297/3);assert.equal(r.conv,60);assert.equal(r.buyers,null);
 assert.equal(rows.reduce((n,r)=>n+r.sum,0),1397);assert.equal(rows.reduce((n,r)=>n+r.spend,0),520);
});
test('case-insensitive join respects both-sides filter and preserves loss/organic/missing rows',async()=>{
 const deals=[deal('gpt_vadym',1,1,100),deal('Organic',2,1,50)];
 const ads=[{utm_term:'GPT_VADYM',spend:120},{utm_term:'SpendOnly',spend:30},{utm_term:null,spend:10}];
 const rows=await run('utm_term',deals,ads);assert.equal(rows.length,4);assert.equal(rows.find(r=>r.key==='gpt_vadym').profit,-20);assert.equal(rows.find(r=>r.key==='spendonly').profit,-30);assert.equal(rows.find(r=>r.key==='organic').spend,0);assert.ok(rows.find(r=>r.key==='(none)'));
 assert.equal((await run('utm_term',deals,ads,'both')).length,1);
 assert.equal((await run('utm_term',deals,ads,'crm')).length,1);
 assert.equal((await run('utm_term',deals,ads,'ads')).length,2);
});
test('campaign/content identities retain case-sensitive existing behavior',async()=>{
 for(const field of ['utm_campaign','utm_content']){
 const rows=await run(field,[deal('Case',1,1,100)],[{[field]:'case',spend:20}]);assert.equal(rows.length,2);assert.equal(rows[0].spend,0);
 }
});
