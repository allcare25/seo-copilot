<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 「改善」ボタンで、AI提案(タイトル/FAQ/内部リンク)を反映した下書きを自動生成する。
 *
 * 安全設計:
 * - 公開中の元記事は一切変更しない
 * - 新しい下書き記事を複製生成し、編集者が内容を確認した上で公開/差し替えできるようにする
 */
class SEOCP_Draft_Generator {

	private $engine;

	public function __construct() {
		$this->engine = new SEOCP_Suggestion_Engine();
	}

	/**
	 * @param int   $post_id
	 * @param array $args { use_title: bool, add_faq: bool, add_links: bool }
	 * @return array|WP_Error
	 */
	public function generate_draft( $post_id, $args = array() ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$args = wp_parse_args( $args, array(
			'use_title' => true,
			'add_faq'   => true,
			'add_links' => true,
		) );

		$new_title = $post->post_title;
		$faqs      = array();
		$links     = array();

		// --- タイトル ---
		if ( $args['use_title'] ) {
			$titles = $this->get_or_generate_titles( $post_id );
			if ( is_wp_error( $titles ) ) {
				return $titles;
			}
			if ( ! empty( $titles ) ) {
				$new_title = $titles[0];
			}
		}

		$extra_content = '';

		// --- FAQ ---
		if ( $args['add_faq'] ) {
			$faqs = $this->get_or_generate_faqs( $post_id );
			if ( is_wp_error( $faqs ) ) {
				return $faqs;
			}
			if ( ! empty( $faqs ) ) {
				$extra_content .= $this->build_faq_block( $faqs );
			}
		}

		// --- 内部リンク ---
		if ( $args['add_links'] ) {
			$links = $this->get_or_generate_links( $post_id );
			if ( is_wp_error( $links ) ) {
				return $links;
			}
			if ( ! empty( $links ) ) {
				$extra_content .= $this->build_links_block( $links );
			}
		}

		$new_content = $post->post_content;
		if ( '' !== trim( $extra_content ) ) {
			$new_content .= "\n\n" . $extra_content;
		}

		$draft_id = wp_insert_post( array(
			'post_title'   => $new_title,
			'post_content' => $new_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => 'draft',
			'post_type'    => $post->post_type,
			'post_author'  => $post->post_author,
		), true );

		if ( is_wp_error( $draft_id ) ) {
			return $draft_id;
		}

		update_post_meta( $draft_id, '_seocp_source_post_id', $post_id );
		update_post_meta( $post_id, '_seocp_latest_draft_id', $draft_id );

		// v0.7.0: 差分プレビュー用に、タイトル・本文それぞれの差分HTMLを生成しておく
		$title_diff_html   = SEOCP_Diff::html_diff( $post->post_title, $new_title, false );
		$content_diff_html = SEOCP_Diff::html_diff( $post->post_content, $new_content, true );

		$payload = array(
			'draft_id'          => $draft_id,
			'edit_url'          => admin_url( 'post.php?post=' . $draft_id . '&action=edit' ),
			'title_used'        => $new_title,
			'faq_count'         => count( $faqs ),
			'link_count'        => count( $links ),
			'title_diff_html'   => $title_diff_html,
			'content_diff_html' => $content_diff_html,
		);

		$this->save_record( $post_id, $payload );

		return $payload;
	}

	private function get_or_generate_titles( $post_id ) {
		$saved = $this->engine->get_latest_suggestion( $post_id, SEOCP_Suggestion_Engine::TYPE_TITLE );
		if ( $saved && ! empty( $saved['content']['titles'] ) ) {
			return $saved['content']['titles'];
		}
		$result = $this->engine->generate_title_suggestions( $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result['titles'];
	}

	private function get_or_generate_faqs( $post_id ) {
		$saved = $this->engine->get_latest_suggestion( $post_id, SEOCP_Suggestion_Engine::TYPE_FAQ );
		if ( $saved && ! empty( $saved['content']['faqs'] ) ) {
			return $saved['content']['faqs'];
		}
		$result = $this->engine->generate_faq_suggestions( $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result['faqs'];
	}

	private function get_or_generate_links( $post_id ) {
		$saved = $this->engine->get_latest_suggestion( $post_id, SEOCP_Suggestion_Engine::TYPE_INTERNAL );
		if ( $saved && ! empty( $saved['content']['links'] ) ) {
			return $saved['content']['links'];
		}
		$result = $this->engine->suggest_internal_links( $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result['links'];
	}

	/**
	 * FAQセクションをGutenbergブロック形式(+FAQPage構造化データ)で生成
	 * v0.9.7: AI提案ページの「その場で反映」機能(SEOCP_Content_Applier)からも
	 * 再利用するためpublicに変更。
	 */
	public function build_faq_block( $faqs ) {
		$html = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">よくある質問</h2>\n<!-- /wp:heading -->\n\n";

		$schema_items = array();
		foreach ( $faqs as $f ) {
			if ( empty( $f['question'] ) || empty( $f['answer'] ) ) {
				continue;
			}
			$q = esc_html( $f['question'] );
			$a = esc_html( $f['answer'] );

			$html .= "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Q. {$q}</h3>\n<!-- /wp:heading -->\n\n";
			$html .= "<!-- wp:paragraph -->\n<p>A. {$a}</p>\n<!-- /wp:paragraph -->\n\n";

			$schema_items[] = array(
				'@type'          => 'Question',
				'name'           => $f['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $f['answer'],
				),
			);
		}

		if ( ! empty( $schema_items ) ) {
			$schema = array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => $schema_items,
			);
			$json  = wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$html .= "<!-- wp:html -->\n<script type=\"application/ld+json\">{$json}</script>\n<!-- /wp:html -->\n\n";
		}

		return $html;
	}

	/**
	 * 内部リンクセクションをGutenbergブロック形式で生成
	 * v0.9.7: AI提案ページの「その場で反映」機能(SEOCP_Content_Applier)からも
	 * 再利用するためpublicに変更。
	 */
	public function build_links_block( $links ) {
		$html  = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">関連記事</h2>\n<!-- /wp:heading -->\n\n";
		$html .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n";
		foreach ( $links as $l ) {
			if ( empty( $l['url'] ) ) {
				continue;
			}
			$url    = esc_url( $l['url'] );
			$anchor = esc_html( ! empty( $l['anchor'] ) ? $l['anchor'] : $l['title'] );
			$html  .= "<li><a href=\"{$url}\">{$anchor}</a></li>\n";
		}
		$html .= "</ul>\n<!-- /wp:list -->\n\n";
		return $html;
	}

	private function save_record( $post_id, $payload ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_SUGGESTIONS;
		$wpdb->insert(
			$table,
			array(
				'post_id'    => $post_id,
				'type'       => SEOCP_Suggestion_Engine::TYPE_DRAFT,
				'content'    => wp_json_encode( $payload ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}
}
