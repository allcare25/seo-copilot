<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI提案エンジン
 * - タイトル改善案
 * - FAQ追加案
 * - 内部リンク候補
 */
class SEOCP_Suggestion_Engine {

	const TYPE_TITLE    = 'title';
	const TYPE_FAQ      = 'faq';
	const TYPE_INTERNAL = 'internal_link';
	const TYPE_DRAFT     = 'draft';
	const TYPE_META_DESCRIPTION = 'meta_description';

	/* ================= タイトル改善案 ================= */

	public function generate_title_suggestions( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$excerpt = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 1200 );
		$gsc_context = $this->get_gsc_context_text( $post_id );

		$prompt = <<<PROMPT
あなたは日本語のSEOコピーライターです。以下の記事に対して、クリック率(CTR)向上と検索意図への適合を両立する
タイトル改善案を5つ提案してください。

【制約】
- 全角で32文字前後(長くても36文字以内)を目安にする
- 記事内容と乖離した誇張・釣りタイトルにしない
- 5案とも異なる切り口にする(例: 数字を使う/悩みに共感/結論を先出し/比較訴求/初心者向けを明示 など)
- 出力は以下の形式「のみ」で、余計な前置きや説明文は書かない

出力形式:
1. (タイトル案)
2. (タイトル案)
3. (タイトル案)
4. (タイトル案)
5. (タイトル案)

【現在のタイトル】
{$post->post_title}

{$gsc_context}
【本文抜粋】
{$excerpt}
PROMPT;

		$ai   = new SEOCP_AI_Provider();
		$text = $ai->generate_text( $prompt );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$titles = $this->parse_numbered_list( $text );

		$payload = array(
			'raw'    => $text,
			'titles' => $titles,
		);

		$this->save_suggestion( $post_id, self::TYPE_TITLE, $payload );

		return $payload;
	}

	/* ================= メタディスクリプション改善案 ================= */

	public function generate_meta_description_suggestions( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$meta_manager = new SEOCP_Meta_Description();
		$current      = $meta_manager->get_current( $post_id );

		$excerpt = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 1500 );
		$gsc_context = $this->get_gsc_context_text( $post_id );

		$prompt = <<<PROMPT
あなたは日本語のSEOコピーライターです。以下の記事に対して、検索結果(SERP)でのクリック率(CTR)向上を狙った
メタディスクリプション(検索結果に表示される説明文)の改善案を5つ提案してください。

【制約】
- 全角で100〜120文字程度(スマートフォンでの表示切れを考慮し、120文字は超えない)
- 記事の要点・読むメリットが一目で伝わる文章にする
- 可能であれば記事内で使われている重要なキーワードを自然に含める
- 誇張・釣り文句にはせず、記事内容と一致させる
- 5案とも異なる切り口にする(例: 結論を先出し/悩みへの共感/数字や実績を提示/読者対象を明示/行動を促す一言を添える など)
- 出力は以下の形式「のみ」で、余計な前置きや説明文は書かない

出力形式:
1. (説明文案)
2. (説明文案)
3. (説明文案)
4. (説明文案)
5. (説明文案)

【現在のタイトル】
{$post->post_title}

【現在のメタディスクリプション】
{$current['text']}

{$gsc_context}
【本文抜粋】
{$excerpt}
PROMPT;

		$ai   = new SEOCP_AI_Provider();
		$text = $ai->generate_text( $prompt );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$descriptions = $this->parse_numbered_list( $text );

		$payload = array(
			'raw'          => $text,
			'descriptions' => $descriptions,
		);

		$this->save_suggestion( $post_id, self::TYPE_META_DESCRIPTION, $payload );

		return $payload;
	}

	/* ================= FAQ追加案 ================= */

	public function generate_faq_suggestions( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$excerpt = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 2000 );

		$prompt = <<<PROMPT
あなたは日本語のSEO/AI検索対策の専門家です。以下の記事内容をもとに、読者が抱きそうな質問とその回答を
FAQとして4〜5組作成してください。AI検索エンジン(ChatGPT検索・Perplexity・Google AI Overviews)に
引用されやすいよう、回答は1〜3文で簡潔・具体的にしてください。

出力は以下の形式「のみ」で、他の説明文は書かないでください。

Q1: (質問)
A1: (回答)
Q2: (質問)
A2: (回答)
Q3: (質問)
A3: (回答)
Q4: (質問)
A4: (回答)

【記事タイトル】
{$post->post_title}

