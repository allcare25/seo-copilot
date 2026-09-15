<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * v0.9.7: AI提案(タイトル/FAQ/内部リンク)ページで、下書きを別途作らずに
 * 「その場で」公開中の記事へ反映するためのヘルパー。
 * また、反映後の記事をその場でプレビュー表示・編集・保存する機能もここでまとめて扱う。
 *
 * SEOCP_Draft_Generator(「改善」下書き生成機能)とはFAQ/内部リンクのHTML組み立てロジックを
 * 共有しており、build_faq_block() / build_links_block() を呼び出して再利用する。
 */
class SEOCP_Content_Applier {

	/**
	 * 現在の投稿のタイトル・本文・プレビューHTMLを取得する。
	 */
	public function get_preview( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		return $this->build_payload( $post );
	}

	/**
	 * プレビュー/編集パネルからの保存。記事のタイトル・本文を直接更新する。
	 */
	public function save_content( $post_id, $content, $title = null ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$update = array(
			'ID'           => $post_id,
			'post_content' => $content,
		);
		if ( null !== $title && '' !== trim( $title ) ) {
			$update['post_title'] = $title;
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get_preview( $post_id );
	}

	/**
	 * 選択されたFAQ案を、記事本文の末尾に追記して直接反映する。
	 *
	 * @param int   $post_id
	 * @param array $faqs 反映するFAQ({question, answer}の配列)
	 */
	public function apply_faq( $post_id, $faqs ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}
		if ( empty( $faqs ) ) {
			return new WP_Error( 'seocp_no_items', '反映するFAQがありません。' );
		}

		$generator = new SEOCP_Draft_Generator();
		$block     = $generator->build_faq_block( $faqs );

		$new_content = rtrim( $post->post_content ) . "\n\n" . $block;

		$result = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get_preview( $post_id );
	}

	/**
	 * 選択された内部リンク候補を、記事本文の末尾に追記して直接反映する。
	 *
	 * @param int   $post_id
	 * @param array $links 反映するリンク({title, url, anchor, reason}の配列)
	 */
	public function apply_links( $post_id, $links ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}
		if ( empty( $links ) ) {
			return new WP_Error( 'seocp_no_items', '反映する内部リンクがありません。' );
		}

		$generator = new SEOCP_Draft_Generator();
		$block     = $generator->build_links_block( $links );

		$new_content = rtrim( $post->post_content ) . "\n\n" . $block;

		$result = wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => $new_content,
		), true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->get_preview( $post_id );
	}

	private function build_payload( $post ) {
		return array(
			'post_id'      => $post->ID,
			'edit_url'     => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'title'        => $post->post_title,
			'content'      => $post->post_content,
			'preview_html' => do_blocks( $post->post_content ),
		);
	}
}
