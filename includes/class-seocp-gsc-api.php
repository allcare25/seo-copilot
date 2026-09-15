<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Search Console OAuth連携 & Search Analytics API呼び出し
 */
class SEOCP_GSC_API {

	const OPTION_TOKENS  = 'seocp_gsc_tokens';
	// webmasters.readonly: Search Analytics/URL Inspection用
	// indexing: インデックス登録リクエスト(Indexing API)用
	// analytics.readonly: GA4連携(コンバージョン数取得)用 (v0.7.0で追加)
	const SCOPE           = 'https://www.googleapis.com/auth/webmasters.readonly';
	const AUTH_ENDPOINT    = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_ENDPOINT   = 'https://oauth2.googleapis.com/token';
	const API_BASE         = 'https://www.googleapis.com/webmasters/v3';

	public function __construct() {
		// admin-post.php経由のコールバック(Cookie認証がブラウザ遷移で確実に効く標準方式)
		add_action( 'admin_post_seocp_gsc_oauth_callback', array( $this, 'handle_callback' ) );
		// 未ログイン状態でこのURLに来た場合(セッション切れ等)にも分かりやすい案内を出す
		add_action( 'admin_post_nopriv_seocp_gsc_oauth_callback', array( $this, 'handle_logged_out_callback' ) );
	}

	public function get_redirect_uri() {
		return admin_url( 'admin-post.php?action=seocp_gsc_oauth_callback' );
	}

	/**
	 * OAuth認可URLを生成
	 */
	public function get_authorization_url() {
		$client_id = SEOCP_Settings::get( 'gsc_client_id' );
		if ( ! $client_id ) {
			return new WP_Error( 'seocp_no_client_id', 'Google OAuthクライアントIDが設定されていません。' );
		}

		$state = wp_generate_password( 32, false );
		set_transient( 'seocp_gsc_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);

		return self::AUTH_ENDPOINT . '?' . http_build_query( $params );
	}

	/**
	 * Googleからのリダイレクトを処理(admin-post.php経由、要ログイン)
	 * 未ログイン時はWordPress core側が自動でadmin_post_nopriv_*に振り分けるため、
	 * ここに到達する時点でCookie認証は成立している。あとは権限チェックのみ行う。
	 */
	public function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。管理者アカウントでログインした状態でこの操作を行ってください。' );
		}

		$state       = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$saved_state = get_transient( 'seocp_gsc_oauth_state_' . get_current_user_id() );
		delete_transient( 'seocp_gsc_oauth_state_' . get_current_user_id() );

		if ( ! $state || ! $saved_state || ! hash_equals( $saved_state, $state ) ) {
			wp_die( '不正なリクエストです(state不一致)。もう一度接続をやり直してください。' );
		}

