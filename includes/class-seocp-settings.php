<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定(APIキー等)の登録・保存を担当
 * v0.7.0: APIキー/クライアントシークレット類をDBに暗号化して保存するように変更。
 */
class SEOCP_Settings {

	const OPTION_KEY = 'seocp_settings';

	// 平文で保存してよい項目以外(APIキー・シークレット類)はこの一覧に含める
	const SENSITIVE_KEYS = array( 'gemini_api_key', 'groq_api_key', 'gsc_client_secret' );

	const ENC_PREFIX = 'seocpenc:';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_migrate_deprecated_model' ), 5 );
	}

	/**
	 * 過去に既定値として保存された廃止済み/廃止予定のモデル名を
	 * 自動追従エイリアス(gemini-flash-latest)に移行する。
	 * ユーザーが明示的に別のモデル名を指定している場合は上書きしない。
	 */
	public function maybe_migrate_deprecated_model() {
		$known_deprecated = array( 'gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite' );

		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! empty( $settings['gemini_model'] ) && in_array( $settings['gemini_model'], $known_deprecated, true ) ) {
			$settings['gemini_model'] = 'gemini-flash-latest';
			update_option( self::OPTION_KEY, $settings );
		}
	}

	public function register_settings() {
		register_setting( 'seocp_settings_group', self::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	public function sanitize( $input ) {
		$existing = get_option( self::OPTION_KEY, array() );

		$ai_provider = isset( $input['ai_provider'] ) && 'groq' === $input['ai_provider'] ? 'groq' : 'gemini';

		return array(
			'ai_provider'       => $ai_provider,
			'gemini_api_key'    => $this->sanitize_sensitive( 'gemini_api_key', $input, $existing ),
			'gemini_model'      => isset( $input['gemini_model'] ) && '' !== trim( $input['gemini_model'] ) ? sanitize_text_field( $input['gemini_model'] ) : ( $existing['gemini_model'] ?? 'gemini-flash-latest' ),
			'groq_api_key'      => $this->sanitize_sensitive( 'groq_api_key', $input, $existing ),
			'groq_model'        => isset( $input['groq_model'] ) && '' !== trim( $input['groq_model'] ) ? sanitize_text_field( $input['groq_model'] ) : ( $existing['groq_model'] ?? 'openai/gpt-oss-20b' ),
			'gsc_client_id'     => isset( $input['gsc_client_id'] ) ? sanitize_text_field( $input['gsc_client_id'] ) : ( $existing['gsc_client_id'] ?? '' ),
			'gsc_client_secret' => $this->sanitize_sensitive( 'gsc_client_secret', $input, $existing ),
			'gsc_site_url'      => isset( $input['gsc_site_url'] ) ? esc_url_raw( $input['gsc_site_url'] ) : ( $existing['gsc_site_url'] ?? '' ),
		);
	}

	/**
	 * 機密項目(APIキー等)をサニタイズしつつ暗号化した状態でDBに保存する値を返す。
	 * 入力欄が空のままsubmitされた場合(=変更なし)は既存値を保持する。
	 */
	private function sanitize_sensitive( $key, $input, $existing ) {
		if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
			$raw = sanitize_text_field( $input[ $key ] );
			return self::encrypt( $raw );
		}
		return isset( $existing[ $key ] ) ? $existing[ $key ] : '';
	}

	/**
	 * 設定値を取得する。SENSITIVE_KEYSに含まれる項目は自動で復号して返す。
	 */
	public static function get( $key, $default = '' ) {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $settings[ $key ] ) || '' === $settings[ $key ] ) {
			return $default;
		}
		if ( in_array( $key, self::SENSITIVE_KEYS, true ) ) {
			return self::decrypt( $settings[ $key ] );
		}
		return $settings[ $key ];
	}

	/**
	 * 設定画面に「保存済みかどうか」だけを表示したい場合に使う(復号せず判定)
	 */
	public static function is_set( $key ) {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! empty( $settings[ $key ] );
	}

	/**
	 * DB(wp_options)にAPIキーやシークレットが平文で残らないよう、WordPressのAUTH_KEY等から
	 * 導出した鍵でAES-256-CBCにより暗号化する。opensslが使えない環境ではやむを得ず平文で保存する
	 * (base64化のみ)。あくまで「DBを直接覗かれた際の可読性を下げる」ための対策であり、
	 * サーバー自体やwp-config.phpが漏洩した場合の保護までは保証しない。
	 */
	public static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return self::ENC_PREFIX . 'b64:' . base64_encode( $plain );
		}
		$key = self::derive_key();
		$iv  = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return self::ENC_PREFIX . 'b64:' . base64_encode( $plain );
		}
		return self::ENC_PREFIX . 'aes:' . base64_encode( $iv . $cipher );
	}

	public static function decrypt( $stored ) {
		if ( '' === $stored || 0 !== strpos( $stored, self::ENC_PREFIX ) ) {
			// 暗号化プレフィックスがない = 旧バージョンからの平文値。そのまま返す(次回保存時に暗号化される)。
			return $stored;
		}
		$body = substr( $stored, strlen( self::ENC_PREFIX ) );

		if ( 0 === strpos( $body, 'b64:' ) ) {
			return base64_decode( substr( $body, 4 ) );
		}

		if ( 0 === strpos( $body, 'aes:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $body, 4 ) );
			if ( false === $raw || strlen( $raw ) < 17 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 16 );
			$cipher = substr( $raw, 16 );
			$key    = self::derive_key();
			$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}

		return '';
	}

	private static function derive_key() {
		$secret = ( defined( 'AUTH_KEY' ) && AUTH_KEY ) ? AUTH_KEY : '';
		$secret .= ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) ? SECURE_AUTH_KEY : '';
		if ( '' === $secret ) {
			$secret = get_option( 'seocp_fallback_secret' );
			if ( ! $secret ) {
				$secret = wp_generate_password( 64, true, true );
				add_option( 'seocp_fallback_secret', $secret );
			}
		}
		return hash( 'sha256', $secret, true );
	}
}
