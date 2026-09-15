<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI検索最適化(LLMO/AEO)チェッククラス
 *
 * チェック項目:
 * 1. サイト全体: robots.txt がAIクローラー(GPTBot, ClaudeBot, PerplexityBot, Google-Extended等)を許可しているか
 * 2. サイト全体: llms.txt の有無
 * 3. 記事単位: 構造化データ(JSON-LD)の有無 (FAQPage / Article / HowTo / BreadcrumbList)
 * 4. 記事単位: 見出し構造(H2/H3)・質問形式見出しの有無
 * 5. 記事単位: 冒頭で結論/要約を提示しているか(最初の段落の長さ・位置で簡易判定)
 * 6. 記事単位: リスト・表など引用されやすい構造の有無
 * 7. 記事単位: 著者情報・更新日の有無(E-E-A-T)
 */
class SEOCP_LLMO_Checker {

	/** AI検索エンジンの代表的なクローラーUA */
	private $ai_bots = array(
		'GPTBot',
		'ChatGPT-User',
		'ClaudeBot',
		'anthropic-ai',
		'PerplexityBot',
		'Google-Extended',
		'CCBot',
		'Applebot-Extended',
	);

	/**
	 * サイト全体のチェック(robots.txt / llms.txt)
	 */
	public function check_site_wide() {
		$result = array(
			'robots_txt' => $this->check_robots_txt(),
			'llms_txt'   => $this->check_llms_txt(),
		);
		return $result;
	}

	private function check_robots_txt() {
		$url = home_url( '/robots.txt' );
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'exists'  => false,
				'blocked' => array(),
				'allowed' => array(),
				'note'    => 'robots.txtを取得できませんでした。',
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$blocked = array();
		$allowed = array();

		foreach ( $this->ai_bots as $bot ) {
			// 該当UAブロックにDisallow: / があるかを簡易判定
			if ( preg_match( '/User-agent:\s*' . preg_quote( $bot, '/' ) . '\s*\n(.*?)(?:\nUser-agent:|\z)/is', $body, $m ) ) {
				if ( preg_match( '/Disallow:\s*\/\s*$/mi', $m[1] ) ) {
					$blocked[] = $bot;
				} else {
					$allowed[] = $bot;
				}
			}
		}

		return array(
			'exists'  => true,
			'blocked' => $blocked,
			'allowed' => $allowed,
			'note'    => '',
		);
	}

	private function check_llms_txt() {
		$url = home_url( '/llms.txt' );
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		$exists = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );

		return array(
			'exists' => $exists,
			'url'    => $url,
		);
	}

	/**
	 * 個別投稿のチェック
	 *
	 * @param int $post_id
	 * @return array details + score
	 */
	public function check_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$content_raw    = $post->post_content;
		$content_html   = apply_filters( 'the_content', $content_raw );
		$plain_text     = wp_strip_all_tags( $content_html );

		$checks = array();

		// 1. 構造化データ
		$schema = $this->detect_schema( $post_id, $content_html );
		$checks['structured_data'] = $schema;

		// 2. 見出し構造
		$headings = $this->analyze_headings( $content_html );
		$checks['headings'] = $headings;

		// 3. 冒頭要約
		$checks['intro_summary'] = $this->check_intro_summary( $content_html );

		// 4. リスト/表
		$checks['scannable_structure'] = $this->check_scannable_structure( $content_html );

		// 5. 文字数(極端に短いと引用されにくい)
		$checks['content_length'] = array(
			'chars' => mb_strlen( $plain_text ),
			'pass'  => mb_strlen( $plain_text ) >= 400,
		);

		$score = $this->calculate_score( $checks );

		return array(
			'post_id' => $post_id,
			'score'   => $score,
			'checks'  => $checks,
		);
	}

	private function detect_schema( $post_id, $content_html ) {
		$found = array();

		// 本文中のJSON-LDブロック(ブロックエディタでHTMLブロックに直書きされているケース等)
		if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $content_html, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$data = json_decode( $json, true );
				if ( is_array( $data ) && isset( $data['@type'] ) ) {
					$found[] = $data['@type'];
				}
			}
		}

		// 有名SEOプラグイン(Yoast/RankMath等)が出力する構造化データはページ全体のレンダリング時に付与されるため、
		// 投稿本文だけでは検出できないケースがある。その場合はフロントのURLを実際に取得して確認する。
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			$resp = wp_remote_get( $permalink, array( 'timeout' => 10 ) );
			if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
				$html = wp_remote_retrieve_body( $resp );
				if ( preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches2 ) ) {
					foreach ( $matches2[1] as $json ) {
						$data = json_decode( $json, true );
						if ( is_array( $data ) ) {
							if ( isset( $data['@type'] ) ) {
								$found[] = $data['@type'];
							} elseif ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
								foreach ( $data['@graph'] as $node ) {
									if ( isset( $node['@type'] ) ) {
										$found[] = $node['@type'];
									}
								}
							}
						}
					}
				}
			}
		}

		$found = array_unique( array_map( function( $t ) {
			return is_array( $t ) ? implode( ',', $t ) : $t;
		}, $found ) );

		return array(
			'types'         => array_values( $found ),
			'has_faq'       => in_array( 'FAQPage', $found, true ),
			'has_article'   => in_array( 'Article', $found, true ) || in_array( 'BlogPosting', $found, true ),
			'has_breadcrumb'=> in_array( 'BreadcrumbList', $found, true ),
			'pass'          => ! empty( $found ),
		);
	}

	private function analyze_headings( $content_html ) {
		preg_match_all( '/<h([2-3])[^>]*>(.*?)<\/h\1>/is', $content_html, $matches );
		$headings = array_map( 'wp_strip_all_tags', $matches[2] );

		$question_like = array_filter( $headings, function( $h ) {
			return (bool) preg_match( '/(とは|なぜ|どう|何|\?|？)/u', $h );
		} );

		return array(
			'count'          => count( $headings ),
			'question_count' => count( $question_like ),
			'pass'           => count( $headings ) >= 2,
		);
	}

	private function check_intro_summary( $content_html ) {
		// 最初のpタグの文字数をチェック(短すぎ/長すぎは結論提示になっていない可能性)
		if ( preg_match( '/<p[^>]*>(.*?)<\/p>/is', $content_html, $m ) ) {
			$first_para = wp_strip_all_tags( $m[1] );
			$len = mb_strlen( $first_para );
			return array(
				'chars' => $len,
				'pass'  => $len >= 40 && $len <= 300,
			);
		}
		return array( 'chars' => 0, 'pass' => false );
	}

	private function check_scannable_structure( $content_html ) {
		$has_list  = (bool) preg_match( '/<(ul|ol)[^>]*>/i', $content_html );
		$has_table = (bool) preg_match( '/<table[^>]*>/i', $content_html );
		return array(
			'has_list'  => $has_list,
			'has_table' => $has_table,
			'pass'      => $has_list || $has_table,
		);
	}

	private function calculate_score( $checks ) {
		// 各項目の重み付け(合計100点)
		$weights = array(
			'structured_data'     => 20,
			'headings'            => 20,
			'intro_summary'       => 20,
			'scannable_structure' => 20,
			'content_length'      => 20,
		);

		$score = 0;
		foreach ( $weights as $key => $weight ) {
			if ( ! empty( $checks[ $key ]['pass'] ) ) {
				$score += $weight;
			}
		}

		// 構造化データの内訳で加点(FAQがあれば特に強い)
		if ( ! empty( $checks['structured_data']['has_faq'] ) ) {
			$score = min( 100, $score + 5 );
		}

		return (int) $score;
	}

	/**
	 * チェック結果をDBに保存
	 */
	public function save_result( $post_id, $result ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_LLMO;

		$wpdb->insert(
			$table,
			array(
				'post_id'    => $post_id,
				'score'      => $result['score'],
				'details'    => wp_json_encode( $result['checks'] ),
				'checked_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		return $wpdb->insert_id;
	}

	/**
	 * 指定投稿の最新チェック結果を取得
	 */
	public function get_latest_result( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_LLMO;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d ORDER BY checked_at DESC LIMIT 1",
				$post_id
			),
			ARRAY_A
		);

		if ( $row && ! empty( $row['details'] ) ) {
			$row['details'] = json_decode( $row['details'], true );
		}

		return $row;
	}

	/**
	 * AIによる改善提案プロンプトを組み立てて生成する
	 */
	public function generate_ai_suggestion( $post_id, $checks ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$issues = array();
		if ( empty( $checks['structured_data']['pass'] ) ) {
			$issues[] = '構造化データ(JSON-LD)が見つかりません。特にFAQPageやArticleスキーマがAI検索に引用されやすくなります。';
		}
		if ( empty( $checks['headings']['pass'] ) ) {
			$issues[] = '見出し(H2/H3)が少なく、質問形式の見出しもほぼありません。';
		}
		if ( empty( $checks['intro_summary']['pass'] ) ) {
			$issues[] = '冒頭で結論・要約が簡潔に提示されていません。';
		}
		if ( empty( $checks['scannable_structure']['pass'] ) ) {
			$issues[] = 'リストや表など、AIが引用しやすい構造がありません。';
		}
		if ( empty( $checks['content_length']['pass'] ) ) {
			$issues[] = '本文が短く(400文字未満)、AI検索エンジンが引用できる情報量が不足している可能性があります。';
		}

		$issues_text = empty( $issues ) ? '大きな課題は見つかりませんでした。' : "- " . implode( "\n- ", $issues );

		$excerpt = wp_strip_all_tags( $post->post_content );
		$excerpt = mb_substr( $excerpt, 0, 1500 );

		$prompt = <<<PROMPT
あなたはSEOおよびAI検索最適化(LLMO/AEO: LLM Optimization / Answer Engine Optimization)の専門家です。
以下の記事について、AI検索エンジン(ChatGPT検索、Perplexity、Google AI Overviewsなど)に引用・参照されやすくするための具体的な改善案を、日本語の箇条書きで5つ以内、簡潔に提示してください。
各項目は「何を」「どう直すか」が分かるように具体的に書いてください。

【記事タイトル】
{$post->post_title}

【検出された課題】
{$issues_text}

【本文抜粋(先頭1500文字)】
{$excerpt}
PROMPT;

		$ai = new SEOCP_AI_Provider();
		$text = $ai->generate_text( $prompt );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_LLMO;
		$wpdb->update(
			$table,
			array( 'ai_suggestion' => $text ),
			array( 'post_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $text;
	}

	/**
	 * AI改善提案のテキスト(箇条書き)を1項目ずつの配列に分解する。
	 * 反映画面でチェックボックスとして1件ずつ選べるようにするために使う。
	 * 先頭の「- 」「・」「1. 」等のマーカーは取り除く。
	 * プロンプトで「5つ以内」を指示していてもAIが守らないことがあるため、
	 * ここで最大件数を強制的に切り詰める。
	 */
	const MAX_SUGGESTION_ITEMS = 5;

	public function parse_suggestion_items( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$items = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$line = preg_replace( '/^[\-\*・]\s*|^\d+[\.\)、]\s*/u', '', $line );
			$line = trim( $line );
			if ( '' !== $line ) {
				$items[] = $line;
			}
			if ( count( $items ) >= self::MAX_SUGGESTION_ITEMS ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * 生成済みのAI改善提案を元に、AIが実際に本文を書き直し、元記事に直接反映する。
	 * 別の下書き投稿は作成せず、対象記事の本文を直接更新する。
	 * (更新前の本文はWordPressのリビジョン機能により復元可能)
	 * (提案テキストをそのまま貼り付けるのではなく、提案を踏まえてAIが本文自体を生成し直す)
	 *
	 * 提案(ai_suggestion)はDBに保存済みのものを使うため、この処理が失敗しても
	 * 「AI改善提案を生成」からやり直す必要はなく、この関数を再実行するだけで良い。
	 */
	/**
	 * v0.7.0: 本文を直接上書きする前に、まず改訂案を生成して「差分プレビュー」を返す。
	 * 実際の上書きは行わず、確認用にトランジェントへ一時保存する(30分間有効)。
	 * ユーザーが差分を確認して問題なければ confirm_apply_suggestion() を呼んで初めて反映される。
	 *
	 * @param int        $post_id
	 * @param array|null $selected_indices 反映する提案項目のインデックス(0始まり、parse_suggestion_items()基準)の配列。
	 *   null または空配列の場合は提案を全件まとめて反映する(v0.7.0までの旧仕様)。
	 *   一部の項目だけを選ぶと、AIへの指示がシンプルになり出力トークン量が減るため、
	 *   まとめて反映するよりトークン上限に達しにくくなる。複数回に分けて少しずつ
	 *   反映していく使い方もできる(v0.6.1のチェックボックス選択方式をv0.7系に移植・v0.7.3)。
	 */
	public function preview_apply_suggestion( $post_id, $selected_indices = null ) {
		if ( function_exists( 'set_time_limit' ) ) {
			// 記事を断片に分けて複数回リクエストするため、長めの記事でも完走できるよう余裕を持たせる
			@set_time_limit( 900 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$latest = $this->get_latest_result( $post_id );
		if ( empty( $latest['ai_suggestion'] ) ) {
			return new WP_Error( 'seocp_no_suggestion', '先に「AI改善提案を生成」を実行してください。' );
		}

		$suggestion_text = $latest['ai_suggestion'];

		// チェックボックスで選択された提案項目のインデックス(0始まり)。
		// 未指定の場合は全件反映(旧仕様)として扱う。
		if ( is_array( $selected_indices ) && ! empty( $selected_indices ) ) {
			$all_items      = $this->parse_suggestion_items( $latest['ai_suggestion'] );
			$selected_items = array();
			foreach ( $selected_indices as $idx ) {
				$idx = (int) $idx;
				if ( isset( $all_items[ $idx ] ) ) {
					$selected_items[] = $all_items[ $idx ];
				}
			}
			if ( empty( $selected_items ) ) {
				return new WP_Error( 'seocp_no_suggestion_selected', '反映する改善提案が選択されていません。少なくとも1件チェックしてください。' );
			}
			$suggestion_text = '- ' . implode( "\n- ", $selected_items );
		}

		$checks = ! empty( $latest['details'] ) ? $latest['details'] : array();

		$revised_content = $this->generate_revised_content( $post, $checks, $suggestion_text );
		if ( is_wp_error( $revised_content ) ) {
			return $revised_content;
		}

		$diff_html = SEOCP_Diff::html_diff( $post->post_content, $revised_content, true );

		set_transient( 'seocp_pending_revision_' . $post_id, $revised_content, 30 * MINUTE_IN_SECONDS );

		return array(
			'post_id'     => $post_id,
			'diff_html'   => $diff_html,
			// v0.7.1: 断片分割で処理されたことを画面上でも確認できるよう件数を返す
			'chunk_count' => $this->last_chunk_count,
		);
	}

	/**
	 * preview_apply_suggestion() で生成済みの改訂案を、実際に本文へ反映(上書き)する。
	 */
	public function confirm_apply_suggestion( $post_id ) {
		$revised_content = get_transient( 'seocp_pending_revision_' . $post_id );
		if ( false === $revised_content ) {
			return new WP_Error( 'seocp_no_pending_revision', 'プレビューの有効期限が切れました。もう一度「この提案を記事に反映」からやり直してください。' );
		}

		$updated = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $revised_content,
		), true );

		delete_transient( 'seocp_pending_revision_' . $post_id );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// 反映後に自動で再チェックし、最新スコアを保存する(ここでスコアが更新される)
		$result = $this->check_post( $post_id );
		$score  = null;
		$checks_after = null;
		if ( ! is_wp_error( $result ) ) {
			$this->save_result( $post_id, $result );
			$score        = $result['score'];
			$checks_after = $result['checks'];
		}

		// v0.7.0: 反映と同時に効果測定の基準値記録を試みる(Search Consoleデータが無ければ静かに失敗させる)
		$tracker = new SEOCP_Effect_Tracker();
		$tracker->start_tracking( $post_id, 'AI改善提案(LLMO)を本文に反映' );

		return array(
			'post_id' => $post_id,
			'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'score'   => $score,
			'checks'  => $checks_after,
		);
	}

	/**
	 * v0.7.4: ロリポップ等、PHPの実行時間上限が固定(120秒程度)で延長できない共用サーバーでも
	 * 確実に完走できるようにするための「ステップ実行」方式。
	 *
	 * preview_apply_suggestion() は1回のリクエストで記事全体(複数断片)をまとめて処理するため、
	 * 記事が長い/断片数が多いとPHP実行時間がサーバー側の上限を超え、レスポンスが返る前に
	 * 接続が強制終了されてしまう(ブラウザ側では「通信エラー」として見える)。
	 *
	 * この方式では、1回のAjaxリクエストにつき「断片1つ」または「JSON-LD生成1回」だけを処理して
	 * 即座に結果を返す。フロント側(admin.js)はdone:trueが返るまでこれを繰り返し呼び出す。
	 * 状態はtransientに保存し、リクエストをまたいで引き継ぐ。
	 */
	public function start_revision_job( $post_id, $selected_indices = null ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$latest = $this->get_latest_result( $post_id );
		if ( empty( $latest['ai_suggestion'] ) ) {
			return new WP_Error( 'seocp_no_suggestion', '先に「AI改善提案を生成」を実行してください。' );
		}

		$suggestion_text = $latest['ai_suggestion'];

		if ( is_array( $selected_indices ) && ! empty( $selected_indices ) ) {
			$all_items      = $this->parse_suggestion_items( $latest['ai_suggestion'] );
			$selected_items = array();
			foreach ( $selected_indices as $idx ) {
				$idx = (int) $idx;
				if ( isset( $all_items[ $idx ] ) ) {
					$selected_items[] = $all_items[ $idx ];
				}
			}
			if ( empty( $selected_items ) ) {
				return new WP_Error( 'seocp_no_suggestion_selected', '反映する改善提案が選択されていません。少なくとも1件チェックしてください。' );
			}
			$suggestion_text = '- ' . implode( "\n- ", $selected_items );
		}

		$checks = ! empty( $latest['details'] ) ? $latest['details'] : array();

		$checks_missing_schema      = empty( $checks['structured_data']['pass'] );
		$suggestion_mentions_schema = (bool) preg_match(
			'/json-?ld|構造化データ|schema\.org|スキーマ|faqpage/iu',
			$suggestion_text
		);
		$wants_schema = $checks_missing_schema || $suggestion_mentions_schema;

		$chunks = $this->split_html_into_chunks( $post->post_content );
		if ( empty( $chunks ) ) {
			return new WP_Error( 'seocp_empty_content', '本文が空のため書き換えできません。' );
		}

		$job = array(
			'post_id'          => $post_id,
			'original_content' => $post->post_content,
			'suggestion_text'  => $suggestion_text,
			'post_title'       => $post->post_title,
			'chunks'           => $chunks,
			'revised_chunks'   => array_fill( 0, count( $chunks ), null ),
			'wants_schema'     => $wants_schema,
			'schema_snippet'   => '',
			'schema_done'      => ! $wants_schema,
			'cursor'           => 0,
			'total_steps'      => count( $chunks ) + ( $wants_schema ? 1 : 0 ),
			'done_steps'       => 0,
			'last_error'       => '',
		);

		set_transient( 'seocp_revision_job_' . $post_id, $job, HOUR_IN_SECONDS );

		return array(
			'total_steps' => $job['total_steps'],
			'done_steps'  => 0,
		);
	}

	/**
	 * ジョブの「1ステップ」だけを処理する(JSON-LD生成、または断片1つの書き換え)。
	 * 呼び出し元(Ajaxハンドラ)はdone:trueが返るまでこれを繰り返し呼び出す想定。
	 */
	public function process_revision_job_step( $post_id ) {
		$job = get_transient( 'seocp_revision_job_' . $post_id );
		if ( false === $job ) {
			return new WP_Error( 'seocp_no_job', '処理が中断されたか、有効期限が切れました。もう一度「差分を確認」からやり直してください。' );
		}

		$ai = new SEOCP_AI_Provider();

		// ステップ種別1: JSON-LD構造化データの生成(あれば最初の1回だけ)
		if ( ! $job['schema_done'] ) {
			$post = get_post( $post_id );
			$job['schema_snippet'] = $post ? $this->generate_schema_snippet( $ai, $post ) : '';
			$job['schema_done']    = true;
			$job['done_steps']++;
			set_transient( 'seocp_revision_job_' . $post_id, $job, HOUR_IN_SECONDS );

			return array(
				'done'        => false,
				'done_steps'  => $job['done_steps'],
				'total_steps' => $job['total_steps'],
			);
		}

		// ステップ種別2: 断片を1つだけ書き換える
		$idx = $job['cursor'];
		if ( $idx < count( $job['chunks'] ) ) {
			$chunk       = $job['chunks'][ $idx ];
			$chunk_chars = mb_strlen( trim( wp_strip_all_tags( $chunk ) ) );

			if ( $chunk_chars < 20 ) {
				// 見出しのみ/短い区切り等、実質的なテキストがほぼない断片はAIを介さずそのまま使う
				$job['revised_chunks'][ $idx ] = $chunk;
			} else {
				$prompt = <<<PROMPT
あなたはSEOおよびAI検索最適化(LLMO/AEO)の専門家兼編集者です。
これは記事本文の一部(断片)です。記事全体は複数の断片に分けて処理しているため、
この断片だけを対象に、「改善提案」のうちこの断片に関係するものだけを反映してください。
関係しない提案は無視して構いません(他の断片で対応します)。

【厳守事項】
- この断片にある事実・情報は一つも省略・削除・要約しないこと。
- 文字数はこの断片と同程度以上にすること。大幅に短くすることは禁止。
- 出力はこの断片の書き換え後のHTMLのみとすること(見出しは<h2>/<h3>、リストは<ul><li>、段落は<p>)。
- 前置きや説明文、コードブロックの```などは一切付けないこと。
- JSON-LD構造化データはここでは追加しないこと(必要であれば別途まとめて追加します)。

【記事タイトル(参考)】
{$job['post_title']}

【改善提案(記事全体向け。この断片に関係するものだけ適用)】
{$job['suggestion_text']}

【本文の断片】
{$chunk}
PROMPT;

				// ステップ方式では1リクエスト=1回のAI呼び出しで必ず完結させたいため、
				// レート制限時のセルフリトライ(sleep)はここでは行わない
				// (sleepしてしまうとサーバーのタイムアウトに引っかかる原因になるため)。
				// 失敗した場合はこの断片を元のまま採用し、呼び出し元が次のステップへ進む。
				$revised = $ai->generate_text( $prompt, 4096, 60, 'gemini', false );

				if ( is_wp_error( $revised ) ) {
					$job['last_error']              = $revised->get_error_message();
					$job['revised_chunks'][ $idx ] = $chunk;
				} else {
					$revised       = trim( preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( $revised ) ) );
					$revised_chars = mb_strlen( trim( wp_strip_all_tags( $revised ) ) );

					if ( '' === $revised || ( $chunk_chars > 0 && $revised_chars < $chunk_chars * 0.6 ) ) {
						// 空応答/大幅な短縮は内容欠落とみなし、元の断片を採用する
						$job['revised_chunks'][ $idx ] = $chunk;
					} else {
						$job['revised_chunks'][ $idx ] = $revised;
					}
				}
			}

			$job['cursor']++;
			$job['done_steps']++;

			if ( $job['cursor'] < count( $job['chunks'] ) ) {
				set_transient( 'seocp_revision_job_' . $post_id, $job, HOUR_IN_SECONDS );
				return array(
					'done'        => false,
					'done_steps'  => $job['done_steps'],
					'total_steps' => $job['total_steps'],
				);
			}
		}

		// 全ステップ完了: 結果を組み立てて差分を生成する
		$success_count = 0;
		foreach ( $job['revised_chunks'] as $i => $c ) {
			if ( null !== $c && $c !== $job['chunks'][ $i ] ) {
				$success_count++;
			}
		}

		if ( 0 === $success_count && empty( $job['schema_snippet'] ) ) {
			delete_transient( 'seocp_revision_job_' . $post_id );
			$detail = $job['last_error'] ? $job['last_error'] : '(詳細エラーなし。APIへ到達できていない可能性があります)';
			return new WP_Error(
				'seocp_all_chunks_failed',
				sprintf( 'すべての断片でAIによる書き換えに失敗しました。実際のエラー: %s', $detail )
			);
		}

		$text = '';
		foreach ( $job['revised_chunks'] as $i => $c ) {
			$text .= ( null !== $c ? $c : $job['chunks'][ $i ] ) . "\n";
		}
		$text = trim( $text );

		if ( ! empty( $job['schema_snippet'] ) ) {
			$text .= "\n" . $job['schema_snippet'];
		}

		$diff_html = SEOCP_Diff::html_diff( $job['original_content'], $text, true );

		delete_transient( 'seocp_revision_job_' . $post_id );
		set_transient( 'seocp_pending_revision_' . $post_id, $text, 30 * MINUTE_IN_SECONDS );

		return array(
			'done'        => true,
			'diff_html'   => $diff_html,
			'chunk_count' => count( $job['chunks'] ),
		);
	}

	/**
	 * 旧バージョン互換用のラッパー(プレビューなしで直接反映)。現在は管理画面からは呼ばれない。
	 */
	public function apply_suggestion_as_draft( $post_id ) {
		$preview = $this->preview_apply_suggestion( $post_id );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		return $this->confirm_apply_suggestion( $post_id );
	}

	/**
	 * v0.7.0: サイト内全記事(直近チェック済み)のスコア一覧を取得する(post_idごとの最新結果のみ)。
	 */
	public function get_all_latest_scores() {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_LLMO;
		$rows = $wpdb->get_results(
			"SELECT t1.post_id, t1.score, t1.checked_at FROM {$table} t1
			 INNER JOIN (
				SELECT post_id, MAX(checked_at) AS max_checked_at FROM {$table} GROUP BY post_id
			 ) t2 ON t1.post_id = t2.post_id AND t1.checked_at = t2.max_checked_at
			 ORDER BY t1.score ASC",
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * スコアが低い記事(未チェックの記事は含まない)の上位N件を返す
	 */
	public function get_low_score_posts( $limit = 10 ) {
		$scores = $this->get_all_latest_scores();
		return array_slice( $scores, 0, $limit );
	}

	/**
	 * 記事本文(HTML)を見出し・段落などのブロック単位の断片に分割する。
	 * 記事全体を1回のAPIリクエストで送ると入出力トークン量が大きくなり、
	 * 無料枠のTPM(1分あたりトークン数)上限に非常にかかりやすいため、
	 * ここで小さな断片に分けてから1つずつAIへ渡す。
	 *
	 * @param string $html
	 * @param int    $target_chars 断片1つあたりの目安文字数
	 * @return array<string>
	 */
	private function split_html_into_chunks( $html, $target_chars = 1200 ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return array();
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			// DOM拡張(ext-dom)が無効なホスティング環境でも分割を維持するため、
			// タグの閉じ位置を境界にした簡易分割にフォールバックする
			return $this->split_html_into_chunks_fallback( $html, $target_chars );
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$wrapped = '<?xml encoding="utf-8"?><div id="seocp-root">' . $html . '</div>';
		$loaded  = $dom->loadHTML( $wrapped, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return $this->split_html_into_chunks_fallback( $html, $target_chars );
		}

		$root = $dom->getElementById( 'seocp-root' );
		if ( ! $root ) {
			return $this->split_html_into_chunks_fallback( $html, $target_chars );
		}

		$chunks       = array();
		$buffer       = '';
		$buffer_chars = 0;

		foreach ( $root->childNodes as $node ) {
			$node_html = $dom->saveHTML( $node );
			if ( null === $node_html ) {
				continue;
			}

			$node_text  = wp_strip_all_tags( $node_html );
			$node_chars = mb_strlen( trim( $node_text ) );

			// 実質的なテキストを持たないノード(改行・コメント等)はバッファにそのまま足す
			if ( 0 === $node_chars ) {
				$buffer .= $node_html;
				continue;
			}

			// 単体で目標サイズを大きく超える要素(長い表・長いコード等)は、
			// バッファが空ならそのまま1断片として独立させる
			if ( $node_chars >= $target_chars * 1.5 && '' === trim( wp_strip_all_tags( $buffer ) ) ) {
				if ( '' !== trim( $buffer ) ) {
					$chunks[] = $buffer;
					$buffer   = '';
				}
				$chunks[]     = $node_html;
				$buffer_chars = 0;
				continue;
			}

			if ( $buffer_chars > 0 && ( $buffer_chars + $node_chars ) > $target_chars ) {
				$chunks[]     = $buffer;
				$buffer       = '';
				$buffer_chars = 0;
			}

			$buffer       .= $node_html;
			$buffer_chars += $node_chars;
		}

		if ( '' !== trim( wp_strip_all_tags( $buffer ) ) ) {
			$chunks[] = $buffer;
		}

		$result = ! empty( $chunks ) ? $chunks : array( $html );

		// DOM解析自体は成功したが、結果的に断片が1つ(=分割できなかった)場合は、
		// 記事全体がDOMの解釈上1つの塊(単一の外側要素で囲まれている等)になっている可能性があるため、
		// タグ境界ベースの簡易分割で再分割を試みる(それでも1つならそのまま採用する)。
		if ( count( $result ) <= 1 ) {
			$fallback_result = $this->split_html_into_chunks_fallback( $html, $target_chars );
			if ( count( $fallback_result ) > 1 ) {
				return $fallback_result;
			}
		}

		return $result;
	}

	/**
	 * DOMDocument拡張が使えない、またはDOM分割の結果1断片にしかならなかった場合の
	 * 簡易フォールバック分割。ブロック要素の閉じタグ(</p> </h2> 等)を境界として
	 * 本文を分割し、target_chars程度にまとめ直す。
	 *
	 * @param string $html
	 * @param int    $target_chars
	 * @return array<string>
	 */
	private function split_html_into_chunks_fallback( $html, $target_chars ) {
		$pattern = '/(<\/(?:p|h[1-6]|ul|ol|table|blockquote|figure|pre)>)/i';
		$parts   = preg_split( $pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( empty( $parts ) ) {
			return array( $html );
		}

		// preg_splitは「本文、閉じタグ、本文、閉じタグ...」の順で返るため、
		// 隣接する組を結合してブロック単位(閉じタグまで含む1つの要素)に戻す
		$blocks = array();
		$buf    = '';
		foreach ( $parts as $part ) {
			$buf .= $part;
			if ( preg_match( $pattern, $part ) ) {
				$blocks[] = $buf;
				$buf      = '';
			}
		}
		if ( '' !== trim( $buf ) ) {
			$blocks[] = $buf;
		}

		if ( empty( $blocks ) ) {
			return array( $html );
		}

		$chunks       = array();
		$buffer       = '';
		$buffer_chars = 0;

		foreach ( $blocks as $block ) {
			$block_chars = mb_strlen( trim( wp_strip_all_tags( $block ) ) );

			if ( 0 === $block_chars ) {
				$buffer .= $block;
				continue;
			}

			if ( $block_chars >= $target_chars * 1.5 && '' === trim( wp_strip_all_tags( $buffer ) ) ) {
				if ( '' !== trim( $buffer ) ) {
					$chunks[] = $buffer;
					$buffer   = '';
				}
				$chunks[]     = $block;
				$buffer_chars = 0;
				continue;
			}

			if ( $buffer_chars > 0 && ( $buffer_chars + $block_chars ) > $target_chars ) {
				$chunks[]     = $buffer;
				$buffer       = '';
				$buffer_chars = 0;
			}

			$buffer       .= $block;
			$buffer_chars += $block_chars;
		}

		if ( '' !== trim( wp_strip_all_tags( $buffer ) ) ) {
			$chunks[] = $buffer;
		}

		return ! empty( $chunks ) ? $chunks : array( $html );
	}

	/**
	 * 構造化データ(JSON-LD)のみを小さいリクエストで生成する。
	 * 本文全体の書き換えとは切り離すことで、この分だけのトークン消費で済ませる。
	 */
	private function generate_schema_snippet( SEOCP_AI_Provider $ai, $post ) {
		$excerpt = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 1500 );

		$prompt = <<<PROMPT
あなたはSEO構造化データの専門家です。以下の記事の内容をもとに、Articleスキーマ(記事内にFAQらしき
質問と回答が含まれていればFAQPageスキーマも)のJSON-LDを1つ生成してください。

【出力形式】
- <script type="application/ld+json"> ... </script> のみを出力すること
- 前置きや説明文は一切書かないこと
- 記事本文にない事実を作り上げないこと(不明な項目は省略してよい)

【記事タイトル】
{$post->post_title}

【本文抜粋】
{$excerpt}
PROMPT;

		$snippet = $ai->generate_text( $prompt, 2048, 60, 'gemini' );

		if ( is_wp_error( $snippet ) ) {
			return ''; // 失敗しても本文側の処理自体は止めない
		}

		return trim( preg_replace( '/^```(?:html|json)?\s*|\s*```$/i', '', trim( $snippet ) ) );
	}

	/** 直近の generate_revised_content() 呼び出しで実際に使われた断片数(検証・表示用) */
	private $last_chunk_count = 1;

	/**
	 * 元記事本文・検出された課題・改善提案を渡し、改善を反映した本文をAIに生成させる。
	 * 記事全体を1回で送るのではなく、断片ごとに小さいリクエストへ分割して処理する
	 * (v0.6.1のレート制限対策ロジックをv0.7.0のプレビュー/確認フローに移植)。
	 */
	private function generate_revised_content( $post, $checks, $suggestion_text ) {
		$ai = new SEOCP_AI_Provider();

		// 構造化データ(JSON-LD)追加の提案が含まれる場合は、本文の断片処理とは別に、
		// JSON-LDだけを小さいリクエストで生成し、最後に本文末尾へ追加する。
		//
		// トリガー条件は、AIの提案文の言い回し(「構造化データ」「JSON-LD」等)だけに頼らない。
		// AIは同じ内容を「スキーマ」「schema.org」「マークアップ」のように別表現で書くことがあり、
		// その場合キーワード一致だけでは検出漏れが起き、structured_data(配点最大の項目)が
		// 毎回未達のままスコアが100点に届かない、という状態になってしまうため、
		// 「チェック時点でそもそも構造化データが無かったか」を直接見る判定を主にする。
		$checks_missing_schema = empty( $checks['structured_data']['pass'] );
		$suggestion_mentions_schema = (bool) preg_match(
			'/json-?ld|構造化データ|schema\.org|スキーマ|faqpage/iu',
			$suggestion_text
		);
		$wants_schema   = $checks_missing_schema || $suggestion_mentions_schema;
		$schema_snippet = $wants_schema ? $this->generate_schema_snippet( $ai, $post ) : '';

		$chunks = $this->split_html_into_chunks( $post->post_content );
		$this->last_chunk_count = count( $chunks );

		if ( empty( $chunks ) ) {
			return new WP_Error( 'seocp_empty_content', '本文が空のため書き換えできません。' );
		}

		$revised_chunks = array();
		$success_count  = 0;
		$last_error     = null; // 診断用: 最後に発生した実際のエラーを保持する

		// 断片ごとにレート制限のセルフリトライ(sleep)が積み重なり、共有ホスティングで
		// PHPワーカーを長時間占有するのを避けるため、セルフリトライは記事1件につき1回までとする。
		$self_retry_used = false;

		// Webサーバー/プロキシ側のタイムアウト(set_time_limit()では制御できない)で
		// 処理未完了のまま強制終了されるのを避けるため、累積経過時間に上限を設け、
		// 超えた場合は残りの断片を元のまま採用して打ち切る。
		$deadline = time() + 600;

		foreach ( $chunks as $chunk ) {
			$chunk_chars = mb_strlen( trim( wp_strip_all_tags( $chunk ) ) );

			// 見出しのみ/短い区切り等、実質的なテキストがほぼない断片はAIを介さずそのまま使う
			if ( $chunk_chars < 20 ) {
				$revised_chunks[] = $chunk;
				continue;
			}

			if ( time() > $deadline ) {
				// 処理時間が長引きすぎている場合は、残りの断片は元のまま採用して打ち切る
				$revised_chunks[] = $chunk;
				continue;
			}

			$prompt = <<<PROMPT
あなたはSEOおよびAI検索最適化(LLMO/AEO)の専門家兼編集者です。
これは記事本文の一部(断片)です。記事全体は複数の断片に分けて処理しているため、
この断片だけを対象に、「改善提案」のうちこの断片に関係するものだけを反映してください。
関係しない提案は無視して構いません(他の断片で対応します)。

【厳守事項】
- この断片にある事実・情報は一つも省略・削除・要約しないこと。
- 文字数はこの断片と同程度以上にすること。大幅に短くすることは禁止。
- 出力はこの断片の書き換え後のHTMLのみとすること(見出しは<h2>/<h3>、リストは<ul><li>、段落は<p>)。
- 前置きや説明文、コードブロックの```などは一切付けないこと。
- JSON-LD構造化データはここでは追加しないこと(必要であれば別途まとめて追加します)。

【記事タイトル(参考)】
{$post->post_title}

【改善提案(記事全体向け。この断片に関係するものだけ適用)】
{$suggestion_text}

【本文の断片】
{$chunk}
PROMPT;

			// 断片の書き換えは1回のリクエストで最大4096トークンを消費するため、
			// TPM(1分あたりトークン数)上限が低いGroqでは即座にレート制限にかかりやすい。
			// ここではGeminiを優先し、Geminiが未設定/失敗した場合のみGroqへフォールバックする。
			$revised = $ai->generate_text( $prompt, 4096, 90, 'gemini', ! $self_retry_used );
			$self_retry_used = true;

			if ( is_wp_error( $revised ) ) {
				// この断片だけ失敗しても記事全体を失敗させず、元の断片のまま処理を続ける
				$last_error       = $revised; // 診断用に実際のエラーを記録しておく
				$revised_chunks[] = $chunk;
				continue;
			}

			$revised       = trim( preg_replace( '/^```(?:html)?\s*|\s*```$/i', '', trim( $revised ) ) );
			$revised_chars = mb_strlen( trim( wp_strip_all_tags( $revised ) ) );

			if ( '' === $revised || ( $chunk_chars > 0 && $revised_chars < $chunk_chars * 0.6 ) ) {
				// 空応答/大幅な短縮は内容欠落とみなし、元の断片を採用する
				$revised_chunks[] = $chunk;
				continue;
			}

			$revised_chunks[] = $revised;
			$success_count++;
		}

		if ( 0 === $success_count ) {
			// 診断用: 実際に発生していたエラー内容(APIキー未設定/レート制限/APIエラー等)を
			// そのままユーザーへ表示し、原因を特定しやすくする。
			$detail = $last_error ? $last_error->get_error_message() : '(詳細エラーなし。APIへ到達できていない可能性があります)';
			return new WP_Error(
				'seocp_all_chunks_failed',
				sprintf(
					'すべての断片でAIによる書き換えに失敗しました。実際のエラー: %s',
					$detail
				)
			);
		}

		$text = implode( "\n", $revised_chunks );

		if ( $schema_snippet ) {
			$text .= "\n" . $schema_snippet;
		}

		return $text;
	}
}
