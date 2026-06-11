/**
 * CPTT AI Assistant — frontend chat widget
 * v6.2.0
 *
 * Lightweight: a single IIFE, no jQuery, minimal markdown rendering,
 * idempotent listeners, debounced sends, and persistent open-state per tab.
 */
(function(){
	'use strict';

	function ready(fn){
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}

	function escapeHtml(s){
		return String(s||'').replace(/[&<>"']/g, function(c){
			return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
		});
	}

	/* Tiny safe markdown:
	 *   **bold**           → <strong>
	 *   `code`             → <code>
	 *   - / * bullet list  → <ul><li>
	 *   1. numbered list   → <ol><li>
	 *   blank line         → paragraph break
	 *   line break         → <br>
	 * We HTML-escape first, then convert.
	 */
	function md(input){
		var s = escapeHtml(String(input || ''));
		// strip any <action>...</action> block (user shouldn't see raw JSON)
		s = s.replace(/&lt;action&gt;[\s\S]*?&lt;\/action&gt;/gi, '');
		// inline code: `code`
		s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
		// bold: **text**
		s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
		// lists — naive but readable
		var lines = s.split('\n');
		var out = [];
		var inUL = false, inOL = false;
		function closeLists(){
			if (inUL){ out.push('</ul>'); inUL = false; }
			if (inOL){ out.push('</ol>'); inOL = false; }
		}
		for (var i = 0; i < lines.length; i++){
			var l = lines[i];
			var mu = l.match(/^\s*[-*•]\s+(.*)$/);
			var mo = l.match(/^\s*\d+[\.)]\s+(.*)$/);
			if (mu){
				if (!inUL){ closeLists(); out.push('<ul>'); inUL = true; }
				out.push('<li>'+ mu[1] +'</li>');
			} else if (mo){
				if (!inOL){ closeLists(); out.push('<ol>'); inOL = true; }
				out.push('<li>'+ mo[1] +'</li>');
			} else {
				closeLists();
				out.push(l.trim() === '' ? '<br>' : l);
			}
		}
		closeLists();
		return out.join('\n').replace(/\n/g, '<br>');
	}

	function initAssistant(){
		var widget   = document.getElementById('cptt-ai-widget');
		if (!widget) return;
		var fab      = document.getElementById('cptt-ai-fab');
		var panel    = document.getElementById('cptt-ai-panel');
		var closeBtn = document.getElementById('cptt-ai-close');
		var clearBtn = document.getElementById('cptt-ai-clear');
		var form     = document.getElementById('cptt-ai-form');
		var input    = document.getElementById('cptt-ai-input');
		var msgsBox  = document.getElementById('cptt-ai-messages');

		if (!fab || !panel) return;

		var CFG = window.CPTT_AI || {};
		var SESSION_KEY = 'cptt_ai_panel_open';

		function open(){
			panel.hidden = false;
			fab.setAttribute('aria-expanded', 'true');
			try { sessionStorage.setItem(SESSION_KEY, '1'); } catch(_){}
			if (input) setTimeout(function(){ input.focus(); }, 60);
			scrollToBottom();
		}
		function close(){
			panel.hidden = true;
			fab.setAttribute('aria-expanded', 'false');
			try { sessionStorage.setItem(SESSION_KEY, '0'); } catch(_){}
		}
		fab.addEventListener('click', function(){
			panel.hidden ? open() : close();
		});
		if (closeBtn) closeBtn.addEventListener('click', close);

		// Restore open state on this tab
		try {
			if (sessionStorage.getItem(SESSION_KEY) === '1') open();
		} catch(_){}

		function scrollToBottom(){
			if (msgsBox) msgsBox.scrollTop = msgsBox.scrollHeight + 200;
		}

		function appendMsg(role, html, actionReport){
			if (!msgsBox) return null;
			var wrap = document.createElement('div');
			wrap.className = 'cptt-ai-msg cptt-ai-msg--' + (role === 'user' ? 'user' : 'bot');
			var bubble = document.createElement('div');
			bubble.className = 'cptt-ai-msg__bubble';
			bubble.innerHTML = html;
			wrap.appendChild(bubble);
			if (actionReport){
				var ar = document.createElement('div');
				ar.className = 'cptt-ai-msg__action-result ' + (actionReport.ok ? 'cptt-ai-msg__action-result--ok' : 'cptt-ai-msg__action-result--err');
				ar.innerHTML = (actionReport.ok ? '✓ ' : '✗ ') + escapeHtml(actionReport.message || '');
				bubble.appendChild(ar);
			}
			msgsBox.appendChild(wrap);
			scrollToBottom();
			return wrap;
		}

		function appendTyping(){
			if (!msgsBox) return null;
			var wrap = document.createElement('div');
			wrap.className = 'cptt-ai-msg cptt-ai-msg--bot cptt-ai-msg--typing';
			wrap.innerHTML = '<div class="cptt-ai-msg__bubble"><span class="cptt-ai-typing"><span></span><span></span><span></span></span></div>';
			msgsBox.appendChild(wrap);
			scrollToBottom();
			return wrap;
		}

		var inFlight = false;
		function sendMessage(text){
			if (!CFG.ajax || !CFG.nonce){
				appendMsg('bot', '<strong>خطا:</strong> پیکربندی دستیار در دسترس نیست.');
				return;
			}
			if (inFlight) return;
			text = String(text||'').trim();
			if (text === '') return;
			inFlight = true;

			appendMsg('user', md(text));
			var typing = appendTyping();
			fab.classList.add('is-busy');

			var fd = new FormData();
			fd.append('action', 'cptt_ai_chat');
			fd.append('nonce',  CFG.nonce);
			fd.append('message', text);

			var xhr = new XMLHttpRequest();
			xhr.open('POST', CFG.ajax);
			xhr.onload = function(){
				inFlight = false;
				fab.classList.remove('is-busy');
				if (typing && typing.parentNode) typing.parentNode.removeChild(typing);
				var r = null;
				try { r = JSON.parse(xhr.responseText); } catch(e){}
				if (r && r.success && r.data && r.data.reply){
					appendMsg('bot', md(r.data.reply), r.data.action || null);
					// If an action created/changed something visible on the page,
					// we soft-refresh to reflect it (but only after a short delay
					// so the user can read the success badge).
					if (r.data.action && r.data.action.ok){
						setTimeout(function(){
							// Reload dashboard data without full reload? Easiest: reload page.
							// Done after 1.5s so users can see the confirmation.
							location.reload();
						}, 1800);
					}
				} else {
					var err = (r && r.data) ? r.data : 'خطای ناشناخته در دستیار';
					appendMsg('bot', '<strong>خطا:</strong> ' + escapeHtml(String(err)));
				}
			};
			xhr.onerror = function(){
				inFlight = false;
				fab.classList.remove('is-busy');
				if (typing && typing.parentNode) typing.parentNode.removeChild(typing);
				appendMsg('bot', '<strong>خطا:</strong> اتصال به سرور ممکن نشد.');
			};
			xhr.send(fd);
		}

		if (form && input){
			form.addEventListener('submit', function(e){
				e.preventDefault();
				var v = input.value;
				if (v.trim() === '') return;
				input.value = '';
				input.style.height = '';
				sendMessage(v);
			});
			// Enter to send, Shift+Enter for newline, auto-grow
			input.addEventListener('keydown', function(e){
				if (e.key === 'Enter' && !e.shiftKey){
					e.preventDefault();
					if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event('submit', { cancelable: true }));
				}
			});
			input.addEventListener('input', function(){
				input.style.height = 'auto';
				input.style.height = Math.min(100, input.scrollHeight) + 'px';
			});
		}

		if (clearBtn){
			clearBtn.addEventListener('click', function(){
				if (!confirm('گفتگوی فعلی پاک شود؟')) return;
				// Wipe UI keeping only the first welcome bubble
				if (msgsBox){
					var first = msgsBox.querySelector('.cptt-ai-msg');
					msgsBox.innerHTML = '';
					if (first) msgsBox.appendChild(first);
				}
				// Wipe server-side rolling history
				if (CFG.ajax && CFG.nonce){
					var fd = new FormData();
					fd.append('action', 'cptt_ai_clear_history');
					fd.append('nonce',  CFG.nonce);
					var xhr = new XMLHttpRequest();
					xhr.open('POST', CFG.ajax);
					xhr.send(fd);
				}
			});
		}
	}

	ready(initAssistant);
})();
