<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI提案生成のプロバイダークラス。
 * Google Gemini APIとGroq API(OpenAI互換, 無料枠あり)の2系統に対応し、
 * 「設定画面で選んだメインプロバイダー」→レート制限時は「もう一方の設定済みプロバイダー」
 * へ自動フォールバックする。両方未設定/両方失敗の場合のみエラーを返す。
 */
class SEOCP_AI_Provider {

	private $primary_provider;
	private $gemini_api_key;
	private $gemini_model;
	private $groq_api_key;
	private $groq_model;

	public function __construct() {
		$settings = get_option( 'seocp_settings', array() );

		$this->primary_provider = ( isset( $settings['ai_provider'] ) && 'groq' === $settings['ai_provider'] ) ? 'groq' : 'gemini';

		// APIキーはDBに暗号化保存されているため、SEOCP_Settings::get()経由で復号して取得する
		$this->gemini_api_key = SEOCP_Settings::get( 'gemini_api_key' );
		$this->gemini_model   = isset( $settings['gemini_model'] ) && $settings['gemini_model']
			? $settings['gemini_model']
			: 'gemini-flash-latest';

		$this->groq_api_key = SEOCP_Settings::get( 'groq_api_key' );
		$this->groq_model   = isset( $settings['groq_model'] ) && $settings['groq_model']
			? $settings['groq_model']
			: 'openai/gpt-oss-20b';
	}

	public function is_configured() {
		return $this->is_provider_configured( 'gemini' ) || $this->is_provider_configured( 'groq' );
	}

	private function is_provider_configured( $provider ) {
		return 'groq' === $provider ? ! empty( $this->groq_api_key ) : ! empty( $this->gemini_api_key );
	}

	private function provider_label( $provider ) {
		return 'groq' === $provider ? 'Groq' : 'Gemini';
	}

	/**
	 * プロンプトを送りテキスト応答を取得する。
	 * メインプロバイダーがレート制限等で失敗した場合、予備プロバイダーが設定されていれば
	 * 自動的にそちらへ切り替えて再試行する。
	 *
	 * @param string      $prompt
	 * @param int         $max_output_tokens 記事全文の書き直しなど長い出力が必要な場合は大きめに指定する
	 * @param int         $timeout
	 * @param string|null $prefer_provider 'gemini'|'groq'|null
	 *   指定すると設定画面の「メインプロバイダー」に関わらず、この呼び出しに限りそちらを優先する。
	 *   記事全文の書き直しのような大量トークンを消費する処理は、Groq無料枠のTPM(1分あたりトークン数)
	 *   上限が非常に低く即座にレート制限にかかりやすいため、TPM上限が大きいGeminiを優先させるとよい。
	 * @param bool        $allow_self_retry_sleep
	 *   falseにすると、レート制限時のセルフリトライ(sleepしての同一プロバイダー再試行)を行わない。
	 *   1リクエスト内で複数チャンクを連続処理するような呼び出し元(記事の断片分割書き換え等)が、
	 *   チャンクごとにsleepを重ねてPHPワーカーを長時間占有するのを避けるためのフラグ。
	 * @return string|WP_Error
	 */
	public function generate_text( $prompt, $max_output_tokens = 4096, $timeout = 60, $prefer_provider = null, $allow_self_retry_sleep = true ) {
		$primary = $this->primary_provider;

		if ( 'gemini' === $prefer_provider || 'groq' === $prefer_provider ) {
			$primary = $prefer_provider;
		}

		$secondary = 'groq' === $primary ? 'gemini' : 'groq';

		$primary_configured   = $this->is_provider_configured( $primary );
		$secondary_configured = $this->is_provider_configured( $secondary );

		if ( ! $primary_configured && ! $secondary_configured ) {
			return new WP_Error(
				'seocp_no_api_key',
				'AIプロバイダーのAPIキーが設定されていません。設定画面からGeminiまたはGroqのAPIキーを登録してください。'
			);
		}

		// メインが未設定でも予備だけ設定されていれば、それを使って生成する
		if ( ! $primary_configured ) {
			return $this->call_provider( $secondary, $prompt, $max_output_tokens, $timeout, ( ! $primary_configured ) && $allow_self_retry_sleep );
		}

		// 予備プロバイダーが設定されている場合、メイン側は待機再試行せず即座に失敗を返させ、
		// フォールバックを高速化する(無駄な待ち時間をなくす)。予備が無い場合のみ、
		// 従来通りメイン側で待機再試行を行う。呼び出し元がセルフリトライを禁止している場合も同様。
		$allow_self_retry = ( ! $secondary_configured ) && $allow_self_retry_sleep;
		$result            = $this->call_provider( $primary, $prompt, $max_output_tokens, $timeout, $allow_self_retry );

		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $secondary_configured || ! $this->is_retryable_error( $result ) ) {
			return $result;
		}