		if ( ! empty( $_GET['error'] ) ) {
			$this->redirect_to_settings( array( 'seocp_gsc_error' => sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( ! $code ) {
			$this->redirect_to_settings( array( 'seocp_gsc_error' => 'no_code' ) );
		}

		$result = $this->exchange_code_for_token( $code );
		if ( is_wp_error( $result ) ) {
			$this->redirect_to_settings( array( 'seocp_gsc_error' => $result->get_error_message() ) );
		}

		$this->redirect_to_settings( array( 'seocp_gsc_connected' => '1' ) );
	}

	/**
	 * 未ログイン状態でコールバックURLに来た場合(セッション切れ等)
	 */
	public function handle_logged_out_callback() {
		$login_url = wp_login_url( $this->get_redirect_uri() . '&' . http_build_query( $_GET ) );
		wp_die(
			'ログインセッションが切れています。<a href="' . esc_url( $login_url ) . '">再度ログイン</a>してから、設定画面で接続をやり直してください。'
		);
	}

	private function redirect_to_settings( $query_args ) {
		$url = add_query_arg( $query_args, admin_url( 'admin.php?page=seocp-settings' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function exchange_code_for_token( $code ) {
		$client_id     = SEOCP_Settings::get( 'gsc_client_id' );
		$client_secret = SEOCP_Settings::get( 'gsc_client_secret' );

		$response = wp_remote_post( self::TOKEN_ENDPOINT, array(
			'timeout' => 20,
			'body'    => array(
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $this->get_redirect_uri(),
				'grant_type'    => 'authorization_code',
			),
		) );

		return $this->store_token_response( $response );
	}

	private function refresh_access_token( $refresh_token ) {
		$client_id     = SEOCP_Settings::get( 'gsc_client_id' );
		$client_secret = SEOCP_Settings::get( 'gsc_client_secret' );

		$response = wp_remote_post( self::TOKEN_ENDPOINT, array(
			'timeout' => 20,
			'body'    => array(
				'refresh_token' => $refresh_token,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'refresh_token',
			),
		) );

		$result = $this->store_token_response( $response, $refresh_token );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result['access_token'];
	}

	private function store_token_response( $response, $fallback_refresh_token = '' ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error_description'] ) ? $data['error_description'] : ( $data['error'] ?? 'token_error' );
			return new WP_Error( 'seocp_gsc_token_error', $message );
		}

		$existing = get_option( self::OPTION_TOKENS, array() );

		$tokens = array(
			'access_token'  => $data['access_token'],
			'refresh_token' => isset( $data['refresh_token'] ) ? $data['refresh_token'] : ( $existing['refresh_token'] ?? $fallback_refresh_token ),
			'expires_at'    => time() + ( isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600 ),
			'scope'         => isset( $data['scope'] ) ? $data['scope'] : self::SCOPE,
		);

		update_option( self::OPTION_TOKENS, $tokens );

		return $tokens;
	}

	public function is_connected() {
		$tokens = get_option( self::OPTION_TOKENS, array() );
		return ! empty( $tokens['refresh_token'] );
	}

	/**
	 * 接続済みトークンにインデックス登録リクエスト(Indexing API)の権限(スコープ)が
	 * 含まれているか。旧バージョンで接続した場合はwebmasters.readonlyのみのため、
	 * インデックス登録リクエスト機能を使うには接続し直す(スコープを取り直す)必要がある。
	 */
	public function disconnect() {
		delete_option( self::OPTION_TOKENS );
	}

	/**
	 * 有効なアクセストークンを取得(必要なら自動リフレッシュ)
	 */
	public function get_valid_access_token() {
		$tokens = get_option( self::OPTION_TOKENS, array() );

		if ( empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'seocp_gsc_not_connected', 'Search Consoleに接続されていません。設定画面から接続してください。' );
		}

		if ( ! empty( $tokens['access_token'] ) && ! empty( $tokens['expires_at'] ) && $tokens['expires_at'] > ( time() + 60 ) ) {
			return $tokens['access_token'];
		}

		return $this->refresh_access_token( $tokens['refresh_token'] );
	}

	/**
	 * 認可済みSearch Consoleプロパティ一覧を取得
	 */
	public function list_sites() {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get( self::API_BASE . '/sites', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'サイト一覧の取得に失敗しました。';
			return new WP_Error( 'seocp_gsc_api_error', $message );
		}

		return isset( $data['siteEntry'] ) ? $data['siteEntry'] : array();
	}

	/**
	 * Search Analyticsデータ取得(ページ単位)
	 *
	 * @param string $site_url
	 * @param string $start_date Y-m-d
	 * @param string $end_date   Y-m-d
	 */
	public function fetch_search_analytics( $site_url, $start_date, $end_date, $row_limit = 2000 ) {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$endpoint = self::API_BASE . '/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';

		$body = array(
			'startDate'  => $start_date,
			'endDate'    => $end_date,
			'dimensions' => array( 'page' ),
			'rowLimit'   => $row_limit,
		);

		$response = wp_remote_post( $endpoint, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Search Analyticsデータの取得に失敗しました。';
			return new WP_Error( 'seocp_gsc_api_error', $message );
		}

		return isset( $data['rows'] ) ? $data['rows'] : array();
	}

	/**
	 * 取得結果をDBに保存し、改善優先度スコアを計算
	 */
	public function save_stats( $rows ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_PAGES;
		$today = current_time( 'Y-m-d' );

		// 順位急落・コンテンツ劣化の検知用に、直近(今日より前)の最新データを先に取得しておく
		$previous_stats = $this->get_previous_stats( $today );

		// 同日分は洗い替え(再取得時の重複防止)
		$wpdb->delete( $table, array( 'data_date' => $today ) );

		$saved = 0;
		$rank_drops  = array();
		$decay_drops = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['keys'][0] ) ) {
				continue;
			}
			$url         = $row['keys'][0];
			$post_id     = (int) url_to_postid( $url );
			$clicks      = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
			$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
			$ctr         = isset( $row['ctr'] ) ? (float) $row['ctr'] : 0;
			$position    = isset( $row['position'] ) ? (float) $row['position'] : 0;
			$priority    = $this->calc_priority_score( $impressions, $ctr, $position );

			$wpdb->insert(
				$table,
				array(
					'post_id'        => $post_id,
					'url'            => $url,
					'clicks'         => $clicks,
					'impressions'    => $impressions,
					'ctr'            => $ctr,
					'position'       => $position,
					'priority_score' => $priority,
					'data_date'      => $today,
				),
				array( '%d', '%s', '%d', '%d', '%f', '%f', '%f', '%s' )
			);
			$saved++;

			if ( isset( $previous_stats[ $url ] ) ) {
				$prev = $previous_stats[ $url ];

				// 順位急落検知(表示回数がある程度あるページのみ対象。ノイズの多い低表示回数ページは除外)
				if ( $impressions >= 10 ) {
					$drop = $position - $prev['position']; // 正の値 = 順位が下がった(数字が大きくなった)
					$threshold = (int) 5;
					if ( $drop >= $threshold ) {
						$rank_drops[] = sprintf( '%s: %s位 → %s位', $url, round( $prev['position'], 1 ), round( $position, 1 ) );
					}
				}

				// コンテンツ劣化検知(前回取得時にある程度クリックがあったページのみ対象。ノイズの多い低クリックページは除外)
				if ( $prev['clicks'] >= 5 ) {
					$drop_pct = ( ( $prev['clicks'] - $clicks ) / $prev['clicks'] ) * 100;
					$decay_threshold = (int) 30;
					if ( $drop_pct >= $decay_threshold ) {
						$decay_drops[] = sprintf( '%s: %d件 → %d件(%s%%減)', $url, $prev['clicks'], $clicks, round( $drop_pct, 1 ) );
					}
				}
			}
		}


		return $saved;
	}

