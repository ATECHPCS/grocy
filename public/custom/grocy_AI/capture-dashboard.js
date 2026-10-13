(function () {
 'use strict';
 function keys(value, expected) { return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).sort().join(',') === expected.slice().sort().join(','); }
 function id(value) { return Number.isSafeInteger(value) && value > 0 && value <= 9999999999; }
 function count(value) { return Number.isSafeInteger(value) && value >= 0; }
 function date(value) { return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/.test(value) && Number.isFinite(Date.parse(value)); }
 function section(value, completed) {
  return keys(value, ['sessions','next_before_id']) && Array.isArray(value.sessions) && value.sessions.length <= 25 && value.sessions.every(function (s) { return keys(s,['id','status','created_at','committed_at','item_count','receipt_count']) && id(s.id) && (completed ? s.status === 'committed' : ['open','reviewing'].indexOf(s.status) !== -1) && (s.created_at === null || date(s.created_at)) && (s.committed_at === null || date(s.committed_at)) && count(s.item_count) && count(s.receipt_count); }) && value.sessions.every(function (s,i,a) { return i === 0 || s.id < a[i-1].id; }) && (value.next_before_id === null || id(value.next_before_id) && value.sessions.length > 0 && value.next_before_id === value.sessions[value.sessions.length-1].id);
 }
 function valid(data) {
  var counts=['open_sessions','reviewing_sessions','completed_sessions_30d','receipts_with_total_30d','receipts_missing_total_30d','completed_without_receipts_30d'];
  return keys(data,['contract_version','period_start','period_end','stats','active','completed']) && data.contract_version === 1 && date(data.period_start) && date(data.period_end) && Date.parse(data.period_start) <= Date.parse(data.period_end) && keys(data.stats,counts.concat(['receipt_printed_total_30d'])) && counts.every(function (name) { return count(data.stats[name]); }) && (data.stats.receipt_printed_total_30d === null || typeof data.stats.receipt_printed_total_30d === 'number' && Number.isFinite(data.stats.receipt_printed_total_30d) && data.stats.receipt_printed_total_30d >= 0) && section(data.active,false) && section(data.completed,true);
 }
 function attach() {
  var root=document.getElementById('grocyai-capture-dashboard'); if (!root) return;
  function el(tag, text, cls) { var node=document.createElement(tag); if (text !== undefined) node.textContent=text; if (cls) node.className=cls; return node; }
  function get(name) { return document.getElementById('grocyai-dashboard-'+name); }
  function text(name, fallback) { return root.getAttribute('data-label-'+name) || fallback; }
  var newButton=get('new'), refresh=get('refresh'), status=get('status'), creating=false, ambiguous=false, loading=false, generation=0;
  var sections={active:{sessions:[],cursor:null,busy:false},completed:{sessions:[],cursor:null,busy:false}};
  function request(url,options) { return fetch(url,Object.assign({credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Content-Type':'application/json'}},options||{})).then(function (response) { if(!response.ok) throw new Error('http'); return response.json(); }); }
  function url(base, trip) { var parsed=new URL(base,window.location.href); parsed.searchParams.set('trip',String(trip)); return parsed.pathname+parsed.search+parsed.hash; }
  function renderSection(name) {
   var section=sections[name], list=get(name);list.textContent='';
   if(!section.sessions.length) list.appendChild(el('li',text(name+'-empty',name==='active'?'No active sessions. Choose New session to start.':'No completed purchases yet.')));
   section.sessions.forEach(function (s) {
    var item=el('li',undefined,'grocy-ai-dashboard-session'); var heading=el('h4',text('session','Session')+' #'+s.id); item.appendChild(heading);
    item.appendChild(el('p',text(s.status,s.status==='open'?'Open':s.status==='reviewing'?'Reviewing':'Completed')+' · '+(name==='completed'?text('created','Created')+': ':'')+(s.created_at===null?text('date-unavailable','Date unavailable'):new Date(s.created_at).toLocaleString())+' · '+s.item_count+' '+text('items','captured items')+' · '+s.receipt_count+' '+text('receipts','receipts')));
    if(name==='completed') item.appendChild(el('p',text('completed','Completed')+': '+(s.committed_at===null?text('date-unavailable','Date unavailable'):new Date(s.committed_at).toLocaleString())));
    var actions=el('div',undefined,'grocy-ai-flow-actions');
    if(s.status==='open') { var resume=el('a',text('resume','Resume'),'btn btn-outline-primary');resume.href=url(root.getAttribute('data-capture-url'),s.id);actions.appendChild(resume); }
    var review=el('a',text('review','Review'),'btn btn-outline-primary');review.href=url(root.getAttribute('data-review-url'),s.id);actions.appendChild(review);item.appendChild(actions);list.appendChild(item);
   });
   var more=get(name+'-more');more.hidden=section.cursor===null;more.disabled=loading||section.busy;
  }
  function renderStats(data) {
   var stats=get('stats');stats.textContent='';var s=data.stats, money=text('unavailable','Unavailable');
   if(s.receipt_printed_total_30d!==null) { var settings=window.Grocy||{},places=Number((settings.UserSettings||{}).stock_decimal_places_prices_display);if(!Number.isInteger(places)||places<0||places>20) places=2;try { money=s.receipt_printed_total_30d.toLocaleString(undefined,{style:'currency',currency:settings.Currency,minimumFractionDigits:places,maximumFractionDigits:places}); } catch(error) { money=s.receipt_printed_total_30d.toLocaleString(undefined,{minimumFractionDigits:places,maximumFractionDigits:places}); } }
   [[text('open-stats','Open sessions'),s.open_sessions],[text('review-stats','Sessions needing review'),s.reviewing_sessions],[text('completed-stats','Completed purchases · last 30 days'),s.completed_sessions_30d],[text('printed-stats','Printed receipt totals · last 30 days'),money]].forEach(function(card){var box=el('div',undefined,'grocy-ai-flow-card');box.appendChild(el('h3',card[0]));box.appendChild(el('p',String(card[1]),'grocy-ai-dashboard-stat-value'));stats.appendChild(box);});
   var notices=[];if(s.receipts_missing_total_30d) notices.push(s.receipts_missing_total_30d+' '+text('missing-totals','receipt(s) have no available printed total.'));if(s.completed_without_receipts_30d) notices.push(s.completed_without_receipts_30d+' '+text('missing-receipts','completed purchase(s) have no receipts.'));notices.push(text('total-guidance','Printed receipt totals cover available receipts only, including ignored items and adjustments. They are not a complete spending total.'));get('notices').textContent=notices.join(' ');
  }
  function load(name) {
   if(loading||creating||Object.keys(sections).some(function(key){return sections[key].busy;})) return;
   newButton.disabled=true;var revision=++generation, endpoint=new URL(root.getAttribute('data-dashboard-endpoint'),window.location.href);
   if(name) { sections[name].busy=true;endpoint.searchParams.set(name+'_before',String(sections[name].cursor));get(name+'-more').disabled=true; }
   else { loading=true;refresh.disabled=true;status.textContent=text('loading','Loading sessions…'); }
   return request(endpoint.href).then(function(data){
    if(revision!==generation) return;if(!valid(data)) throw new Error('contract');
    var names=name?[name]:['active','completed'];names.forEach(function(key){var target=sections[key], incoming=data[key];if(name&&incoming.next_before_id!==null&&incoming.next_before_id>=target.cursor) throw new Error('cursor');var seen={};target.sessions=(name?target.sessions:[]).concat(incoming.sessions).filter(function(s){if(seen[s.id])return false;seen[s.id]=true;return true;});target.cursor=incoming.next_before_id;});
    if(!name)renderStats(data);status.textContent=ambiguous?text('create-ambiguous','Session creation could not be confirmed. Refresh sessions and review any empty session before creating another.'):'';
   }).catch(function(){status.textContent=text('load-error','Could not load sessions. Refresh sessions to try again.');}).finally(function(){if(revision!==generation)return;loading=false;refresh.disabled=false;newButton.disabled=creating||ambiguous;Object.keys(sections).forEach(function(key){sections[key].busy=false;renderSection(key);});});
  }
  newButton.addEventListener('click',function(){
   if(creating||ambiguous||loading||Object.keys(sections).some(function(key){return sections[key].busy;}))return;creating=true;newButton.disabled=true;refresh.disabled=true;status.textContent=text('creating','Creating session…');
   request(root.getAttribute('data-trips-endpoint'),{method:'POST',body:'{}'}).then(function(trip){if(!trip||!id(trip.id)||trip.status!=='open')throw new Error('trip');window.location.assign(url(root.getAttribute('data-capture-url'),trip.id));}).catch(function(){ambiguous=true;status.textContent=text('create-ambiguous','Session creation could not be confirmed. Refresh sessions and review any empty session before creating another.');}).finally(function(){creating=false;refresh.disabled=false;newButton.disabled=true;});
  });
  refresh.addEventListener('click',function(){load();});Object.keys(sections).forEach(function(name){get(name+'-more').addEventListener('click',function(){if(sections[name].cursor!==null)load(name);});});load();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',attach);else attach();
})();
