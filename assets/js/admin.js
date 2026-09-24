/**
 * WP CPT Sidrene cijene — admin interactions.
 * Requires: cptscAdmin { ajax, nonce, i18n }.
 */
(function () {
	'use strict';

	if (typeof window.cptscAdmin === 'undefined') {
		return;
	}

	var CFG = window.cptscAdmin;

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', CFG.nonce);
		Object.keys(data || {}).forEach(function (k) {
			if (data[k] instanceof File) {
				body.append(k, data[k]);
			} else if (typeof data[k] === 'object' && data[k] !== null) {
				body.append(k, JSON.stringify(data[k]));
			} else {
				body.append(k, data[k]);
			}
		});
		return fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); });
	}

	function collectFields(root) {
		var out = {};
		$$('input, select, textarea', root).forEach(function (el) {
			if (!el.name || el.name === 'action' || el.name === 'nonce') { return; }
			if (el.type === 'radio') {
				if (el.checked) { out[el.name] = el.value; }
			} else if (el.type === 'checkbox') {
				if (el.checked) {
					if (out[el.name] === undefined) { out[el.name] = []; }
					if (Array.isArray(out[el.name])) { out[el.name].push(el.value); }
				}
			} else {
				out[el.name] = el.value;
			}
		});
		// Normalize checkbox arrays to first value when single-choice patterns used.
		Object.keys(out).forEach(function (k) {
			if (Array.isArray(out[k]) && out[k].length === 1 && k.indexOf('[]') === -1 && k.indexOf('post_types') === -1) {
				out[k] = out[k][0];
			}
		});
		return out;
	}

	/* ---------------------------------------------------------- wizard */

	var body = $('#cptsc-wizard-body');
	if (body) {
		var nextBtn = $('#cptsc-wizard-next');
		var backBtn = $('#cptsc-wizard-back');
		var actBtn = $('#cptsc-wizard-activate');

		function wizardPost(action, extra) {
			var step = parseInt(body.getAttribute('data-step'), 10) || 1;
			var data = collectFields(body);
			data.step = step;
			Object.keys(extra || {}).forEach(function (k) { data[k] = extra[k]; });
			return post(action, data);
		}

		function renderResult(res, direction) {
			if (!res.success) {
				var msg = (res.data && res.data.message) ? res.data.message : CFG.i18n.error;
				window.alert(msg);
				return;
			}
			var step = res.data.step;
			if (direction === 'save' || direction === 'back') {
				// Ask server for step HTML.
				wizardPost(direction === 'back' ? 'cptsc_wizard_back' : 'cptsc_wizard_next', { step: step }).then(function (r2) {
					if (r2.success && typeof r2.data.html === 'string') {
						showStep(r2.data.step, r2.data.html);
					} else if (r2.success) {
						location.reload();
					} else {
						window.alert((r2.data && r2.data.message) || CFG.i18n.error);
					}
				});
			} else if (direction === 'activate') {
				window.location.href = res.data.redirect;
			}
		}

		function showStep(step, html) {
			body.setAttribute('data-step', String(step));
			body.innerHTML = html;
			bindStepExtras();
			// Update nav buttons.
			var nav = document.querySelector('.cptsc-wizard-nav');
			if (nav) {
				nav.innerHTML = '';
				if (step > 1) {
					var b = document.createElement('button');
					b.type = 'button'; b.className = 'button'; b.id = 'cptsc-wizard-back'; b.textContent = '← Natrag';
					nav.appendChild(b);
				}
				if (step < 12) {
					var n = document.createElement('button');
					n.type = 'button'; n.className = 'button button-primary'; n.id = 'cptsc-wizard-next'; n.textContent = 'Nastavi →';
					nav.appendChild(n);
				} else {
					var a = document.createElement('button');
					a.type = 'button'; a.className = 'button button-primary'; a.id = 'cptsc-wizard-activate'; a.textContent = 'Aktiviraj';
					nav.appendChild(a);
				}
				bindNav();
			}
			window.scrollTo({ top: 0, behavior: 'smooth' });
		}

		function bindNav() {
			nextBtn = $('#cptsc-wizard-next');
			backBtn = $('#cptsc-wizard-back');
			actBtn = $('#cptsc-wizard-activate');
			if (nextBtn) {
				nextBtn.addEventListener('click', function () {
					nextBtn.disabled = true;
					var savePromise;
					var stepNum = parseInt(body.getAttribute('data-step'), 10) || 1;
					if (7 === stepNum && typeof window.cptscCollectRules === 'function') {
						// Persist term rules first, then advance.
						savePromise = post('cptsc_save_term_rules', { rules: window.cptscCollectRules() })
							.then(function () { return wizardPost('cptsc_wizard_save_step'); });
					} else {
						savePromise = wizardPost('cptsc_wizard_save_step');
					}
					savePromise.then(function (res) {
						nextBtn.disabled = false;
						if (!res.success) {
							window.alert((res.data && res.data.message) || CFG.i18n.error);
							return;
						}
						wizardPost('cptsc_wizard_next', { step: res.data.step }).then(function (r2) {
							if (r2.success) { showStep(r2.data.step, r2.data.html); }
						});
					});
				});
			}
			if (backBtn) {
				backBtn.addEventListener('click', function () {
					wizardPost('cptsc_wizard_back').then(function (res) {
						if (res.success) {
							wizardPost('cptsc_wizard_next', { step: res.data.step }).then(function (r2) {
								if (r2.success) { showStep(r2.data.step, r2.data.html); }
							});
						}
					});
				});
			}
			if (actBtn) {
				actBtn.addEventListener('click', function () {
					actBtn.disabled = true;
					post('cptsc_wizard_activate', {}).then(function (res) {
						actBtn.disabled = false;
						if (res.success) { window.location.href = res.data.redirect; }
						else { window.alert((res.data && res.data.message) || CFG.i18n.error); }
					});
				});
			}
		}
		bindNav();

		function bindStepExtras() {
			// Discovery runner.
			var disc = $('#cptsc-run-discovery');
			if (disc) {
				disc.addEventListener('click', function () {
					disc.disabled = true;
					var boxes = $$('.cptsc-disc-box');
					var idx = 0;
					function nextBox() {
						if (idx >= boxes.length) { disc.disabled = false; return; }
						var box = boxes[idx++];
						var pt = box.getAttribute('data-pt');
						box.querySelector('.description').textContent = CFG.i18n.working;
						post('cptsc_discover', { post_type: pt }).then(function (res) {
							if (res.success) {
								var d = res.data;
								box.querySelector('.description').innerHTML =
									'ACF: ' + (d.acf ? d.acf.length : 0) +
									' · Meta: ' + (d.meta ? d.meta.length : 0) +
									' · Taxonomije: ' + (d.taxonomies ? d.taxonomies.length : 0) +
									' · Uzorak: ' + d.sampled;
							} else {
								box.querySelector('.description').textContent = CFG.i18n.error;
							}
							nextBox();
						});
					}
					nextBox();
				});
			}

			// Inspector.
			var inspect = $('#cptsc-inspect');
			if (inspect) {
				inspect.addEventListener('click', function () {
					inspect.disabled = true;
					var out = $('#cptsc-inspect-result');
					out.innerHTML = '<p>' + CFG.i18n.working + '</p>';
					post('cptsc_inspect', { post_id: inspect.getAttribute('data-post') }).then(function (res) {
						inspect.disabled = false;
						if (!res.success || !res.data.fetched) {
							out.innerHTML = '<p class="cptsc-err">' + CFG.i18n.error + '</p>';
							return;
						}
						var cands = res.data.candidates || [];
						if (!cands.length) {
							out.innerHTML = '<p>' + 'Nije pronađeno mjesto cijene. Koristite shortcode način.' + '</p>';
							return;
						}
						var html = '<p class="description">Pronađena mjesta — odaberite ono gdje je aktualna cijena:</p>';
						cands.forEach(function (c, i) {
							html += '<label class="cptsc-candidate">' +
								'<input type="radio" name="cptsc_selector_pick" value="' + escapeAttr(c.selector) + '"' + (i === 0 ? ' checked' : '') + '>' +
								'<span class="cptsc-cand-title"><strong>' + escapeHtml(c.selector) + '</strong>' +
								(c.in_content ? '' : ' <em>(samo izvan sadržaja — shortcode je pouzdaniji)</em>') + '</span>' +
								'<span class="cptsc-cand-samples">' + escapeHtml(c.text) + '</span>' +
								'</label>';
						});
						html += '<p><button type="button" class="button button-primary" id="cptsc-verify-selector">Potvrdi odabrano mjesto</button></p>';
						html += '<div id="cptsc-verify-result"></div>';
						out.innerHTML = html;

						var verify = $('#cptsc-verify-selector');
						verify.addEventListener('click', function () {
							var pick = document.querySelector('input[name="cptsc_selector_pick"]:checked');
							if (!pick) { return; }
							verify.disabled = true;
							post('cptsc_verify_selector', {
								selector: pick.value,
								post_id: inspect.getAttribute('data-post')
							}).then(function (r) {
								verify.disabled = false;
								var vr = $('#cptsc-verify-result');
								if (r.success && r.data.verified) {
									vr.innerHTML = '<p class="cptsc-ok">Potvrđeno — automatski prikaz je aktivan.</p>';
								} else {
									vr.innerHTML = '<p class="cptsc-warn">Mjesto nije pronađeno u sadržaju. Preporučujemo shortcode način ili odaberite drugo mjesto.</p>';
								}
							});
						});
					});
				});
			}
		}
		bindStepExtras();
	}

	/* ------------------------------------------------------ term rules */

	var taxBox = document.getElementById('cptsc-term-rules');
	if (taxBox) {
		function collectRules() {
			var rules = [];
			$$('.cptsc-term-check', taxBox).forEach(function (cb) {
				if (!cb.checked) { return; }
				var tax = cb.getAttribute('data-taxonomy');
				var term = cb.getAttribute('data-term');
				var groupEl = taxBox.querySelector('.cptsc-term-group[data-taxonomy="' + tax + '"][data-term="' + term + '"]');
				var descEl = taxBox.querySelector('.cptsc-term-desc[data-taxonomy="' + tax + '"][data-term="' + term + '"]');
				rules.push({
					taxonomy: tax,
					term_id: parseInt(term, 10),
					group: groupEl ? groupEl.value : 'general_2026',
					include_descendants: descEl ? descEl.checked : true
				});
			});
			return rules;
		}
		taxBox.addEventListener('change', function (e) {
			var t = e.target;
			if (t.classList.contains('cptsc-term-check')) {
				var tax = t.getAttribute('data-taxonomy');
				var term = t.getAttribute('data-term');
				var g = taxBox.querySelector('.cptsc-term-group[data-taxonomy="' + tax + '"][data-term="' + term + '"]');
				if (g) { g.disabled = !t.checked; }
			}
		});
		// Save rules whenever leaving step (also on next click via save_step 7 hook below).
		window.cptscCollectRules = collectRules;
	}

	/* ---------------------------------------------------------- jobs */

	$$('.cptsc-resume-job').forEach(function (btn) {
		btn.addEventListener('click', function () {
			btn.disabled = true;
			post('cptsc_resume_job', { job_id: btn.getAttribute('data-job') }).then(function (res) {
				if (res.success && res.data.resumed) { location.reload(); }
				else { btn.disabled = false; }
			});
		});
	});

	/* ------------------------------------------------ diagnostics copy */

	var copyBtn = document.getElementById('cptsc-copy-diag');
	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			var text = document.getElementById('cptsc-diag-text').textContent;
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function () {
					copyBtn.textContent = 'Kopirano ✓';
					setTimeout(function () { copyBtn.textContent = 'Kopiraj dijagnostiku'; }, 1500);
				});
			}
		});
	}

	/* ------------------------------------------------------- job poll */

	$$('.cptsc-job-panel').forEach(function (panel) {
		var jobId = panel.getAttribute('data-job');
		var timer = setInterval(function () {
			post('cptsc_job_status', { job_id: jobId }).then(function (res) {
				if (!res.success) { clearInterval(timer); return; }
				var job = res.data;
				var pct = job.total > 0 ? Math.round(100 * job.processed / Math.max(1, job.total)) : 0;
				var bar = panel.querySelector('.cptsc-progress-bar');
				if (bar) { bar.style.width = pct + '%'; }
				var prog = panel.querySelector('.cptsc-job-progress');
				if (prog) { prog.textContent = job.processed + ' / ' + job.total; }
				var st = panel.querySelector('.cptsc-job-status');
				if (st && job.status) { st.textContent = job.status; }
				if (['completed', 'failed', 'cancelled'].indexOf(job.status) !== -1) {
					clearInterval(timer);
					setTimeout(function () { location.reload(); }, 800);
				}
			});
		}, 2500);
	});

	/* ------------------------------------------------------ test mode */

	var testBtn = document.getElementById('cptsc-run-test');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			testBtn.disabled = true;
			var out = document.getElementById('cptsc-test-result');
			out.innerHTML = '<p>' + CFG.i18n.working + '</p>';
			post('cptsc_test_run', { limit: 5 }).then(function (res) {
				testBtn.disabled = false;
				if (!res.success) {
					out.innerHTML = '<p class="cptsc-err">' + ((res.data && res.data.message) || CFG.i18n.error) + '</p>';
					return;
				}
				var rows = res.data.rows || [];
				var html = '<h4>' + 'Rezultat testa (bez promjena u produkcijskim podacima)' + '</h4>';
				html += '<table class="widefat striped cptsc-test-table"><thead><tr>' +
					'<th>ID</th><th>Naziv</th><th>Cijena</th><th>Grupa</th><th>Status</th><th>Frontend</th>' +
					'</tr></thead><tbody>';
				rows.forEach(function (r) {
					html += '<tr>' +
						'<td>' + r.post_id + '</td>' +
						'<td>' + escapeHtml(r.title) + '</td>' +
						'<td>' + escapeHtml((r.values && r.values.current_price) || '—') + '</td>' +
						'<td>' + escapeHtml((r.group_info && r.group_info.group) || '—') + '</td>' +
						'<td>' + escapeHtml((r.issues && r.issues.length) ? r.issues.join(', ') : 'OK') + '</td>' +
						'<td>' + (r.frontend ? '<div class="cptsc-anchor-price">' + r.frontend.replace(/<\/?div[^>]*>/g, '') + '</div>' : '—') + '</td>' +
						'</tr>';
				});
				html += '</tbody></table>';
				if (res.data.csv) {
					html += '<h4>CSV pregled</h4><pre class="cptsc-diag">' + escapeHtml(res.data.csv) + '</pre>';
				}
				out.innerHTML = html;
			});
		});
	}

	/* --------------------------------------------- full reset confirm */

	window.cptscConfirmReset = function (e) {
		var form = e.target;
		var answer = window.prompt('Za potpuni reset unesite riječ RESET (sve sidrene i povijesne podatke će se obrisati):');
		if (answer !== 'RESET') { return false; }
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = 'cptsc_confirm';
		input.value = answer;
		form.appendChild(input);
		return true;
	};

	window.cptscConfirmUninstall = function (e) {
		var form = e.target;
		var answer = window.prompt('TRAJNO BRIŠE SVE podatke i datoteke plugina te ga deaktivira. Unesite točno: OBRIŠI SVE');
		if (answer !== 'OBRIŠI SVE') { return false; }
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = 'cptsc_confirm';
		input.value = answer;
		form.appendChild(input);
		return true;
	};

	function escapeHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
	}
	function escapeAttr(s) { return escapeHtml(s); }
})();
