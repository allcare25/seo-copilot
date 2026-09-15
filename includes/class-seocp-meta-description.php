<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * メタディスクリプションの取得・保存を担当。
 *
 * サイトにYoast SEO / Rank Math / All in One SEO / SEOPress のいずれかが
 * 導入されている場合は、そのプラグインが実際にフロントへ出力するメタキーに
 * 書き込むことで、既存のSEOプラグインと競合せずそのまま反映されるようにする。
 * どれも導入されていない場合のみ、本プラグイン自身のキーに保存し、
 * wp_head で <meta name="description"> を出力する(重複出力を避けるため)。
 */
class SEOCP_Meta_Description {

	const OWN_META_KEY = '_seocp_meta_description';

	/** 推奨文字数の目安(全角) */
	const RECOMMENDED_MAX = 120;

	public function __construct() {
		add_action( 'wp_head', array( $this, 'maybe_output_meta_tag' ), 1 );
	}

	/**
	 * 対応している他社SEOプラグインのうち、有効なものを1つ返す(優先順位あり)。
	 * 見つからなければ null。
	 */
	public function detect_active_seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		return null;
	}

	/**
	 * 連携先プラグインごとのメタキー名
	 */
	private function get_meta_key_for( $plugin ) {
		switch ( $plugin ) {
			case 'yoast':
				return '_yoast_wpseo_metadesc';
			case 'rankmath':
				return 'rank_math_description';
			case 'aioseo':
				return '_aioseo_description';
			case 'seopress':
				return '_seopress_titles_desc';
			default:
				return self::OWN_META_KEY;
		}
	}

	/**
	 * 現在設定されているメタディスクリプションを取得する
	 *
	 * @return array{text:string, source:string, source_label:string}
	 */
	public function get_current( $post_id ) {
		$plugin = $this->detect_active_seo_plugin();
		$key    = $this->get_meta_key_for( $plugin );
		$text   = get_post_meta( $post_id, $key, true );

		// 他社プラグインのキーが空でも、本プラグインの保存値が残っていれば参考として拾う
		if ( '' === $text && $plugin ) {
			$fallback = get_post_meta( $post_id, self::OWN_META_KEY, true );
			if ( '' !== $fallback ) {
				return array(
					'text'         => $fallback,
					'source'       => 'seocp',
					'source_label' => $this->get_source_label( 'seocp' ) . '(未反映)',
				);
			}
		}

		return array(
			'text'         => (string) $text,
			'source'       => $plugin ? $plugin : 'seocp',
			'source_label' => $this->get_source_label( $plugin ? $plugin : 'seocp' ),
		);
	}

	private function get_source_label( $plugin ) {
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'aioseo'   => 'All in One SEO',
			'seopress' => 'SEOPress',
			'seocp'    => '本プラグイン',
		);
		return $labels[ $plugin ] ?? '本プラグイン';
	}

	/**
	 * メタディスクリプションを保存する。
	 * 他社SEOプラグインが有効な場合はそのメタキーに書き込み、
	 * 常に本プラグイン独自キーにも保存しておく(他社プラグインが無効化された場合の保険・履歴用)。
	 */
	public function apply( $post_id, $text ) {
		$text   = trim( wp_strip_all_tags( $text ) );
		$plugin = $this->detect_active_seo_plugin();
		$key    = $this->get_meta_key_for( $plugin );

		update_post_meta( $post_id, self::OWN_META_KEY, $text );
		if ( $key !== self::OWN_META_KEY ) {
			update_post_meta( $post_id, $key, $text );
		}

		return array(
			'source'       => $plugin ? $plugin : 'seocp',
			'source_label' => $this->get_source_label( $plugin ? $plugin : 'seocp' ),
		);
	}

	/**
	 * 他社SEOプラグインが導入されていない場合のみ、本プラグインの保存値を
	 * <meta name="description"> として出力する(二重出力防止)。
	 */
	public function maybe_output_meta_tag() {
		if ( $this->detect_active_seo_plugin() ) {
			return; // 他社プラグインが自前で出力するため何もしない
		}
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}
		$text = get_post_meta( $post_id, self::OWN_META_KEY, true );
		if ( '' === $text ) {
			return;
		}
		echo '<meta name="description" content="' . esc_attr( $text ) . '">' . "\n";
	}

	/**
	 * 全角換算の文字数(目安)。半角英数は0.5文字として計算する簡易版。
	 */
	public static function count_length( $text ) {
		$len = 0;
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $chars ) {
			return mb_strlen( $text );
		}
		foreach ( $chars as $c ) {
			$len += ( strlen( $c ) > 1 ) ? 1 : 0.5;
		}
		return (int) ceil( $len );
	}
}