【本文抜粋】
{$excerpt}
PROMPT;

		$ai   = new SEOCP_AI_Provider();
		$text = $ai->generate_text( $prompt );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$faqs = $this->parse_qa_pairs( $text );

		$payload = array(
			'raw'  => $text,
			'faqs' => $faqs,
		);

		$this->save_suggestion( $post_id, self::TYPE_FAQ, $payload );

		return $payload;
	}

	/* ================= 内部リンク候補 ================= */

	public function suggest_internal_links( $post_id, $max_candidates = 40 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$candidates = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => $max_candidates,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'exclude'        => array( $post_id ),
		) );

		if ( empty( $candidates ) ) {
			return new WP_Error( 'seocp_no_candidates', 'リンク候補となる他の記事が見つかりません。' );
		}

		$candidate_map  = array();
		$candidate_text = '';
		$i = 0;
		foreach ( $candidates as $c ) {
			$i++;
			$candidate_map[ $i ] = array(
				'title' => get_the_title( $c ),
				'url'   => get_permalink( $c ),
			);
			$candidate_text .= $i . '. ' . get_the_title( $c ) . "\n";
		}

		$excerpt = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 1500 );

		$prompt = <<<PROMPT
あなたは日本語のSEO専門家です。以下の【対象記事】に対して、【候補記事一覧】の中から
内部リンクを設置すると読者の理解や回遊性が高まる記事を最大5つ選んでください。
関連性が薄い場合は無理に5つ選ばず、少なくても構いません。

出力は以下の形式「のみ」、他の説明文は書かないでください。番号は候補記事一覧の番号と一致させてください。

番号 | アンカーテキスト案 | 理由(20文字程度)

【対象記事タイトル】
{$post->post_title}

【対象記事の本文抜粋】
{$excerpt}

【候補記事一覧】
{$candidate_text}
PROMPT;

		$ai   = new SEOCP_AI_Provider();
		$text = $ai->generate_text( $prompt );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$links = $this->parse_internal_links( $text, $candidate_map );

		$payload = array(
			'raw'   => $text,
			'links' => $links,
		);

		$this->save_suggestion( $post_id, self::TYPE_INTERNAL, $payload );

		return $payload;
	}

	/* ================= 共通ヘルパー ================= */

	/**
	 * GSCデータ(あれば)をプロンプト用テキストに変換
	 */
	private function get_gsc_context_text( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_PAGES;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d ORDER BY data_date DESC LIMIT 1",
				$post_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return '';
		}

		return sprintf(
			"【Search Consoleデータ(直近取得分)】\nクリック数: %d / 表示回数: %d / CTR: %s%% / 平均順位: %s位\n\n",
			(int) $row['clicks'],
			(int) $row['impressions'],
			round( (float) $row['ctr'] * 100, 2 ),
			round( (float) $row['position'], 1 )
		);
	}

	private function parse_numbered_list( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$items = array();
		foreach ( $lines as $line ) {
			// AIの出力に含まれがちなMarkdownの強調記号(**太字**)を除去してから判定する。
			$line = trim( str_replace( array( '**', '__' ), '', $line ) );
			// 半角(. ))・全角(．）)・読点(、)のいずれの番号表記にも対応する。
			if ( preg_match( '/^\s*\d+\s*[\.\)、．）]\s*(.+)$/u', $line, $m ) ) {
				$items[] = trim( $m[1] );
			}
		}
		return $items;
	}

	private function parse_qa_pairs( $text ) {
		preg_match_all( '/Q\d*[:：]\s*(.+?)\s*\n\s*A\d*[:：]\s*(.+?)(?=\n\s*Q\d*[:：]|\z)/us', $text, $matches, PREG_SET_ORDER );
		$faqs = array();
		foreach ( $matches as $m ) {
			$faqs[] = array(
				'question' => trim( $m[1] ),
				'answer'   => trim( preg_replace( '/\s+$/u', '', $m[2] ) ),
			);
		}
		return $faqs;
	}

	private function parse_internal_links( $text, $candidate_map ) {
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$links = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '|' ) ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$num = preg_replace( '/[^\d]/', '', $parts[0] );
			if ( ! $num || ! isset( $candidate_map[ (int) $num ] ) ) {
				continue;
			}
			$cand = $candidate_map[ (int) $num ];
			$links[] = array(
				'title'  => $cand['title'],
				'url'    => $cand['url'],
				'anchor' => isset( $parts[1] ) ? $parts[1] : $cand['title'],
				'reason' => isset( $parts[2] ) ? $parts[2] : '',
			);
		}
		return $links;
	}

	/* ================= DB保存/取得 ================= */

	private function save_suggestion( $post_id, $type, $payload ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_SUGGESTIONS;

		$wpdb->insert(
			$table,
			array(
				'post_id'    => $post_id,
				'type'       => $type,
				'content'    => wp_json_encode( $payload ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	public function get_latest_suggestion( $post_id, $type ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_SUGGESTIONS;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d AND type = %s ORDER BY created_at DESC LIMIT 1",
				$post_id,
				$type
			),
			ARRAY_A
		);

		if ( $row && ! empty( $row['content'] ) ) {
			$row['content'] = json_decode( $row['content'], true );
		}

		return $row;
	}
}