	/**
	 * 「今日」より前で最も新しいdata_dateにおける、URLごとの順位・クリック数・表示回数を取得する
	 */
	private function get_previous_stats( $today ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_PAGES;

		$prev_date = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(data_date) FROM {$table} WHERE data_date < %s", $today
		) );
		if ( ! $prev_date ) {
			return array();
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, position, clicks, impressions FROM {$table} WHERE data_date = %s", $prev_date
		), ARRAY_A );

		$map = array();
		foreach ( $rows as $r ) {
			$map[ $r['url'] ] = array(
				'position'    => (float) $r['position'],
				'clicks'      => (int) $r['clicks'],
				'impressions' => (int) $r['impressions'],
			);
		}
		return $map;
	}

	/**
	 * 掲載順位から、業界一般値ベースの目安CTR(固定テーブル)を返す。
	 * 「CTRが低いページ」を判定する際の基準値として使う。
	 */
	public static function get_benchmark_ctr( $position ) {
		if ( $position <= 1 ) {
			return 0.28;
		} elseif ( $position <= 3 ) {
			return 0.15;
		} elseif ( $position <= 5 ) {
			return 0.08;
		} elseif ( $position <= 10 ) {
			return 0.04;
		}
		return 0.015;
	}

	/**
	 * 改善優先度スコア算出ロジック(CTRギャップ重視)
	 * 順位帯別の目安CTR(業界一般値)に対して実CTRがどれだけ下回っているか(%ptギャップ)を
	 * そのままスコアとする。目安CTRを上回っている(=ギャップがマイナス)場合は0とし、
	 * 改善候補から外れるようにする。
	 */
	private function calc_priority_score( $impressions, $ctr, $position ) {
		$benchmark_ctr = self::get_benchmark_ctr( $position );
		$ctr_gap       = max( 0, $benchmark_ctr - $ctr );
		return round( $ctr_gap * 100, 2 ); // %ptギャップをそのままスコアにする
	}

	/**
	 * DBに保存済みの最新データ日付を取得
	 */
	public function get_latest_data_date() {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_PAGES;
		return $wpdb->get_var( "SELECT MAX(data_date) FROM {$table}" );
	}

	/**
	 * URL Inspection APIでインデックス状況を検査
	 */
	public function inspect_url( $site_url, $inspection_url ) {
		$token = $this->get_valid_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$endpoint = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
		$body = array(
			'inspectionUrl' => $inspection_url,
			'siteUrl'       => $site_url,
		);

		$response = wp_remote_post( $endpoint, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'URL検査に失敗しました。';
			return new WP_Error( 'seocp_gsc_api_error', $message );
		}

		return isset( $data['inspectionResult'] ) ? $data['inspectionResult'] : array();
	}

	/**
	 * Indexing APIでインデックス登録(再クロール)をリクエストする。
	 *
	 * 注意: GoogleはこのAPIを公式にはJobPosting/BroadcastEvent(求人・ライブ配信)構造化データを
	 * 持つページ向けとして案内しており、通常のブログ記事等での利用は公式サポート対象外。
	 * エンドポイント自体は任意のURLを受け付けるため多くのサイトで実利用されているが、
	 * Googleがリクエストを無視する・優先度が低く扱われる可能性がある点は留意すること。
	 *
	 * @param string $url
	 * @param string $type 'URL_UPDATED'(既定, 新規/更新の通知) または 'URL_DELETED'
	 * @return array|WP_Error
	 */
	public function get_history_for_post( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_PAGES;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT data_date, clicks, impressions, ctr, position FROM {$table} WHERE post_id = %d ORDER BY data_date ASC",
				$post_id
			),
			ARRAY_A
		);
	}

	/**
	 * 履歴データが蓄積されている投稿ID一覧(重複除去)
	 */
	public function get_tracked_post_ids() {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_PAGES;
		return $wpdb->get_col( "SELECT DISTINCT post_id FROM {$table} WHERE post_id > 0 ORDER BY post_id" );
	}

	/**
	 * 改善優先ページ一覧(CTRギャップ降順)
	 *
	 * 表示回数が少ないページはCTRのブレが大きくノイズになるため、
	 * 直近取得分の表示回数が10未満のページは対象から除外する。
	 * 目安CTRを既に上回っている(priority_score = 0)ページも除外し、
	 * 該当するものは件数を絞らず全件返す。
	 */
	public function get_priority_pages( $limit = 0 ) {
		global $wpdb;
		$table       = $wpdb->prefix . SEOCP_TABLE_PAGES;
		$latest_date = $this->get_latest_data_date();

		if ( ! $latest_date ) {
			return array();
		}

		$sql = "SELECT * FROM {$table}
				WHERE data_date = %s
				AND impressions >= 10
				AND priority_score > 0
				ORDER BY priority_score DESC";

		$params = array( $latest_date );

		if ( $limit > 0 ) {
			$sql .= ' LIMIT %d';
			$params[] = $limit;
		}

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * コンテンツ劣化候補一覧
	 *
	 * 直近2回分の取得データ(data_date)を比較し、クリック数の下落率が
	 * 通知しきい値(設定)以上のページを、記事に紐づくもの(post_id > 0)に限定して返す。
	 * 「取得のたびに通知」とは別に、画面上でいつでも一覧確認できるようにするための一覧取得用メソッド。
	 */
	public function get_decay_pages() {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_PAGES;

		$dates = $this->get_recent_data_dates( 2 );
		if ( count( $dates ) < 2 ) {
			return array();
		}
		list( $latest_date, $prev_date ) = $dates;

		$latest_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, url, clicks, impressions, position FROM {$table} WHERE data_date = %s AND post_id > 0", $latest_date
		), ARRAY_A );
		$prev_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT url, clicks, impressions, position FROM {$table} WHERE data_date = %s", $prev_date
		), ARRAY_A );

		$prev_map = array();
		foreach ( $prev_rows as $r ) {
			$prev_map[ $r['url'] ] = $r;
		}

		$threshold = (int) 30;
		$candidates = array();
		foreach ( $latest_rows as $row ) {
			if ( ! isset( $prev_map[ $row['url'] ] ) ) {
				continue;
			}
			$prev = $prev_map[ $row['url'] ];
			if ( (int) $prev['clicks'] < 5 ) {
				continue; // ノイズの多い低クリックページは除外
			}
			$drop_pct = ( ( (int) $prev['clicks'] - (int) $row['clicks'] ) / (int) $prev['clicks'] ) * 100;
			if ( $drop_pct < $threshold ) {
				continue;
			}
			$candidates[] = array(
				'post_id'         => (int) $row['post_id'],
				'url'             => $row['url'],
				'prev_clicks'     => (int) $prev['clicks'],
				'latest_clicks'   => (int) $row['clicks'],
				'prev_impressions'   => (int) $prev['impressions'],
				'latest_impressions' => (int) $row['impressions'],
				'prev_position'   => (float) $prev['position'],
				'latest_position' => (float) $row['position'],
				'drop_pct'        => round( $drop_pct, 1 ),
				'prev_date'       => $prev_date,
				'latest_date'     => $latest_date,
			);
		}

		usort( $candidates, function ( $a, $b ) {
			return $b['drop_pct'] <=> $a['drop_pct'];
		} );

		return $candidates;
	}

	/**
	 * 直近の取得日(data_date)を新しい順に最大 $limit 件返す
	 */
	public function get_recent_data_dates( $limit = 2 ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_PAGES;
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT data_date FROM {$table} ORDER BY data_date DESC LIMIT %d", $limit
		) );
	}
}