		$fallback_result = $this->call_provider( $secondary, $prompt, $max_output_tokens, $timeout, $allow_self_retry_sleep );

		if ( ! is_wp_error( $fallback_result ) ) {
			// v0.7.5: 以前はここで「※Geminiの上限/エラーのため、予備プロバイダー(Groq)で
			// 生成しました。」という注記を本文に自動で追記していたが、この注記自体が
			// 記事本文(断片の書き換え結果)にそのまま混入してしまうため廃止した。
			// どちらのプロバイダーで生成されたかは必要であれば呼び出し元でログ等に記録すること。
			return trim( $fallback_result );
		}

		return new WP_Error(
			'seocp_all_providers_failed',
			sprintf(
				'%s: %s ／ %s(予備): %s',
				$this->provider_label( $primary ),
				$result->get_error_message(),
				$this->provider_label( $secondary ),
				$fallback_result->get_error_message()
			)
		);
	}

	private function is_retryable_error( $error ) {
		return in_array( $error->get_error_code(), array( 'seocp_rate_limited', 'seocp_timeout', 'seocp_empty_response', 'seocp_truncated_response' ), true );
	}

	private function call_provider( $provider, $prompt, $max_output_tokens, $timeout, $allow_self_retry ) {
		return 'groq' === $provider
			? $this->generate_text_groq( $prompt, $max_output_tokens, $timeout, $allow_self_retry )
			: $this->generate_text_gemini( $prompt, $max_output_tokens, $timeout, $allow_self_retry );
	}

	/* ==================== Gemini ==================== */

	private function generate_text_gemini( $prompt, $max_output_tokens, $timeout, $allow_self_retry, $is_retry = false ) {
		if ( empty( $this->gemini_api_key ) ) {
			return new WP_Error( 'seocp_no_api_key', 'Gemini APIキーが設定されていません。設定画面から登録してください。' );
		}

		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
			rawurlencode( $this->gemini_model ),
			rawurlencode( $this->gemini_api_key )
		);

		$body = array(
			'contents' => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'     => 0.4,
				'maxOutputTokens' => $max_output_tokens,
				// gemini-flash-latest等の思考モデルは、内部の「思考」トークンもmaxOutputTokensの枠から
				// 消費するため、思考にほぼ使い切られて本文が空/途中で切れることがある。
				// この用途(SEO改善提案・本文生成)には思考は不要なため無効化し、枠を出力に使う。
				'thinkingConfig'  => array(
					'thinkingBudget' => 0,
				),
			),
		);

		$response = wp_remote_post( $endpoint, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => $timeout,
		) );

		if ( is_wp_error( $response ) ) {
			if ( false !== strpos( $response->get_error_message(), 'timed out' ) ) {
				return new WP_Error(
					'seocp_timeout',
					sprintf( 'Gemini APIへのリクエストがタイムアウトしました(%d秒)。サーバーが混み合っている可能性があります。時間をおいて再度お試しください。', $timeout )
				);
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Gemini APIエラー(HTTP ' . $code . ')';

			// レート制限(無料枠の上限)の場合は、Googleが返す推奨待機秒数を見て自動で1回だけ再試行する
			// (ただし予備プロバイダーが設定されている場合は呼び出し元がフォールバックするため、待機しない)
			$is_rate_limited = ( 429 === $code )
				|| ( isset( $data['error']['status'] ) && 'RESOURCE_EXHAUSTED' === $data['error']['status'] );

			if ( $is_rate_limited ) {
				$retry_delay = $this->extract_retry_delay_seconds( $data );

				if ( $allow_self_retry && ! $is_retry && null !== $retry_delay && $retry_delay <= 65 ) {
					sleep( $retry_delay + 1 );
					return $this->generate_text_gemini( $prompt, $max_output_tokens, $timeout, $allow_self_retry, true );
				}

				$wait_text = null !== $retry_delay ? $retry_delay . '秒' : '1分';
				return new WP_Error(
					'seocp_rate_limited',
					sprintf(
						'Gemini APIの無料枠のリクエスト上限(レート制限)に達しました。%sほど待ってから、同じボタンでもう一度お試しください(提案データは保存済みのため、生成をやり直す必要はありません)。頻発する場合は設定画面でモデルを変更するか、Google AI Studioで有料プランへの切り替えをご検討ください。',
						$wait_text
					)
				);
			}

			if ( $code === 404 || false !== stripos( $message, 'is not found' ) || false !== stripos( $message, 'no longer available' ) ) {
				$message .= sprintf(
					' ／ 使用中のモデル「%s」が廃止・利用不可の可能性があります。設定画面でモデル欄を空にして保存すると、Google追従型の既定値(gemini-flash-latest)に自動で戻ります。',
					$this->gemini_model
				);
			}
			return new WP_Error( 'seocp_api_error', $message );
		}

		$text = '';
		if ( ! empty( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		$finish_reason = isset( $data['candidates'][0]['finishReason'] ) ? $data['candidates'][0]['finishReason'] : '';

		if ( '' === trim( $text ) ) {
			if ( 'MAX_TOKENS' === $finish_reason ) {
				return new WP_Error(
					'seocp_truncated_response',
					'AIの応答が出力上限に達し、本文を生成できませんでした(モデルの内部処理がトークン枠を使い切った可能性があります)。時間をおいて再度お試しください。'
				);
			}
			return new WP_Error( 'seocp_empty_response', 'AIから有効な応答が得られませんでした。' );
		}

		if ( 'MAX_TOKENS' === $finish_reason ) {
			$text = trim( $text ) . "\n\n※出力上限に達したため、この提案は途中までしか生成されていない可能性があります。";
		}

		return trim( $text );
	}

	/**
	 * Gemini APIの429エラー応答から、推奨される再試行までの待機秒数(RetryInfo.retryDelay)を取り出す。
	 * 例: "55.8s" -> 56 (切り上げ)。見つからない場合はnullを返す。
	 */
	private function extract_retry_delay_seconds( $data ) {
		if ( empty( $data['error']['details'] ) || ! is_array( $data['error']['details'] ) ) {
			return null;
		}
		foreach ( $data['error']['details'] as $detail ) {
			if ( isset( $detail['retryDelay'] ) ) {
				$seconds = (float) rtrim( $detail['retryDelay'], 's' );
				if ( $seconds > 0 ) {
					return (int) ceil( $seconds );
				}
			}
		}
		return null;
	}

	/* ==================== Groq ==================== */

	/**
	 * Groq API(OpenAI互換 /chat/completions)を呼び出す
	 */
	private function generate_text_groq( $prompt, $max_output_tokens, $timeout, $allow_self_retry, $is_retry = false ) {
		if ( empty( $this->groq_api_key ) ) {
			return new WP_Error( 'seocp_no_api_key', 'Groq APIキーが設定されていません。設定画面から登録してください。' );
		}

		$endpoint = 'https://api.groq.com/openai/v1/chat/completions';

		$body = array(
			'model'               => $this->groq_model,
			'messages'            => array(
				array( 'role' => 'user', 'content' => $prompt ),
			),
			'temperature'         => 0.4,
			'max_completion_tokens' => $max_output_tokens,
		);

		// gpt-oss系は推論(reasoning)モデルのため、Geminiのthinkingと同様に出力枠を
		// 推論トークンに使い切って本文が空になりやすい。推論を軽くして本文用の枠を確保する。
		if ( false !== stripos( $this->groq_model, 'gpt-oss' ) ) {
			$body['reasoning_effort'] = 'low';
		}

		$response = wp_remote_post( $endpoint, array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->groq_api_key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => $timeout,
		) );

		if ( is_wp_error( $response ) ) {
			if ( false !== strpos( $response->get_error_message(), 'timed out' ) ) {
				return new WP_Error(
					'seocp_timeout',
					sprintf( 'Groq APIへのリクエストがタイムアウトしました(%d秒)。時間をおいて再度お試しください。', $timeout )
				);
			}
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Groq APIエラー(HTTP ' . $code . ')';

			$is_rate_limited = ( 429 === $code )
				|| ( isset( $data['error']['code'] ) && 'rate_limit_exceeded' === $data['error']['code'] );

			if ( $is_rate_limited ) {
				$retry_delay = $this->extract_retry_delay_seconds_groq( $response, $message );

				if ( $allow_self_retry && ! $is_retry && null !== $retry_delay && $retry_delay <= 65 ) {
					sleep( $retry_delay + 1 );
					return $this->generate_text_groq( $prompt, $max_output_tokens, $timeout, $allow_self_retry, true );
				}

				$wait_text = null !== $retry_delay ? $retry_delay . '秒' : '1分';
				return new WP_Error(
					'seocp_rate_limited',
					sprintf(
						'Groq APIの無料枠のリクエスト上限(レート制限)に達しました。%sほど待ってから、もう一度お試しください。頻発する場合は設定画面で使用モデルを変更するか、Groq Consoleで上限をご確認ください。',
						$wait_text
					)
				);
			}

			if ( 401 === $code || 403 === $code ) {
				$message .= ' ／ Groq APIキーが正しくない、または権限がない可能性があります。設定画面のAPIキーをご確認ください。';
			}

			if ( 404 === $code || false !== stripos( $message, 'does not exist' ) || false !== stripos( $message, 'has been decommissioned' ) ) {
				$message .= sprintf(
					' ／ 使用中のモデル「%s」が廃止・利用不可の可能性があります。Groq Consoleのモデル一覧(https://console.groq.com/docs/models)で現在利用可能なモデルIDを確認し、設定画面で更新してください。',
					$this->groq_model
				);
			}

			return new WP_Error( 'seocp_api_error', $message );
		}

		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		$finish_reason = isset( $data['choices'][0]['finish_reason'] ) ? $data['choices'][0]['finish_reason'] : '';

		if ( '' === trim( (string) $text ) ) {
			if ( 'length' === $finish_reason ) {
				return new WP_Error(
					'seocp_truncated_response',
					'AIの応答が出力上限に達し、本文を生成できませんでした。時間をおいて再度お試しください。'
				);
			}
			return new WP_Error( 'seocp_empty_response', 'AIから有効な応答が得られませんでした。' );
		}

		if ( 'length' === $finish_reason ) {
			$text = trim( $text ) . "\n\n※出力上限に達したため、この提案は途中までしか生成されていない可能性があります。";
		}

		return trim( $text );
	}

	/**
	 * Groqの429応答から再試行までの待機秒数を取り出す。
	 * まずレスポンスヘッダのretry-afterを見て、無ければエラーメッセージ内の
	 * 「try again in 1.2s」のような表記を正規表現で拾う。
	 */
	private function extract_retry_delay_seconds_groq( $response, $message ) {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( $header && is_numeric( $header ) ) {
			return (int) ceil( (float) $header );
		}

		if ( preg_match( '/try again in\s+([0-9.]+)\s*s/i', $message, $matches ) ) {
			return (int) ceil( (float) $matches[1] );
		}

		return null;
	}
}
