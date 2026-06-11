/* =====================================================================
 * CPTT Finance — admin JS, v6.4.0
 *
 * Lightweight: vanilla + tiny chart. Handles:
 *   - Currency input formatting
 *   - Debtor accordion (collapsible rows)
 *   - Receivables search filter
 *   - Income/Expense modal CRUD
 *   - Account CRUD + Transfers
 *   - Category CRUD
 *   - Monthly chart (custom canvas, no library)
 * ===================================================================== */
(function(){
	'use strict';

	function ready(fn){
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}
	function $(sel, c){ return (c||document).querySelector(sel); }
	function $$(sel, c){ return Array.prototype.slice.call((c||document).querySelectorAll(sel)); }

	var CFG = window.CPTT_FIN || { ajax:'', nonce:'', currency:'تومان' };

	function ajax(action, data, cb){
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce',  CFG.nonce);
		for (var k in data) if (Object.prototype.hasOwnProperty.call(data,k)) fd.append(k, data[k]);
		var xhr = new XMLHttpRequest();
		xhr.open('POST', CFG.ajax);
		xhr.onload = function(){
			var r = null;
			try { r = JSON.parse(xhr.responseText); } catch(e){}
			if (cb) cb(r);
		};
		xhr.onerror = function(){ if (cb) cb(null); };
		xhr.send(fd);
	}

	function fmt(n){ return Math.round(+n||0).toLocaleString('en-US'); }
	function parseMoney(v){ return parseFloat(String(v||'').replace(/[^\d.\-]/g, '')) || 0; }

	/* ───────── Currency input live formatting ───────── */
	function bindCurrencyInputs(){
		document.addEventListener('input', function(e){
			if (!e.target.classList || !e.target.classList.contains('cpttf-money')) return;
			var v = String(e.target.value || '').replace(/[^\d]/g, '');
			e.target.value = v ? fmt(v) : '';
		});
	}

	/* ───────── Debtor / Expert accordion ───────── */
	function bindAccordion(){
		document.addEventListener('click', function(e){
			var head = e.target.closest('.cpttf-debtor__head');
			if (!head) return;
			var li = head.closest('.cpttf-debtor');
			if (!li) return;
			var body = li.querySelector('.cpttf-debtor__projects');
			if (!body) return;
			var open = li.classList.contains('is-open');
			// close siblings
			$$('.cpttf-debtor.is-open', li.parentNode).forEach(function(o){
				if (o !== li){ o.classList.remove('is-open'); var b = o.querySelector('.cpttf-debtor__projects'); if (b) b.hidden = true; }
			});
			if (open){ li.classList.remove('is-open'); body.hidden = true; }
			else     { li.classList.add('is-open');    body.hidden = false; }
		});
	}

	/* ───────── Receivables search ───────── */
	function bindReceivablesSearch(){
		var input = $('#cpttf-recv-search');
		var list  = $('#cpttf-debtor-list');
		if (!input || !list) return;
		input.addEventListener('input', function(){
			var q = input.value.toLowerCase().trim();
			$$('.cpttf-debtor', list).forEach(function(li){
				var hay = (li.getAttribute('data-search') || '').toLowerCase();
				li.style.display = (!q || hay.indexOf(q) !== -1) ? '' : 'none';
			});
		});
	}

	/* ───────── Generic Modal helpers ───────── */
	function openModal(id){ var m = document.getElementById(id); if (m){ m.hidden = false; } }
	function closeModal(id){ var m = document.getElementById(id); if (m){ m.hidden = true; } }
	function bindCloseHandlers(){
		document.addEventListener('click', function(e){
			if (e.target.matches('[data-cpttf-close]')) {
				var m = e.target.closest('.cpttf-modal');
				if (m) m.hidden = true;
			}
		});
	}

	/* ───────── Transactions ───────── */
	window.cpttfOpenTx = function(type, id, prefill){
		var modal = $('#cpttf-tx-modal'); if (!modal) return;
		var form = $('#cpttf-tx-form');
		form.reset();
		form.querySelector('[name="id"]').value = id || 0;
		form.querySelector('[name="tx_type"]').value = type;
		$('#cpttf-tx-modal-title').textContent = type === 'income' ? '+ ثبت درآمد جدید' : '+ ثبت هزینه جدید';
		// filter category options by tx type
		var sel = form.querySelector('[name="category_id"]');
		if (sel) $$('optgroup', sel).forEach(function(og){ og.hidden = (og.dataset.tx !== type); });
		if (prefill){ for (var k in prefill){ var el = form.querySelector('[name="'+ k +'"]'); if (el) el.value = prefill[k]; } }
		openModal('cpttf-tx-modal');
	};
	function bindTxButtons(){
		document.addEventListener('click', function(e){
			var btn = e.target.closest('[data-cpttf-tx]');
			if (btn){ cpttfOpenTx(btn.dataset.cpttfTx); }

			var del = e.target.closest('[data-cpttf-tx-del]');
			if (del){
				if (!confirm('این تراکنش حذف شود؟')) return;
				ajax('cptt_fin_tx_delete', { id: del.dataset.cpttfTxDel }, function(r){
					if (r && r.success) location.reload();
					else alert((r && r.data) ? r.data : 'خطا');
				});
			}

			var sub = e.target.closest('[data-cpttf-submit="tx"]');
			if (sub){
				var form = $('#cpttf-tx-form');
				var data = {};
				new FormData(form).forEach(function(v,k){ data[k] = v; });
				sub.disabled = true; sub.textContent = '...';
				ajax('cptt_fin_tx_save', data, function(r){
					sub.disabled = false; sub.textContent = 'ذخیره';
					if (r && r.success) location.reload();
					else alert((r && r.data) ? r.data : 'خطا');
				});
			}
		});
	}

	/* ───────── Accounts ───────── */
	function bindAccountButtons(){
		document.addEventListener('click', function(e){
			var addBtn = e.target.closest('[data-cpttf-account="new"]');
			if (addBtn){ openAccountModal(); }
			var editBtn = e.target.closest('[data-cpttf-account="edit"]');
			if (editBtn){ openAccountModal(parseInt(editBtn.dataset.id, 10) || 0); }
			var delBtn = e.target.closest('[data-cpttf-account="delete"]');
			if (delBtn){
				if (!confirm('این حساب حذف یا غیرفعال شود؟ (در صورت داشتن تراکنش، فقط غیرفعال می‌شود)')) return;
				ajax('cptt_fin_account_delete', { id: delBtn.dataset.id }, function(r){
					if (r && r.success) location.reload();
					else alert((r && r.data) ? r.data : 'خطا');
				});
			}
			var transBtn = e.target.closest('[data-cpttf-transfer]');
			if (transBtn){ openModal('cpttf-tr-modal'); }

			var sub = e.target.closest('[data-cpttf-submit="account"]');
			if (sub){
				var form = $('#cpttf-acc-form');
				var data = {};
				new FormData(form).forEach(function(v,k){ data[k] = v; });
				sub.disabled = true; sub.textContent = '...';
				ajax('cptt_fin_account_save', data, function(r){
					sub.disabled = false; sub.textContent = 'ذخیره';
					if (r && r.success) location.reload();
					else alert((r && r.data) ? r.data : 'خطا');
				});
			}
			var subT = e.target.closest('[data-cpttf-submit="transfer"]');
			if (subT){
				var form2 = $('#cpttf-tr-form');
				var data2 = {};
				new FormData(form2).forEach(function(v,k){ data2[k] = v; });
				subT.disabled = true; subT.textContent = '...';
				ajax('cptt_fin_transfer', data2, function(r){
					subT.disabled = false; subT.textContent = 'انتقال';
					if (r && r.success) location.reload();
					else alert((r && r.data) ? r.data : 'خطا');
				});
			}
		});
		// toggle bank fields when type changes
		document.addEventListener('change', function(e){
			if (e.target && e.target.id === 'cpttf-acc-type'){
				var bankFields = $$('.cpttf-bank-fields', e.target.closest('form'));
				bankFields.forEach(function(b){ b.hidden = (e.target.value !== 'bank'); });
			}
		});
	}
	function openAccountModal(id){
		var modal = $('#cpttf-acc-modal'); if (!modal) return;
		var form = $('#cpttf-acc-form');
		form.reset();
		form.querySelector('[name="id"]').value = id || 0;
		$('#cpttf-acc-modal-title').textContent = id ? 'ویرایش حساب' : 'حساب جدید';
		// Trigger type-change to hide bank fields by default
		var typeSel = $('#cpttf-acc-type', form);
		if (typeSel) typeSel.dispatchEvent(new Event('change'));
		openModal('cpttf-acc-modal');
	}

	/* ───────── Categories ───────── */
	function bindCategoryButtons(){
		document.addEventListener('click', function(e){
			var addBtn = e.target.closest('[data-cpttf-cat="new"]');
			if (addBtn){ openCatModal(0, addBtn.dataset.type || 'expense'); }
			var editBtn = e.target.closest('[data-cpttf-cat="edit"]');
			if (editBtn){ openCatModal(parseInt(editBtn.dataset.id, 10) || 0); }
			var delBtn = e.target.closest('[data-cpttf-cat="delete"]');
			if (delBtn){
				if (!confirm('این دسته‌بندی حذف شود؟')) return;
				ajax('cptt_fin_cat_delete', { id: delBtn.dataset.id }, function(r){
					if (r && r.success) location.reload();
					else alert((r && r.data) ? r.data : 'خطا');
				});
			}
			var sub = e.target.closest('[data-cpttf-submit="cat"]');
			if (sub){
				var form = $('#cpttf-cat-form');
				var data = {};
				new FormData(form).forEach(function(v,k){ data[k] = v; });
				sub.disabled = true; sub.textContent = '...';
				ajax('cptt_fin_cat_save', data, function(r){
					sub.disabled = false; sub.textContent = 'ذخیره';
					if (r && r.success) location.reload();
					else alert((r && r.data) ? r.data : 'خطا');
				});
			}
		});
	}
	function openCatModal(id, type){
		var modal = $('#cpttf-cat-modal'); if (!modal) return;
		var form = $('#cpttf-cat-form');
		form.reset();
		form.querySelector('[name="id"]').value = id || 0;
		if (type) form.querySelector('[name="type"]').value = type;
		$('#cpttf-cat-modal-title').textContent = id ? 'ویرایش دسته' : 'دسته جدید';
		openModal('cpttf-cat-modal');
	}

	/* ───────── Chart (Canvas — simple bar + line) ───────── */
	function drawMonthlyChart(){
		var canvas = $('#cpttf-chart-monthly');
		if (!canvas) return;
		var data = [];
		try { data = JSON.parse(canvas.getAttribute('data-monthly') || '[]'); } catch(e){}
		if (!data || !data.length) return;
		var currency = canvas.getAttribute('data-currency') || '';

		function fitCanvas(){
			var parent = canvas.parentNode;
			var w = parent.clientWidth || 600;
			var h = 220;
			canvas.width  = w * (window.devicePixelRatio || 1);
			canvas.height = h * (window.devicePixelRatio || 1);
			canvas.style.width  = w + 'px';
			canvas.style.height = h + 'px';
			return { w: canvas.width, h: canvas.height };
		}
		function render(){
			var size = fitCanvas();
			var ctx = canvas.getContext('2d');
			ctx.clearRect(0,0,size.w,size.h);
			ctx.save();
			ctx.scale(window.devicePixelRatio || 1, window.devicePixelRatio || 1);
			var W = canvas.width / (window.devicePixelRatio || 1);
			var H = canvas.height / (window.devicePixelRatio || 1);

			var pad = { l: 56, r: 16, t: 16, b: 30 };
			var w = W - pad.l - pad.r;
			var h = H - pad.t - pad.b;
			var max = 0;
			data.forEach(function(d){ max = Math.max(max, d.income, d.expense); });
			if (max <= 0) max = 1;

			// grid + y labels
			ctx.font = '11px Tahoma, sans-serif';
			ctx.fillStyle = '#94a3b8';
			ctx.strokeStyle = '#e5e7eb';
			ctx.lineWidth = 1;
			var ticks = 4;
			for (var t = 0; t <= ticks; t++){
				var y = pad.t + (h * t / ticks);
				ctx.beginPath();
				ctx.moveTo(pad.l, y);
				ctx.lineTo(W - pad.r, y);
				ctx.stroke();
				var val = max * (1 - t / ticks);
				ctx.textAlign = 'right';
				ctx.fillText(shortNum(val), pad.l - 6, y + 3);
			}

			// bars
			var groupW = w / data.length;
			var barW = Math.min(28, groupW * 0.35);
			data.forEach(function(d, i){
				var gx = pad.l + groupW * i + groupW / 2;
				// income (green)
				var ih = h * (d.income / max);
				var iy = pad.t + h - ih;
				ctx.fillStyle = 'rgba(22, 163, 74, .85)';
				ctx.fillRect(gx - barW - 2, iy, barW, ih);
				// expense (red)
				var eh = h * (d.expense / max);
				var ey = pad.t + h - eh;
				ctx.fillStyle = 'rgba(239, 68, 68, .85)';
				ctx.fillRect(gx + 2, ey, barW, eh);
				// label
				ctx.fillStyle = '#475569';
				ctx.textAlign = 'center';
				ctx.fillText(d.label, gx, H - 10);
			});

			// legend
			ctx.textAlign = 'right';
			ctx.fillStyle = '#16a34a'; ctx.fillRect(W - pad.r - 90, pad.t - 8, 10, 10); ctx.fillStyle = '#475569';
			ctx.fillText('درآمد', W - pad.r - 60, pad.t);
			ctx.fillStyle = '#ef4444'; ctx.fillRect(W - pad.r - 90 - 60, pad.t - 8, 10, 10); ctx.fillStyle = '#475569';
			ctx.fillText('هزینه', W - pad.r - 60 - 60, pad.t);

			ctx.restore();
		}
		function shortNum(n){
			n = +n || 0;
			if (n >= 1e9) return (n/1e9).toFixed(1) + 'B';
			if (n >= 1e6) return (n/1e6).toFixed(1) + 'M';
			if (n >= 1e3) return (n/1e3).toFixed(0) + 'K';
			return Math.round(n);
		}
		render();
		window.addEventListener('resize', (function(){
			var t = null;
			return function(){ if (t) clearTimeout(t); t = setTimeout(render, 120); };
		})(), { passive: true });
	}

	/* ─── Donut / Pie chart ─── */
	function drawDonutCharts(){
		$$('canvas[data-pie]').forEach(function(canvas){
			var data = [];
			try { data = JSON.parse(canvas.getAttribute('data-pie') || '[]'); } catch(e){}
			if (!data || !data.length) return;
			var total = 0;
			data.forEach(function(d){ total += +d.value || 0; });
			if (total <= 0) return;

			function render(){
				var parent = canvas.parentNode;
				var W = parent.clientWidth || 360;
				var H = 220;
				canvas.width  = W * (window.devicePixelRatio || 1);
				canvas.height = H * (window.devicePixelRatio || 1);
				canvas.style.width  = W + 'px';
				canvas.style.height = H + 'px';
				var ctx = canvas.getContext('2d');
				ctx.save();
				ctx.scale(window.devicePixelRatio || 1, window.devicePixelRatio || 1);
				ctx.clearRect(0,0,W,H);

				var radius = Math.min(W, H) * 0.38;
				var cx = W * 0.34, cy = H * 0.50;
				var inner = radius * 0.55;

				var start = -Math.PI / 2;
				data.forEach(function(d){
					var portion = (+d.value || 0) / total;
					var end = start + portion * Math.PI * 2;
					ctx.beginPath();
					ctx.moveTo(cx, cy);
					ctx.arc(cx, cy, radius, start, end);
					ctx.closePath();
					ctx.fillStyle = d.color || '#94a3b8';
					ctx.fill();
					start = end;
				});
				// Cut center for donut effect
				ctx.globalCompositeOperation = 'destination-out';
				ctx.beginPath(); ctx.arc(cx, cy, inner, 0, Math.PI*2); ctx.fill();
				ctx.globalCompositeOperation = 'source-over';

				// Total in center
				ctx.fillStyle = '#0f172a';
				ctx.font = '900 16px Tahoma, sans-serif';
				ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
				ctx.fillText(shortNum(total), cx, cy - 6);
				ctx.fillStyle = '#64748b';
				ctx.font = '11px Tahoma, sans-serif';
				ctx.fillText('کل', cx, cy + 12);

				// Legend on right
				var lx = W * 0.62, ly = 20;
				ctx.textAlign = 'right'; ctx.textBaseline = 'top';
				ctx.font = '12px Tahoma, sans-serif';
				data.forEach(function(d){
					ctx.fillStyle = d.color || '#94a3b8';
					ctx.fillRect(W - 14, ly + 2, 10, 10);
					ctx.fillStyle = '#0f172a';
					ctx.fillText(d.name, W - 28, ly);
					ctx.fillStyle = '#64748b';
					ctx.font = '11px Tahoma, sans-serif';
					var pct = total > 0 ? Math.round(100 * (+d.value || 0) / total) : 0;
					ctx.fillText(shortNum(d.value) + ' (' + pct + '٪)', W - 28, ly + 14);
					ctx.font = '12px Tahoma, sans-serif';
					ly += 36;
				});
				ctx.restore();
			}
			render();
			var rT;
			window.addEventListener('resize', function(){ if(rT) clearTimeout(rT); rT = setTimeout(render, 120); }, { passive: true });
		});
	}

	/* ─── Horizontal bar chart ─── */
	function drawHBarCharts(){
		$$('canvas[data-hbar]').forEach(function(canvas){
			var data = [];
			try { data = JSON.parse(canvas.getAttribute('data-hbar') || '[]'); } catch(e){}
			if (!data || !data.length) return;
			var currency = canvas.getAttribute('data-currency') || '';

			function render(){
				var parent = canvas.parentNode;
				var W = parent.clientWidth || 360;
				var H = Math.max(180, data.length * 36 + 30);
				canvas.width  = W * (window.devicePixelRatio || 1);
				canvas.height = H * (window.devicePixelRatio || 1);
				canvas.style.width  = W + 'px';
				canvas.style.height = H + 'px';
				var ctx = canvas.getContext('2d');
				ctx.save();
				ctx.scale(window.devicePixelRatio || 1, window.devicePixelRatio || 1);
				ctx.clearRect(0,0,W,H);

				var max = 0;
				data.forEach(function(d){ max = Math.max(max, +d.paid || 0); });
				if (max <= 0) max = 1;

				var padR = 110, padL = 16, padT = 12, padB = 12;
				var rowH = (H - padT - padB) / data.length;
				var colors = ['#6366f1','#06b6d4','#16a34a','#f59e0b','#ec4899','#a855f7','#0ea5e9','#ef4444'];
				ctx.font = '12px Tahoma, sans-serif';

				data.forEach(function(d, i){
					var y = padT + i * rowH;
					var bw = (W - padR - padL) * ((+d.paid || 0) / max);
					ctx.fillStyle = colors[i % colors.length];
					ctx.beginPath();
					if (ctx.roundRect) ctx.roundRect(W - padR - bw, y + 8, bw, rowH - 16, 6);
					else                ctx.rect(W - padR - bw, y + 8, bw, rowH - 16);
					ctx.fill();
					// label (right)
					ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
					ctx.fillStyle = '#0f172a';
					var name = d.name || '—';
					if (name.length > 18) name = name.substr(0, 17) + '…';
					ctx.fillText(name, W - 6, y + rowH/2);
					// value (left of bar)
					ctx.textAlign = 'left';
					ctx.fillStyle = '#475569';
					ctx.font = 'bold 11px Tahoma, sans-serif';
					ctx.fillText(shortNum(d.paid), padL, y + rowH/2);
					ctx.font = '12px Tahoma, sans-serif';
				});
				ctx.restore();
			}
			render();
			var rT;
			window.addEventListener('resize', function(){ if(rT) clearTimeout(rT); rT = setTimeout(render, 120); }, { passive: true });
		});
	}

	function shortNum(n){
		n = +n || 0;
		if (n >= 1e9) return (n/1e9).toFixed(1) + 'B';
		if (n >= 1e6) return (n/1e6).toFixed(1) + 'M';
		if (n >= 1e3) return (n/1e3).toFixed(0) + 'K';
		return Math.round(n).toLocaleString('en');
	}

	/* ─── Jalali datepicker (basic, inline grid) ─── */
	var FA_MONTHS_JD = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
	function g2j(gy, gm, gd){
		var g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
		var gy2 = (gm > 2) ? (gy + 1) : gy;
		var days = 355666 + (365 * gy) + (~~((gy2 + 3) / 4)) - (~~((gy2 + 99) / 100)) + (~~((gy2 + 399) / 400)) + gd + g_d_m[gm - 1];
		var jy = -1595 + (33 * ~~(days / 12053));
		days %= 12053;
		jy += 4 * ~~(days / 1461);
		days %= 1461;
		if (days > 365){ jy += ~~((days - 1) / 365); days = (days - 1) % 365; }
		var jm = (days < 186) ? 1 + ~~(days / 31) : 7 + ~~((days - 186) / 30);
		var jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
		return [jy, jm, jd];
	}
	function j2g(jy, jm, jd){
		var jy1 = jy + 1595;
		var days = -355668 + (365 * jy1) + (~~(jy1 / 33) * 8) + (~~(((jy1 % 33) + 3) / 4)) + jd + ((jm < 7) ? (jm - 1) * 31 : ((jm - 7) * 30 + 186));
		var gy = 400 * ~~(days / 146097);
		days %= 146097;
		if (days > 36524){
			gy += 100 * ~~(--days / 36524);
			days %= 36524;
			if (days >= 365) days++;
		}
		gy += 4 * ~~(days / 1461);
		days %= 1461;
		if (days > 365){
			gy += ~~((days - 1) / 365);
			days = (days - 1) % 365;
		}
		var gd = days + 1;
		var sal_a = [0,31,((gy%4===0 && gy%100!==0) || (gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31];
		var gm; for (gm = 1; gm <= 12 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
		return [gy, gm, gd];
	}
	function jLen(jy, jm){
		if (jm <= 6) return 31;
		if (jm <= 11) return 30;
		var jy1 = jy + 1595;
		var leap = (jy1 % 33 % 4 - 1 === ((jy1 % 33) * 0.025 << 0)); // approx
		// simpler: check by next year
		return 29; // dey/bahman = 30/30, esfand 29 or 30
	}
	function toFaDigits(n){ var fa=['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹']; return String(n).replace(/[0-9]/g, function(d){return fa[+d];}); }

	function buildJDatepicker(input){
		if (input.dataset.cpttfJdBound) return;
		input.dataset.cpttfJdBound = '1';
		input.setAttribute('autocomplete', 'off');
		input.dir = 'ltr';
		input.addEventListener('focus', openPicker);
		input.addEventListener('click', openPicker);

		function openPicker(){
			closePickers();
			var now = new Date();
			var j = g2j(now.getFullYear(), now.getMonth()+1, now.getDate());
			// parse value if exists
			var v = String(input.value || '').replace(/[۰-۹]/g, function(c){ return '0123456789'['۰۱۲۳۴۵۶۷۸۹'.indexOf(c)]; });
			var m = v.match(/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/);
			var jy = m ? +m[1] : j[0];
			var jm = m ? +m[2] : j[1];
			var jd = m ? +m[3] : j[2];

			var picker = document.createElement('div');
			picker.className = 'cpttf-jdp';
			document.body.appendChild(picker);
			var r = input.getBoundingClientRect();
			picker.style.top  = (window.scrollY + r.bottom + 4) + 'px';
			picker.style.left = (window.scrollX + r.left) + 'px';

			function render(){
				var daysIn = 31;
				if (jm >= 7 && jm <= 11) daysIn = 30;
				else if (jm === 12){
					// crude leap-year fallback
					var gtmp = j2g(jy + 1, 1, 1);
					var gtmpPrev = j2g(jy, 12, 29);
					var diff = Math.round((new Date(gtmp[0], gtmp[1]-1, gtmp[2]) - new Date(gtmpPrev[0], gtmpPrev[1]-1, gtmpPrev[2])) / 86400000);
					daysIn = (diff >= 2) ? 30 : 29;
				}
				var gFirst = j2g(jy, jm, 1);
				var firstDate = new Date(gFirst[0], gFirst[1]-1, gFirst[2]);
				// Saturday-first (Persian week): JS day 6 = Saturday
				var firstWeekday = (firstDate.getDay() + 1) % 7; // 0=Sat..6=Fri
				var weekdays = ['ش','ی','د','س','چ','پ','ج'];

				var html = '<div class="cpttf-jdp__head">';
				html += '<button type="button" class="cpttf-jdp__nav" data-d="-1">‹</button>';
				html += '<span><b>'+ FA_MONTHS_JD[jm-1] +'</b> '+ toFaDigits(jy) +'</span>';
				html += '<button type="button" class="cpttf-jdp__nav" data-d="1">›</button>';
				html += '</div><div class="cpttf-jdp__grid">';
				weekdays.forEach(function(w){ html += '<div class="cpttf-jdp__wd">'+w+'</div>'; });
				for (var i = 0; i < firstWeekday; i++) html += '<div class="cpttf-jdp__cell cpttf-jdp__cell--empty"></div>';
				for (var d = 1; d <= daysIn; d++){
					var active = (d === jd) ? ' is-active' : '';
					html += '<button type="button" class="cpttf-jdp__cell'+active+'" data-day="'+d+'">'+ toFaDigits(d) +'</button>';
				}
				html += '</div><div class="cpttf-jdp__foot"><button type="button" class="cpttf-jdp__today">امروز</button><button type="button" class="cpttf-jdp__cancel">بستن</button></div>';
				picker.innerHTML = html;
				picker.querySelectorAll('.cpttf-jdp__nav').forEach(function(b){
					b.onclick = function(){ jm += +b.dataset.d; if (jm < 1){ jm = 12; jy--; } if (jm > 12){ jm = 1; jy++; } render(); };
				});
				picker.querySelectorAll('[data-day]').forEach(function(c){
					c.onclick = function(){
						jd = +c.dataset.day;
						var time = '';
						if (/\d{1,2}:\d{1,2}/.test(input.value)) time = input.value.match(/(\d{1,2}:\d{1,2})/)[1];
						input.value = (jy + '/' + String(jm).padStart(2,'0') + '/' + String(jd).padStart(2,'0')) + (time ? ' ' + time : '');
						input.dispatchEvent(new Event('change', { bubbles: true }));
						closePickers();
					};
				});
				picker.querySelector('.cpttf-jdp__today').onclick = function(){
					var n = new Date();
					var jt = g2j(n.getFullYear(), n.getMonth()+1, n.getDate());
					jy = jt[0]; jm = jt[1]; jd = jt[2];
					input.value = (jy + '/' + String(jm).padStart(2,'0') + '/' + String(jd).padStart(2,'0'));
					input.dispatchEvent(new Event('change', { bubbles: true }));
					closePickers();
				};
				picker.querySelector('.cpttf-jdp__cancel').onclick = closePickers;
			}
			render();

			setTimeout(function(){
				function out(e){ if (!e.target.closest('.cpttf-jdp') && e.target !== input){ closePickers(); document.removeEventListener('click', out, true); } }
				document.addEventListener('click', out, true);
			}, 0);
		}
	}
	function closePickers(){ $$('.cpttf-jdp').forEach(function(p){ p.parentNode && p.parentNode.removeChild(p); }); }
	function bindJDatepickers(){
		var sels = '.cpttf-jdate, .cpttf-modal__body input[name="date_local"], .cpttf-filters input[name="ffrom"], .cpttf-filters input[name="fto"], .cpttf-filters input[name="from"], .cpttf-filters input[name="to"]';
		$$(sels).forEach(buildJDatepicker);
		// Re-scan when a modal opens (forms are persistent in DOM but visible later)
		var mo = new MutationObserver(function(){ $$(sels).forEach(buildJDatepicker); });
		mo.observe(document.body, { childList: true, subtree: true });
	}

	ready(function(){
		bindCurrencyInputs();
		bindAccordion();
		bindReceivablesSearch();
		bindCloseHandlers();
		bindTxButtons();
		bindAccountButtons();
		bindCategoryButtons();
		drawMonthlyChart();
		drawDonutCharts();
		drawHBarCharts();
		bindJDatepickers();
	});
})();
