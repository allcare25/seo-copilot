(function ($) {
	'use strict';

	function renderCheckDetails(checks) {
		var labels = {
			structured_data: '構造化データ(JSON-LD)',
			headings: '見出し構造(H2/H3・質問形式)',
			intro_summary: '冒頭での結論提示',
			scannable_structure: 'リスト/表の有無',
			content_length: '本文の分量'
		};

		var html = '<ul>';
		$.each(labels, function (key, label) {
			var item = checks[key];
			if (!item) return;
			var badge = item.pass
				? '<span class="seocp-badge-pass">◎ 合格</span>'
				: '<span class="seocp-badge-fail">△ 要改善</span>';
			html += '<li>' + label + '：' + badge + '</li>';
		});
		html += '</ul>';
		return html;
	}

	function previewSuggestionButtonHtml(label) {
		label = label || '選択した項目を記事に反映（差分を確認）';
		return '<button type="button" class="button button-primary seocp-preview-llmo-suggestion">' + label + '</button>';
	}

	// AI改善提案(箇条書きテキスト)を1項目ずつに分解する。
	// PHP側の parse_suggestion_items() と同じルールで行頭のマーカーを除去し、
	// 同じ上限件数で切り詰める。
	var MAX_SUGGESTION_ITEMS = 5;

	function parseSuggestionItems(text) {
		return String(text || '')
			.split(/\r\n|\r|\n/)
			.map(function (line) { return line.trim(); })
			.filter(function (line) { return line !== ''; })
			.map(function (line) { return line.replace(/^[\-\*・]\s*|^\d+[.)、]\s*/u, '').trim(); })
			.filter(function (line) { return line !== ''; })
			.slice(0, MAX_SUGGESTION_ITEMS);
	}

	function renderSuggestionPanel(text) {
		var items = parseSuggestionItems(text);
		var html = '<strong>AI改善提案(反映する項目にチェック):</strong>';
		html += '<ul class="seocp-suggestion-items" style="margin-top:6px;">';
		items.forEach(function (item, idx) {
			html += '<li><label>' +
				'<input type="checkbox" class="seocp-suggestion-item-checkbox" value="' + idx + '" checked> ' +
				$('<div>').text(item).html() +
				'</label></li>';
		});
		html += '</ul>';
		html += '<div class="seocp-apply-suggestion-row" style="margin-top:10px;">' + previewSuggestionButtonHtml() + '</div>';
		html += '<div class="seocp-diff-preview-panel" style="display:none; margin-top:10px;"></div>';
		html += '<p style="color:#666;font-size:12px;margin-top:6px;">※ チェックを入れた項目だけを反映します。一部だけ選んで反映すると、AIの処理量が減りトークン上限エラーになりにくくなります。まとめて何度かに分けて反映することもできます。</p>';
		return html;
	}

	$(document).on('click', '#seocp-check-site-wide', function () {
		var $btn = $(this);
		var $out = $('#seocp-site-wide-output');
		$btn.prop('disabled', true).text('チェック中...');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_check_site_wide',
			nonce: SEOCP.nonce
		}).done(function (res) {
			if (!res.success) {
				$out.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var d = res.data;
			var html = '<table class="widefat striped" style="max-width:700px;">';

			html += '<tr><td>robots.txt</td><td>';
			if (!d.robots_txt.exists) {
				html += '<span class="seocp-badge-fail">見つかりません</span>';
			} else {
				html += '許可: ' + (d.robots_txt.allowed.join(', ') || 'なし') + '<br>';
				html += 'ブロック: ' + (d.robots_txt.blocked.join(', ') || 'なし');
			}
			html += '</td></tr>';

			html += '<tr><td>llms.txt</td><td>';
			html += d.llms_txt.exists
				? '<span class="seocp-badge-pass">設置済み</span>'
				: '<span class="seocp-badge-fail">未設置 (' + d.llms_txt.url + ')</span>';
			html += '</td></tr>';

			html += '</table>';
			$out.html(html);
		}).fail(function () {
			$out.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('robots.txt / llms.txt をチェック');
		});
	});

	$(document).on('click', '.seocp-run-check', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $panel = $row.find('.seocp-detail-panel');

		$btn.prop('disabled', true).text('チェック中...');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_run_llmo_check',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				$panel.show().html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			$row.find('.seocp-score-cell').text(res.data.score + ' 点');
			$row.find('.seocp-checked-at-cell').text('たった今');
			$panel.show().html(renderCheckDetails(res.data.checks));
		}).fail(function () {
			$panel.show().html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('チェック実行');
		});
	});

	$(document).on('click', '.seocp-generate-suggestion', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $panel = $row.find('.seocp-detail-panel');

		$btn.prop('disabled', true).text('生成中...');
		$panel.show().html('<p>AIが改善案を生成しています…</p>');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_generate_ai_suggestion',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				$panel.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var text = res.data.suggestion;
			$panel.html(renderSuggestionPanel(text));
		}).fail(function () {
			$panel.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('AI改善提案を生成');
		});
	});

	/* v0.7.0: 差分プレビュー → 確認して反映、の2段階フロー */

	$(document).on('click', '.seocp-preview-llmo-suggestion', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $panel = $row.find('.seocp-detail-panel');
		var $diffPanel = $panel.find('.seocp-diff-preview-panel');

		var selected = [];
		$panel.find('.seocp-suggestion-item-checkbox:checked').each(function () {
			selected.push($(this).val());
		});

		if ($panel.find('.seocp-suggestion-item-checkbox').length > 0 && selected.length === 0) {
			alert('反映する改善提案を1つ以上チェックしてください。');
			return;
		}

		$btn.prop('disabled', true).text('AIが改訂案を生成中...');
		$diffPanel.show().html('<p>準備中...</p>');

		// v0.7.4: 実行時間の短い共用サーバー(ロリポップ等)でもタイムアウトしないよう、
		// 断片(またはJSON-LD生成)を1つずつ、別々の短いAjaxリクエストで順番に処理する。
		// 1回のリクエストが長引かないため、サーバー側のPHP実行時間の上限に引っかかりにくい。
		var stepRetryLeft = 2; // 1ステップあたりの通信エラー時の自動リトライ回数

		function finishWithError(message) {
			$btn.prop('disabled', false).text('選択した項目を記事に反映（差分を確認）');
			$diffPanel.show().html('<p class="seocp-badge-fail">エラー: ' + message + '</p>');
		}

		function finishWithNetworkError() {
			$btn.prop('disabled', false).text('選択した項目を記事に反映（差分を確認）');
			$diffPanel.show().html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}

		function runStep() {
			$.post(SEOCP.ajaxUrl, {
				action: 'seocp_process_llmo_revision_step',
				nonce: SEOCP.nonce,
				post_id: postId
			}).done(function (res) {
				if (!res.success) {
					finishWithError(res.data.message);
					return;
				}

				stepRetryLeft = 2; // 成功したのでリトライ回数をリセット

				if (!res.data.done) {
					$btn.text('AIが改訂案を生成中...(' + res.data.done_steps + '/' + res.data.total_steps + ')');
					runStep();
					return;
				}

				$btn.prop('disabled', false).text('選択した項目を記事に反映（差分を確認）');
				var chunkNote = res.data.chunk_count
					? '<p class="description">本文を' + res.data.chunk_count + '個の断片に分割してAIへ渡しました。</p>'
					: '';
				$diffPanel.show().html(
					'<div class="seocp-diff-preview-box">' +
					'<p><strong>差分プレビュー(削除: <del>赤打消線</del> / 追加: <ins>緑下線</ins>)</strong></p>' +
					chunkNote +
					res.data.diff_html +
					'</div>' +
					'<div class="seocp-confirm-row" style="margin-top:10px;">' +
					'<button type="button" class="button button-primary seocp-confirm-llmo-suggestion">確認しました。この内容で記事を上書きする</button> ' +
					'<button type="button" class="button seocp-cancel-llmo-preview">キャンセル</button>' +
					'</div>'
				);
			}).fail(function () {
				// 1ステップは短時間で終わる想定のため、通信エラーは一時的な回線不調の可能性が高い。
				// 数回まではジョブを破棄せず自動的に同じステップをリトライする。
				if (stepRetryLeft > 0) {
					stepRetryLeft--;
					setTimeout(runStep, 1500);
					return;
				}
				finishWithNetworkError();
			});
		}

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_start_llmo_revision',
			nonce: SEOCP.nonce,
			post_id: postId,
			selected: selected
		}).done(function (res) {
			if (!res.success) {
				finishWithError(res.data.message);
				return;
			}
			$btn.text('AIが改訂案を生成中...(0/' + res.data.total_steps + ')');
			runStep();
		}).fail(function () {
			finishWithNetworkError();
		});
	});

	$(document).on('click', '.seocp-cancel-llmo-preview', function () {
		$(this).closest('.seocp-diff-preview-panel').hide().empty();
	});

	$(document).on('click', '.seocp-confirm-llmo-suggestion', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $diffPanel = $btn.closest('.seocp-diff-preview-panel');

		$btn.prop('disabled', true).text('反映中...');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_confirm_llmo_suggestion',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				$diffPanel.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var scoreText = (res.data.score !== null && res.data.score !== undefined)
				? res.data.score + ' 点'
				: $row.find('.seocp-score-cell').text();
			$row.find('.seocp-score-cell').text(scoreText);
			$row.find('.seocp-checked-at-cell').text('たった今');
			$diffPanel.html(
				'<span class="seocp-badge-pass">記事の本文を更新しました' + (scoreText ? '（スコア: ' + scoreText + '）' : '') + '。</span><br>' +
				'<span style="color:#666;font-size:12px;">更新前の内容はWordPressの「リビジョン」から復元できます。効果測定の基準値も自動で記録されました(「更新履歴・順位変化・効果測定」画面で確認できます)。</span><br>' +
				'<a href="' + res.data.edit_url + '" target="_blank" rel="noopener">記事を編集する →</a>'
			);
		}).fail(function () {
			$btn.prop('disabled', false).text('確認しました。この内容で記事を上書きする');
			$diffPanel.append('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		});
	});

	/* v0.7.0: 一括スコアリング(llmo-page.php) */

	function loadLowScorePosts() {
		var $list = $('#seocp-low-score-list');
		if (!$list.length) return;
		$list.html('<em>読み込み中...</em>');
		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_get_low_score_posts',
			nonce: SEOCP.nonce
		}).done(function (res) {
			if (!res.success || !res.data.posts.length) {
				$list.html('<p>まだチェック済みの記事がありません。下の一覧から「チェック実行」するか、「表示中の記事を一括チェック」をお試しください。</p>');
				return;
			}
			var html = '<table class="widefat striped"><thead><tr><th>タイトル</th><th style="width:70px;">スコア</th></tr></thead><tbody>';
			$.each(res.data.posts, function (i, p) {
				html += '<tr><td><a href="' + p.edit_url + '" target="_blank" rel="noopener">' + p.title + '</a></td><td>' + p.score + ' 点</td></tr>';
			});
			html += '</tbody></table>';
			$list.html(html);
		});
	}

	$(document).on('click', '#seocp-refresh-low-score', loadLowScorePosts);
	if ($('#seocp-low-score-list').length) {
		loadLowScorePosts();
	}

	$(document).on('click', '#seocp-bulk-check-all', function () {
		var $btn = $(this);
		var $groups = $('.seocp-suggestion-table > tbody.seocp-suggestion-group');
		var total = $groups.length;
		if (!total) return;

		$btn.prop('disabled', true);
		$('#seocp-bulk-check-progress').show();
		$('#seocp-bulk-progress-fill').css('width', '0%');
		$('#seocp-bulk-progress-text').text('0 / ' + total);

		var index = 0;
		function next() {
			if (index >= total) {
				$('#seocp-bulk-progress-text').text('完了: ' + total + ' / ' + total);
				$btn.prop('disabled', false);
				loadLowScorePosts();
				return;
			}
			var $group = $groups.eq(index);
			var postId = $group.data('post-id');
			$.post(SEOCP.ajaxUrl, {
				action: 'seocp_run_llmo_check',
				nonce: SEOCP.nonce,
				post_id: postId
			}).done(function (res) {
				if (res.success) {
					$group.find('.seocp-score-cell').text(res.data.score + ' 点');
					$group.find('.seocp-checked-at-cell').text('たった今');
				}
			}).always(function () {
				index++;
				var pct = Math.round((index / total) * 100);
				$('#seocp-bulk-progress-fill').css('width', pct + '%');
				$('#seocp-bulk-progress-text').text(index + ' / ' + total);
				next();
			});
		}
		next();
	});

	/* ----------------- GSC: 設定画面 ----------------- */

	$(document).on('click', '#seocp-gsc-connect', function () {
		var $btn = $(this);
		$btn.prop('disabled', true).text('接続準備中...');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_gsc_get_auth_url',
			nonce: SEOCP.nonce
		}).done(function (res) {
			if (!res.success) {
				alert('エラー: ' + res.data.message);
				$btn.prop('disabled', false).text('Googleに接続');
				return;
			}
			window.location.href = res.data.url;
		}).fail(function () {
			alert('通信エラーが発生しました。');
			$btn.prop('disabled', false).text('Googleに接続');
		});
	});

	$(document).on('click', '#seocp-gsc-disconnect', function () {
		if (!confirm('Search Consoleとの接続を解除しますか？')) return;
		var $btn = $(this);
		$btn.prop('disabled', true);

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_gsc_disconnect',
			nonce: SEOCP.nonce
		}).done(function () {
			location.reload();
		});
	});

	$(document).on('click', '#seocp-gsc-load-sites', function () {
		var $btn = $(this);
		var $select = $('#seocp-gsc-site-select');
		$btn.prop('disabled', true).text('取得中...');
		$select.empty().append('<option>読み込み中...</option>');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_gsc_list_sites',
			nonce: SEOCP.nonce
		}).done(function (res) {
			$select.empty();
			if (!res.success) {
				$select.append('<option>取得失敗</option>');
				alert('エラー: ' + res.data.message);
				return;
			}
			if (!res.data.sites.length) {
				$select.append('<option>プロパティが見つかりません</option>');
				return;
			}
			$.each(res.data.sites, function (i, site) {
				$select.append(
					$('<option>').val(site.siteUrl).text(site.siteUrl + '（' + site.permissionLevel + '）')
				);
			});
		}).fail(function () {
			$select.empty().append('<option>通信エラー</option>');
		}).always(function () {
			$btn.prop('disabled', false).text('プロパティ一覧を取得');
		});
	});

	$(document).on('click', '#seocp-gsc-save-site', function () {
		var siteUrl = $('#seocp-gsc-site-select').val();
		if (!siteUrl) {
			alert('プロパティを選択してください。先に「プロパティ一覧を取得」を押してください。');
			return;
		}
		var $btn = $(this);
		$btn.prop('disabled', true).text('保存中...');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_gsc_save_site',
			nonce: SEOCP.nonce,
			site_url: siteUrl
		}).done(function (res) {
			if (!res.success) {
				alert('エラー: ' + res.data.message);
				return;
			}
			location.reload();
		}).always(function () {
			$btn.prop('disabled', false).text('このプロパティを使用');
		});
	});

	/* ----------------- GSC: データ取得画面 ----------------- */

	$(document).on('click', '#seocp-gsc-fetch', function () {
		var $btn = $(this);
		var days = $('#seocp-gsc-days').val();
		var $out = $('#seocp-gsc-fetch-result');

		$btn.prop('disabled', true).text('取得中... (数十秒かかる場合があります)');
		$out.html('');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_gsc_fetch_data',
			nonce: SEOCP.nonce,
			days: days
		}).done(function (res) {
			if (!res.success) {
				$out.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			$out.html(
				'<p class="seocp-badge-pass">' + res.data.saved_count + '件のページデータを取得しました（' +
				res.data.start_date + ' 〜 ' + res.data.end_date + '）。一覧を更新しています…</p>'
			);
			setTimeout(function () { location.reload(); }, 1200);
		}).fail(function () {
			$out.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('最新データを取得');
		});
	});

	/* ----------------- AI提案: タイトル/FAQ/内部リンク ----------------- */

	function escHtml(str) {
		return $('<div>').text(str == null ? '' : str).html();
	}

	$(document).on('click', '.seocp-gen-title', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $out = $row.find('.seocp-result-title');
		$row.find('.seocp-result-empty').hide();

		$btn.prop('disabled', true).text('生成中...');
		$out.html('<p>タイトル案を生成しています…</p>');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_generate_title',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				$out.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var titles = res.data.titles;
			var html = '<strong>タイトル改善案:</strong><ul class="seocp-title-suggestion-list">';
			if (titles && titles.length) {
				$.each(titles, function (i, t) {
					html += '<li>'
						+ '<span class="seocp-title-suggestion-text">' + escHtml(t) + '</span>'
						+ ' <button type="button" class="button button-small seocp-apply-title" data-title="' + escHtml(t) + '">この案を反映</button>'
						+ ' <span class="seocp-apply-title-result"></span>'
						+ '</li>';
				});
			} else {
				html += '<li>' + escHtml(res.data.raw).replace(/\n/g, '<br>') + '</li>';
			}
			html += '</ul>';
			$out.html(html);
		}).fail(function () {
			$out.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('タイトル案');
		});
	});

	$(document).on('click', '.seocp-apply-title', function () {
		var $btn = $(this);
		var $li = $btn.closest('li');
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var title = $btn.data('title');
		var $result = $li.find('.seocp-apply-title-result');

		if (!window.confirm('この案でタイトルを上書きします。よろしいですか？\n\n' + title)) {
			return;
		}

		$btn.prop('disabled', true).text('反映中...');
		$row.find('.seocp-apply-title').not($btn).prop('disabled', true);

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_apply_title',
			nonce: SEOCP.nonce,
			post_id: postId,
			title: title
		}).done(function (res) {
			if (!res.success) {
				$result.html('<span class="seocp-badge-fail">エラー: ' + escHtml(res.data.message) + '</span>');
				$row.find('.seocp-apply-title').prop('disabled', false);
				$btn.text('この案を反映');
				return;
			}
			$result.html('<span class="seocp-badge-pass">反映しました</span>');
			$li.siblings().find('.seocp-apply-title').prop('disabled', false).text('この案を反映');
			$btn.text('反映済み');
			// 一覧側に表示しているタイトル文言があれば合わせて更新する
			$row.find('.seocp-current-title').text(title);
			$('.seocp-post-title-' + postId).text(title);
			// プレビュー/編集パネルを開いている場合はその場で内容を更新する
			refreshPreviewIfOpen($row, res.data);
		}).fail(function () {
			$result.html('<span class="seocp-badge-fail">通信エラーが発生しました。</span>');
			$row.find('.seocp-apply-title').prop('disabled', false);
			$btn.text('この案を反映');
		});
	});

	$(document).on('click', '.seocp-gen-meta-desc', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $out = $row.find('.seocp-result-meta-desc');

		$btn.prop('disabled', true).text('生成中...');
		$out.html('<p>説明文案を生成しています…</p>');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_generate_meta_description',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				$out.html('<p class="seocp-badge-fail">エラー: ' + escHtml(res.data.message) + '</p>');
				return;
			}
			var descriptions = res.data.descriptions;
			var html = '<strong>メタディスクリプション改善案:</strong><ul class="seocp-title-suggestion-list">';
			if (descriptions && descriptions.length) {
				$.each(descriptions, function (i, t) {
					var len = seocpCountLength(t);
					var lenClass = len > 120 ? 'seocp-badge-fail' : 'seocp-meta-desc-length';
					html += '<li>'
						+ '<span class="seocp-title-suggestion-text">' + escHtml(t) + '</span>'
						+ ' <span class="' + lenClass + '">(' + len + '文字)</span>'
						+ ' <button type="button" class="button button-small seocp-apply-meta-desc" data-description="' + escHtml(t) + '">この案を反映</button>'
						+ ' <span class="seocp-apply-title-result"></span>'
						+ '</li>';
				});
			} else {
				html += '<li>' + escHtml(res.data.raw).replace(/\n/g, '<br>') + '</li>';
			}
			html += '</ul>';
			$out.html(html);
		}).fail(function () {
			$out.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('説明文案');
		});
	});

	// 全角換算の簡易文字数カウント(全角1文字/半角0.5文字)。PHP側のSEOCP_Meta_Description::count_lengthと揃えている。
	function seocpCountLength(str) {
		var len = 0;
		for (var i = 0; i < str.length; i++) {
			len += str.charCodeAt(i) > 255 ? 1 : 0.5;
		}
		return Math.ceil(len);
	}

	$(document).on('click', '.seocp-apply-meta-desc', function () {
		var $btn = $(this);
		var $li = $btn.closest('li');
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var description = $btn.data('description');
		var $result = $li.find('.seocp-apply-title-result');

		if (!window.confirm('この案でメタディスクリプションを上書きします。よろしいですか？\n\n' + description)) {
			return;
		}

		$btn.prop('disabled', true).text('反映中...');
		$row.find('.seocp-apply-meta-desc').not($btn).prop('disabled', true);

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_apply_meta_description',
			nonce: SEOCP.nonce,
			post_id: postId,
			description: description
		}).done(function (res) {
			if (!res.success) {
				$result.html('<span class="seocp-badge-fail">エラー: ' + escHtml(res.data.message) + '</span>');
				$row.find('.seocp-apply-meta-desc').prop('disabled', false);
				$btn.text('この案を反映');
				return;
			}
			$result.html('<span class="seocp-badge-pass">反映しました(' + escHtml(res.data.source_label) + ')</span>');
			$li.siblings().find('.seocp-apply-meta-desc').prop('disabled', false).text('この案を反映');
			$btn.text('反映済み');
			// 一覧側に表示している「現在の説明文」を合わせて更新する
			$('.seocp-current-meta-desc-' + postId).text(description);
		}).fail(function () {
			$result.html('<span class="seocp-badge-fail">通信エラーが発生しました。</span>');
			$row.find('.seocp-apply-meta-desc').prop('disabled', false);
			$btn.text('この案を反映');
		});
	});

	$(document).on('click', '.seocp-gen-faq', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $out = $row.find('.seocp-result-faq');
		$row.find('.seocp-result-empty').hide();

		$btn.prop('disabled', true).text('生成中...');
		$out.html('<p>FAQ案を生成しています…</p>');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_generate_faq',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				$out.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var faqs = res.data.faqs;
			var html = '<strong>FAQ追加案:</strong>';
			if (faqs && faqs.length) {
				html += '<ul class="seocp-title-suggestion-list seocp-faq-suggestion-list">';
				$.each(faqs, function (i, f) {
					html += '<li>'
						+ '<label style="display:block;">'
						+ '<input type="checkbox" class="seocp-faq-item-check" data-index="' + i + '" checked> '
						+ '<strong>Q. ' + escHtml(f.question) + '</strong><br>'
						+ '<span style="margin-left:22px; display:inline-block;">A. ' + escHtml(f.answer) + '</span>'
						+ '</label>'
						+ '</li>';
				});
				html += '</ul>';
				html += '<p><button type="button" class="button button-small seocp-apply-faq-selected">選択したFAQをこの記事に反映</button>'
					+ ' <span class="seocp-apply-faq-result"></span></p>';
			} else {
				html += '<div>' + escHtml(res.data.raw).replace(/\n/g, '<br>') + '</div>';
			}
			$out.html(html);
		}).fail(function () {
			$out.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('FAQ案');
		});
	});

	$(document).on('click', '.seocp-apply-faq-selected', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $result = $row.find('.seocp-apply-faq-result');
		var indices = [];
		$row.find('.seocp-faq-item-check:checked').each(function () {
			indices.push($(this).data('index'));
		});

		if (!indices.length) {
			$result.html('<span class="seocp-badge-fail">反映するFAQを選択してください。</span>');
			return;
		}
		if (!window.confirm('選択した' + indices.length + '件のFAQをこの記事に追記します。よろしいですか？')) {
			return;
		}

		$btn.prop('disabled', true).text('反映中...');
		$result.html('');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_apply_faq',
			nonce: SEOCP.nonce,
			post_id: postId,
			indices: indices
		}).done(function (res) {
			if (!res.success) {
				$result.html('<span class="seocp-badge-fail">エラー: ' + escHtml(res.data.message) + '</span>');
				return;
			}
			$result.html('<span class="seocp-badge-pass">' + res.data.applied_count + '件を記事に反映しました</span>');
			refreshPreviewIfOpen($row, res.data);
		}).fail(function () {
			$result.html('<span class="seocp-badge-fail">通信エラーが発生しました。</span>');
		}).always(function () {
			$btn.prop('disabled', false).text('選択したFAQをこの記事に反映');
		});
	});

	$(document).on('click', '.seocp-gen-links', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $out = $row.find('.seocp-result-links');
		$row.find('.seocp-result-empty').hide();

		$btn.prop('disabled', true).text('生成中...');
		$out.html('<p>内部リンク候補を探しています…</p>');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_generate_internal_links',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				$out.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var links = res.data.links;
			var html = '<strong>内部リンク候補:</strong>';
			if (links && links.length) {
				html += '<ul class="seocp-links-suggestion-list">';
				$.each(links, function (i, l) {
					html += '<li>'
						+ '<label>'
						+ '<input type="checkbox" class="seocp-link-item-check" data-index="' + i + '" checked> '
						+ '<a href="' + l.url + '" target="_blank" rel="noopener">' + escHtml(l.title) + '</a>'
						+ '　アンカー案:「' + escHtml(l.anchor) + '」'
						+ (l.reason ? '　(' + escHtml(l.reason) + ')' : '')
						+ '</label>'
						+ '</li>';
				});
				html += '</ul>';
				html += '<p><button type="button" class="button button-small seocp-apply-links-selected">選択した内部リンクをこの記事に反映</button>'
					+ ' <span class="seocp-apply-links-result"></span></p>';
			} else {
				html += '<div>' + escHtml(res.data.raw).replace(/\n/g, '<br>') + '</div>';
			}
			$out.html(html);
		}).fail(function () {
			$out.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('内部リンク候補');
		});
	});

	$(document).on('click', '.seocp-apply-links-selected', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $result = $row.find('.seocp-apply-links-result');
		var indices = [];
		$row.find('.seocp-link-item-check:checked').each(function () {
			indices.push($(this).data('index'));
		});

		if (!indices.length) {
			$result.html('<span class="seocp-badge-fail">反映する内部リンクを選択してください。</span>');
			return;
		}
		if (!window.confirm('選択した' + indices.length + '件の内部リンクをこの記事に追記します。よろしいですか？')) {
			return;
		}

		$btn.prop('disabled', true).text('反映中...');
		$result.html('');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_apply_links',
			nonce: SEOCP.nonce,
			post_id: postId,
			indices: indices
		}).done(function (res) {
			if (!res.success) {
				$result.html('<span class="seocp-badge-fail">エラー: ' + escHtml(res.data.message) + '</span>');
				return;
			}
			$result.html('<span class="seocp-badge-pass">' + res.data.applied_count + '件を記事に反映しました</span>');
			refreshPreviewIfOpen($row, res.data);
		}).fail(function () {
			$result.html('<span class="seocp-badge-fail">通信エラーが発生しました。</span>');
		}).always(function () {
			$btn.prop('disabled', false).text('選択した内部リンクをこの記事に反映');
		});
	});

	$(document).on('click', '.seocp-gen-draft', function () {
		var $btn = $(this);
		var $row = $btn.closest('tbody');
		var postId = $row.data('post-id');
		var $out = $row.find('.seocp-result-draft');
		$row.find('.seocp-result-empty').hide();

		var useTitle = $row.find('.seocp-opt-title').is(':checked') ? '1' : '0';
		var addFaq = $row.find('.seocp-opt-faq').is(':checked') ? '1' : '0';
		var addLinks = $row.find('.seocp-opt-links').is(':checked') ? '1' : '0';

		$btn.prop('disabled', true).text('下書き生成中...(タイトル/FAQ/リンクを未生成の場合は先に生成します)');
		$out.html('<p>改善案を反映した下書きを作成しています…</p>');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_generate_draft',
			nonce: SEOCP.nonce,
			post_id: postId,
			use_title: useTitle,
			add_faq: addFaq,
			add_links: addLinks
		}).done(function (res) {
			if (!res.success) {
				$out.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var d = res.data;
			var html = '<p class="seocp-badge-pass">下書きを作成しました。</p>';
			html += '<p>タイトル: ' + escHtml(d.title_used) + '<br>';
			html += 'FAQ: ' + d.faq_count + '件 / 内部リンク: ' + d.link_count + '件</p>';
			html += '<a class="button button-primary" href="' + d.edit_url + '" target="_blank" rel="noopener">下書きを編集</a> ';
			html += '<button type="button" class="button seocp-toggle-draft-diff">元記事との差分を見る</button>';
			html += '<div class="seocp-draft-diff-panel" style="display:none; margin-top:10px;">';
			if (d.title_diff_html && d.title_diff_html.indexOf('変更なし') === -1) {
				html += '<p><strong>タイトルの差分:</strong></p><div class="seocp-diff-preview-box">' + d.title_diff_html + '</div>';
			}
			html += '<p style="margin-top:10px;"><strong>本文の差分(削除: <del>赤打消線</del> / 追加: <ins>緑下線</ins>):</strong></p>';
			html += '<div class="seocp-diff-preview-box">' + d.content_diff_html + '</div>';
			html += '</div>';
			$out.html(html);
		}).fail(function () {
			$out.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('「改善」下書きを生成');
		});
	});

	$(document).on('click', '.seocp-toggle-draft-diff', function () {
		$(this).closest('.seocp-result-draft').find('.seocp-draft-diff-panel').toggle();
	});

	/* ----------------- プレビュー/編集パネル ----------------- */

	// タイトル案/FAQ案/内部リンク候補の「反映」操作後、このtbody内でプレビューパネルが
	// 既に開かれている(読み込み済みの)場合は、返ってきたデータでその場を更新する。
	function refreshPreviewIfOpen($row, data) {
		var $panelRow = $row.find('.seocp-preview-row');
		if (!$panelRow.data('loaded')) {
			return;
		}
		populateContentPreview($panelRow.find('.seocp-preview-panel'), data);
	}

	function populateContentPreview($panel, data) {
		$panel.data('post-id', data.post_id);
		$panel.find('.seocp-preview-title').val(data.title);
		$panel.find('.seocp-preview-editor').val(data.content);
		$panel.find('.seocp-preview-rendered').html(data.preview_html);
		$panel.find('.seocp-open-editor').attr('href', data.edit_url);
	}

	$(document).on('click', '.seocp-toggle-preview', function () {
		var $row = $(this).closest('tbody');
		var postId = $row.data('post-id');
		var $panelRow = $row.find('.seocp-preview-row');
		var $panel = $panelRow.find('.seocp-preview-panel');

		if ($panelRow.is(':visible')) {
			$panelRow.hide();
			return;
		}

		if ($panelRow.data('loaded')) {
			$panelRow.show();
			return;
		}

		$panel.find('.seocp-preview-msg').css('color', '').text('読み込み中…');
		$panelRow.show();

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_get_post_preview',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (res.success) {
				populateContentPreview($panel, res.data);
				$panel.find('.seocp-preview-msg').text('');
				$panelRow.data('loaded', true);
			} else {
				$panel.find('.seocp-preview-msg').css('color', '#b32d2e').text('エラー: ' + (res.data && res.data.message ? res.data.message : '不明なエラー'));
			}
		}).fail(function () {
			$panel.find('.seocp-preview-msg').css('color', '#b32d2e').text('通信エラーが発生しました。');
		});
	});

	$(document).on('click', '.seocp-close-preview', function () {
		$(this).closest('.seocp-preview-row').hide();
	});

	$(document).on('click', '.seocp-save-preview', function () {
		var $btn = $(this);
		var $panel = $btn.closest('.seocp-preview-panel');
		var $row = $panel.closest('tbody');
		var postId = $panel.data('post-id');
		var $status = $panel.find('.seocp-preview-save-status');

		$btn.prop('disabled', true);
		$status.css('color', '').text('保存中…');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_save_post_content',
			nonce: SEOCP.nonce,
			post_id: postId,
			title: $panel.find('.seocp-preview-title').val(),
			content: $panel.find('.seocp-preview-editor').val()
		}).done(function (res) {
			if (res.success) {
				populateContentPreview($panel, res.data);
				$status.css('color', '#00a32a').text('保存しました。');
				// 一覧側のタイトル表示も合わせて更新する
				$row.find('.seocp-current-title').text(res.data.title);
				$('.seocp-post-title-' + postId).text(res.data.title);
			} else {
				$status.css('color', '#b32d2e').text('エラー: ' + (res.data && res.data.message ? res.data.message : '不明なエラー'));
			}
		}).fail(function () {
			$status.css('color', '#b32d2e').text('通信エラーが発生しました。');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	/* ----------------- インデックス登録状況 ----------------- */

	$(document).on('click', '.seocp-check-index', function () {
		var $btn = $(this);
		var $row = $btn.closest('tr');
		var postId = $row.data('post-id');

		$btn.prop('disabled', true).text('確認中...');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_check_index_status',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				alert('エラー: ' + res.data.message);
				return;
			}
			var d = res.data;
			var statusHtml = d.verdict === 'PASS'
				? '<span class="seocp-badge-pass">インデックス済み</span>'
				: '<span class="seocp-badge-fail">未インデックス/要確認</span>';
			$row.find('.seocp-index-status-cell').html(statusHtml);
			$row.find('.seocp-index-detail-cell').html(
				'coverageState: ' + escHtml(d.coverage_state) + '<br>最終確認: たった今'
			);

			var $actionCell = $row.find('td').last();
			if ($actionCell.find('a').length === 0) {
				$actionCell.append(
					'<br><a href="' + d.inspect_url + '" target="_blank" rel="noopener" style="font-size:12px;">Search Consoleで確認/登録リクエスト</a>'
				);
			}

		}).always(function () {
			$btn.prop('disabled', false).text('インデックス状況を確認');
		});
	});

	/* ----------------- 更新履歴・順位変化 ----------------- */

	function buildHistorySvg(history, revisionDates, title) {
		var width = 800, height = 320;
		var padding = { top: 30, right: 30, bottom: 50, left: 50 };
		var innerW = width - padding.left - padding.right;
		var innerH = height - padding.top - padding.bottom;

		var positions = history.map(function (h) { return parseFloat(h.position); });
		var clicksArr = history.map(function (h) { return parseInt(h.clicks, 10); });
		var maxPos = Math.max.apply(null, positions);
		var minPos = Math.min.apply(null, positions);
		var maxClicks = Math.max.apply(null, clicksArr) || 1;

		var yMax = Math.ceil(maxPos + 2);
		var yMin = Math.max(1, Math.floor(minPos - 1));
		if (yMax === yMin) { yMax = yMin + 1; }

		var n = history.length;
		var xStep = n > 1 ? innerW / (n - 1) : 0;

		function xPos(i) { return padding.left + i * xStep; }
		function yPosForPosition(v) {
			var ratio = (v - yMin) / (yMax - yMin);
			return padding.top + ratio * innerH;
		}
		function yPosForClicks(v) {
			var ratio = v / maxClicks;
			return padding.top + innerH - (ratio * innerH * 0.4);
		}

		var positionPoints = history.map(function (h, i) {
			return xPos(i) + ',' + yPosForPosition(parseFloat(h.position));
		}).join(' ');

		var svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" style="width:100%; height:auto; font-family:sans-serif;">';

		svg += '<line x1="' + padding.left + '" y1="' + padding.top + '" x2="' + padding.left + '" y2="' + (padding.top + innerH) + '" stroke="#ccc"/>';
		svg += '<line x1="' + padding.left + '" y1="' + (padding.top + innerH) + '" x2="' + (padding.left + innerW) + '" y2="' + (padding.top + innerH) + '" stroke="#ccc"/>';

		svg += '<text x="6" y="' + (padding.top + 10) + '" font-size="11" fill="#666">' + yMin + '位</text>';
		svg += '<text x="6" y="' + (padding.top + innerH) + '" font-size="11" fill="#666">' + yMax + '位</text>';
		svg += '<text x="' + padding.left + '" y="16" font-size="11" fill="#2271b1">— 平均順位(上ほど好成績・グレー棒はクリック数)</text>';

		(revisionDates || []).forEach(function (rd) {
			var idx = -1;
			history.forEach(function (h, i) { if (h.data_date === rd) { idx = i; } });
			if (idx === -1) {
				var minDiff = Infinity;
				history.forEach(function (h, i) {
					var diff = Math.abs(new Date(h.data_date) - new Date(rd));
					if (diff < minDiff) { minDiff = diff; idx = i; }
				});
			}
			if (idx >= 0) {
				var x = xPos(idx);
				svg += '<line x1="' + x + '" y1="' + padding.top + '" x2="' + x + '" y2="' + (padding.top + innerH) + '" stroke="#d63638" stroke-dasharray="3,3"/>';
				svg += '<text x="' + x + '" y="' + (padding.top - 2) + '" font-size="9" fill="#d63638" text-anchor="middle">更新</text>';
			}
		});

		history.forEach(function (h, i) {
			var x = xPos(i) - 6;
			var yTop = yPosForClicks(parseInt(h.clicks, 10));
			var yBase = padding.top + innerH;
			svg += '<rect x="' + x + '" y="' + yTop + '" width="12" height="' + (yBase - yTop) + '" fill="#dcdcde"/>';
		});

		svg += '<polyline points="' + positionPoints + '" fill="none" stroke="#2271b1" stroke-width="2"/>';
		history.forEach(function (h, i) {
			var x = xPos(i);
			var y = yPosForPosition(parseFloat(h.position));
			svg += '<circle cx="' + x + '" cy="' + y + '" r="3" fill="#2271b1"><title>' + h.data_date + ' 順位:' + h.position + ' クリック:' + h.clicks + '</title></circle>';
		});

		var labelEvery = Math.max(1, Math.ceil(n / 8));
		history.forEach(function (h, i) {
			if (i % labelEvery === 0 || i === n - 1) {
				svg += '<text x="' + xPos(i) + '" y="' + (padding.top + innerH + 15) + '" font-size="9" fill="#666" text-anchor="middle">' + h.data_date.substr(5) + '</text>';
			}
		});

		svg += '</svg>';
		svg += '<p style="font-size:12px; color:#666;">グレーの棒: クリック数(相対値) ／ 赤の破線: コンテンツ更新日</p>';
		return '<h3>' + escHtml(title) + '</h3>' + svg;
	}

	function buildHistoryTable(history) {
		var html = '<table class="widefat striped"><thead><tr><th>日付</th><th>クリック</th><th>表示回数</th><th>CTR</th><th>平均順位</th></tr></thead><tbody>';
		history.slice().reverse().forEach(function (h) {
			html += '<tr><td>' + h.data_date + '</td><td>' + h.clicks + '</td><td>' + h.impressions + '</td><td>'
				+ (Math.round(h.ctr * 10000) / 100) + '%</td><td>' + (Math.round(h.position * 10) / 10) + '</td></tr>';
		});
		html += '</tbody></table>';
		return html;
	}

	$(document).on('click', '#seocp-history-load', function () {
		var postId = $('#seocp-history-post-select').val();
		if (!postId) {
			alert('ページを選択してください。');
			return;
		}
		var $btn = $(this);
		var $chart = $('#seocp-history-chart-container');
		var $table = $('#seocp-history-table-container');

		$btn.prop('disabled', true).text('読み込み中...');
		$chart.html('<p>データを読み込んでいます…</p>');
		$table.html('');

		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_get_history_data',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success) {
				$chart.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var history = res.data.history;
			if (!history || !history.length) {
				$chart.html('<p>このページの履歴データがまだありません。「Search Consoleデータ」画面で複数回データを取得すると、ここに履歴が表示されます。</p>');
				return;
			}
			$chart.html(buildHistorySvg(history, res.data.revision_dates, res.data.title));
			$table.html(buildHistoryTable(history));
			currentHistoryPostId = postId;
			$('#seocp-effect-tracking-card').show();
			loadEffectRecords(postId);
		}).fail(function () {
			$chart.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('表示');
		});
	});

	/* ----------------- v0.7.0: 効果測定 ----------------- */

	var currentHistoryPostId = null;

	function loadEffectRecords(postId) {
		var $container = $('#seocp-effect-records');
		if (!$container.length) return;
		$container.html('<em>読み込み中...</em>');
		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_get_effect_records',
			nonce: SEOCP.nonce,
			post_id: postId
		}).done(function (res) {
			if (!res.success || !res.data.records.length) {
				$container.html('<p class="description">まだ記録がありません。「この時点を基準値として記録」を押すと、以後の変化を追跡できます。</p>');
				return;
			}
			var html = '';
			$.each(res.data.records, function (i, r) {
				html += '<div class="seocp-effect-record" data-tracking-id="' + r.id + '" style="border:1px solid #dcdcde; border-radius:4px; padding:10px; margin-top:8px;">';
				html += '<strong>' + (r.note || '(メモなし)') + '</strong>　<span style="color:#666;font-size:12px;">開始: ' + r.started_at + '</span><br>';
				html += '基準値(直近28日平均): クリック ' + r.baseline_clicks + ' / 表示 ' + r.baseline_impressions + ' / CTR ' + (r.baseline_ctr * 100).toFixed(2) + '% / 順位 ' + parseFloat(r.baseline_position).toFixed(1) + '<br>';
				html += '<button type="button" class="button seocp-effect-compare" style="margin-top:6px;">現在と比較する</button>';
				html += '<div class="seocp-effect-comparison-result" style="margin-top:8px;"></div>';
				html += '</div>';
			});
			$container.html(html);
		});
	}

	$(document).on('click', '#seocp-effect-start', function () {
		if (!currentHistoryPostId) return;
		var $btn = $(this);
		var note = $('#seocp-effect-note').val();
		$btn.prop('disabled', true).text('記録中...');
		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_start_effect_tracking',
			nonce: SEOCP.nonce,
			post_id: currentHistoryPostId,
			note: note
		}).done(function (res) {
			if (!res.success) {
				alert('エラー: ' + res.data.message);
				return;
			}
			$('#seocp-effect-note').val('');
			loadEffectRecords(currentHistoryPostId);
		}).fail(function () {
			alert('通信エラーが発生しました。');
		}).always(function () {
			$btn.prop('disabled', false).text('この時点を基準値として記録');
		});
	});

	$(document).on('click', '.seocp-effect-compare', function () {
		var $btn = $(this);
		var $record = $btn.closest('.seocp-effect-record');
		var trackingId = $record.data('tracking-id');
		var $result = $record.find('.seocp-effect-comparison-result');

		$btn.prop('disabled', true).text('比較中...');
		$.post(SEOCP.ajaxUrl, {
			action: 'seocp_get_effect_comparison',
			nonce: SEOCP.nonce,
			tracking_id: trackingId
		}).done(function (res) {
			if (!res.success) {
				$result.html('<p class="seocp-badge-fail">エラー: ' + res.data.message + '</p>');
				return;
			}
			var d = res.data;
			var html = '<table class="widefat striped" style="max-width:520px;">';
			html += '<thead><tr><th></th><th>基準値</th><th>それ以降</th></tr></thead><tbody>';
			html += '<tr><td>期間</td><td>' + d.baseline.period + '</td><td>' + d.followup.period + '</td></tr>';
			html += '<tr><td>クリック数</td><td>' + d.baseline.clicks + '</td><td>' + d.followup.clicks + '</td></tr>';
			html += '<tr><td>表示回数</td><td>' + d.baseline.impressions + '</td><td>' + d.followup.impressions + '</td></tr>';
			html += '<tr><td>CTR</td><td>' + (d.baseline.ctr * 100).toFixed(2) + '%</td><td>' + (d.followup.ctr * 100).toFixed(2) + '%'
				+ (d.ctr_change_pct !== undefined ? ' (' + (d.ctr_change_pct > 0 ? '+' : '') + d.ctr_change_pct + '%)' : '') + '</td></tr>';
			html += '<tr><td>平均順位</td><td>' + parseFloat(d.baseline.position).toFixed(1) + '</td><td>' + parseFloat(d.followup.position).toFixed(1)
				+ (d.position_change !== undefined ? ' (' + (d.position_change > 0 ? '改善 +' + d.position_change : d.position_change) + ')' : '') + '</td></tr>';
			html += '</tbody></table>';
			if (d.followup.days_with_data < 3) {
				html += '<p class="description">※比較対象期間のデータがまだ少ないため(' + d.followup.days_with_data + '日分)、参考値としてご覧ください。</p>';
			}
			$result.html(html);
		}).fail(function () {
			$result.html('<p class="seocp-badge-fail">通信エラーが発生しました。</p>');
		}).always(function () {
			$btn.prop('disabled', false).text('現在と比較する');
		});
	});

	/* ----------------- CTR改善: 提案ページへのジャンプ先ハイライト ----------------- */

	if ( window.location.hash && window.location.hash.indexOf('#seocp-post-') === 0 ) {
		var $target = $(window.location.hash);
		if ( $target.length ) {
			$target.addClass('seocp-highlight-target');
			setTimeout(function () {
				$('html, body').animate({ scrollTop: $target.offset().top - 40 }, 300);
			}, 100);
		}
	}

})(jQuery);
