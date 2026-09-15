<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEOCP_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_seocp_run_llmo_check', array( $this, 'ajax_run_llmo_check' ) );
		add_action( 'wp_ajax_seocp_generate_ai_suggestion', array( $this, 'ajax_generate_ai_suggestion' ) );
		add_action( 'wp_ajax_seocp_preview_llmo_suggestion', array( $this, 'ajax_preview_llmo_suggestion' ) );
		add_action( 'wp_ajax_seocp_confirm_llmo_suggestion', array( $this, 'ajax_confirm_llmo_suggestion' ) );
		// v0.7.4: 実行時間の短いホスティング(ロリポップ等)向けの断片ステップ実行方式
		add_action( 'wp_ajax_seocp_start_llmo_revision', array( $this, 'ajax_start_llmo_revision' ) );
		add_action( 'wp_ajax_seocp_process_llmo_revision_step', array( $this, 'ajax_process_llmo_revision_step' ) );
		add_action( 'wp_ajax_seocp_check_site_wide', array( $this, 'ajax_check_site_wide' ) );
		add_action( 'wp_ajax_seocp_get_low_score_posts', array( $this, 'ajax_get_low_score_posts' ) );

		add_action( 'wp_ajax_seocp_gsc_get_auth_url', array( $this, 'ajax_gsc_get_auth_url' ) );
		add_action( 'wp_ajax_seocp_gsc_disconnect', array( $this, 'ajax_gsc_disconnect' ) );
		add_action( 'wp_ajax_seocp_gsc_list_sites', array( $this, 'ajax_gsc_list_sites' ) );
		add_action( 'wp_ajax_seocp_gsc_save_site', array( $this, 'ajax_gsc_save_site' ) );
		add_action( 'wp_ajax_seocp_gsc_fetch_data', array( $this, 'ajax_gsc_fetch_data' ) );

		add_action( 'wp_ajax_seocp_generate_title', array( $this, 'ajax_generate_title' ) );
		add_action( 'wp_ajax_seocp_apply_title', array( $this, 'ajax_apply_title' ) );
		add_action( 'wp_ajax_seocp_generate_meta_description', array( $this, 'ajax_generate_meta_description' ) );
		add_action( 'wp_ajax_seocp_apply_meta_description', array( $this, 'ajax_apply_meta_description' ) );
		add_action( 'wp_ajax_seocp_generate_faq', array( $this, 'ajax_generate_faq' ) );
		add_action( 'wp_ajax_seocp_generate_internal_links', array( $this, 'ajax_generate_internal_links' ) );
		add_action( 'wp_ajax_seocp_generate_draft', array( $this, 'ajax_generate_draft' ) );

		// v0.9.7: タイトル案/FAQ案/内部リンク候補をその場で記事に反映 + プレビュー/編集パネル
		add_action( 'wp_ajax_seocp_apply_faq', array( $this, 'ajax_apply_faq' ) );
		add_action( 'wp_ajax_seocp_apply_links', array( $this, 'ajax_apply_links' ) );
		add_action( 'wp_ajax_seocp_get_post_preview', array( $this, 'ajax_get_post_preview' ) );
		add_action( 'wp_ajax_seocp_save_post_content', array( $this, 'ajax_save_post_content' ) );

		add_action( 'wp_ajax_seocp_check_index_status', array( $this, 'ajax_check_index_status' ) );
		add_action( 'wp_ajax_seocp_get_history_data', array( $this, 'ajax_get_history_data' ) );



		// v0.7.0: 効果測定
		add_action( 'wp_ajax_seocp_start_effect_tracking', array( $this, 'ajax_start_effect_tracking' ) );
		add_action( 'wp_ajax_seocp_get_effect_comparison', array( $this, 'ajax_get_effect_comparison' ) );
		add_action( 'wp_ajax_seocp_get_effect_records', array( $this, 'ajax_get_effect_records' ) );



		// v0.9.4: 生成した下書きをページ内でプレビュー/編集

		// 投稿公開時、設定で有効化されていれば自動でインデックス登録をリクエストする
	}

	public function register_menu() {
		add_menu_page(
			'SEO運用支援',
			'SEO運用支援',
			'manage_options',
			'seocp-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-line',
			58
		);

		add_submenu_page( 'seocp-dashboard', 'ダッシュボード', 'ダッシュボード', 'manage_options', 'seocp-dashboard', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'seocp-dashboard', 'AI検索チェック(LLMO/AEO)', 'AI検索チェック(LLMO/AEO)', 'manage_options', 'seocp-llmo', array( $this, 'render_llmo' ) );
		add_submenu_page( 'seocp-dashboard', 'Search Consoleデータ', 'Search Consoleデータ(改善優先度)', 'manage_options', 'seocp-gsc', array( $this, 'render_gsc' ) );
		add_submenu_page( 'seocp-dashboard', 'AI提案(タイトル・FAQ・内部リンク)', 'AI提案(タイトル/FAQ/リンク)', 'manage_options', 'seocp-suggestions', array( $this, 'render_suggestions' ) );
		add_submenu_page( 'seocp-dashboard', 'インデックス登録対象ページ', 'インデックス登録対象ページ', 'manage_options', 'seocp-index', array( $this, 'render_index' ) );
		add_submenu_page( 'seocp-dashboard', '更新履歴・順位変化・効果測定', '更新履歴・順位変化・効果測定', 'manage_options', 'seocp-history', array( $this, 'render_history' ) );
		add_submenu_page( 'seocp-dashboard', '設定', '設定', 'manage_options', 'seocp-settings', array( $this, 'render_settings' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'seocp' ) === false ) {
			return;
		}
		wp_enqueue_style( 'seocp-admin', SEOCP_PLUGIN_URL . 'assets/css/admin.css', array(), SEOCP_VERSION );
		wp_enqueue_script( 'seocp-admin', SEOCP_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), SEOCP_VERSION, true );
		wp_localize_script( 'seocp-admin', 'SEOCP', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'seocp_nonce' ),
		) );
	}

	public function render_dashboard() {
		include SEOCP_PLUGIN_DIR . 'admin/views/dashboard-page.php';
	}

	public function render_llmo() {
		include SEOCP_PLUGIN_DIR . 'admin/views/llmo-page.php';
	}

	public function render_settings() {
		include SEOCP_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	public function render_gsc() {
		include SEOCP_PLUGIN_DIR . 'admin/views/gsc-page.php';
	}

	public function render_suggestions() {
		include SEOCP_PLUGIN_DIR . 'admin/views/suggestions-page.php';
	}



	public function render_index() {
		include SEOCP_PLUGIN_DIR . 'admin/views/index-page.php';
	}

	public function render_history() {
		include SEOCP_PLUGIN_DIR . 'admin/views/history-page.php';
	}

	public function render_placeholder() {
		echo '<div class="wrap"><h1>準備中</h1><p>この機能は次のステップで実装予定です。</p></div>';
	}

	/* ----------------- AJAX ----------------- */

	private function verify_request() {
		check_ajax_referer( 'seocp_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
		}
	}

	public function ajax_run_llmo_check() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$checker = new SEOCP_LLMO_Checker();
		$result  = $checker->check_post( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$checker->save_result( $post_id, $result );

		wp_send_json_success( $result );
	}

	public function ajax_generate_ai_suggestion() {
		$this->verify_request();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$checker = new SEOCP_LLMO_Checker();
		$latest  = $checker->get_latest_result( $post_id );

		if ( ! $latest ) {
			// 未チェックならまず実行
			$result = $checker->check_post( $post_id );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			$checker->save_result( $post_id, $result );
			$checks = $result['checks'];
		} else {
			$checks = $latest['details'];
		}

		$suggestion = $checker->generate_ai_suggestion( $post_id, $checks );

		if ( is_wp_error( $suggestion ) ) {
			wp_send_json_error( array( 'message' => $suggestion->get_error_message() ) );
		}

		wp_send_json_success( array( 'suggestion' => $suggestion ) );
	}

	public function ajax_check_site_wide() {
		$this->verify_request();
		$checker = new SEOCP_LLMO_Checker();
		$result  = $checker->check_site_wide();
		wp_send_json_success( $result );
	}

	/**
	 * v0.7.0: 本文上書きの「差分プレビュー」を生成する(この時点ではまだ保存しない)
	 */
	public function ajax_preview_llmo_suggestion() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		// チェックボックスで選択された提案項目のインデックス(0始まり)。
		// 未指定の場合はコントローラー側で「全件反映」として扱われる。
		$selected = null;
		if ( isset( $_POST['selected'] ) && is_array( $_POST['selected'] ) ) {
			$selected = array_map( 'absint', wp_unslash( $_POST['selected'] ) );
		}

		$checker = new SEOCP_LLMO_Checker();
		$result  = $checker->preview_apply_suggestion( $post_id, $selected );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * v0.7.0: プレビュー確認後、実際に本文へ反映する
	 */
	public function ajax_confirm_llmo_suggestion() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$checker = new SEOCP_LLMO_Checker();
		$result  = $checker->confirm_apply_suggestion( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * v0.7.4: ステップ実行方式のジョブを開始する(断片分割・準備のみ、AI呼び出しはまだ行わない)。
	 */
	public function ajax_start_llmo_revision() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$selected = null;
		if ( isset( $_POST['selected'] ) && is_array( $_POST['selected'] ) ) {
			$selected = array_map( 'absint', wp_unslash( $_POST['selected'] ) );
		}

		$checker = new SEOCP_LLMO_Checker();
		$result  = $checker->start_revision_job( $post_id, $selected );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * v0.7.4: ジョブの1ステップ(断片1つ、またはJSON-LD生成1回)だけを処理して返す。
	 * done:trueが返るまでフロント側が繰り返し呼び出す。
	 */
	public function ajax_process_llmo_revision_step() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$checker = new SEOCP_LLMO_Checker();
		$result  = $checker->process_revision_job_step( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * v0.7.0: サイト内スコアが低い記事の一覧(複数記事の一括LLMOスコアリング用パネル)
	 */
	public function ajax_get_low_score_posts() {
		$this->verify_request();
		$checker = new SEOCP_LLMO_Checker();
		$rows    = $checker->get_low_score_posts( 10 );

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'post_id'    => (int) $r['post_id'],
				'title'      => get_the_title( $r['post_id'] ),
				'edit_url'   => admin_url( 'post.php?post=' . $r['post_id'] . '&action=edit' ),
				'score'      => (int) $r['score'],
				'checked_at' => $r['checked_at'],
			);
		}
		wp_send_json_success( array( 'posts' => $out ) );
	}

	/* ----------------- GSC AJAX ----------------- */

	public function ajax_gsc_get_auth_url() {
		$this->verify_request();
		$gsc = new SEOCP_GSC_API();
		$url = $gsc->get_authorization_url();
		if ( is_wp_error( $url ) ) {
			wp_send_json_error( array( 'message' => $url->get_error_message() ) );
		}
		wp_send_json_success( array( 'url' => $url ) );
	}

	public function ajax_gsc_disconnect() {
		$this->verify_request();
		$gsc = new SEOCP_GSC_API();
		$gsc->disconnect();
		wp_send_json_success();
	}

	public function ajax_gsc_list_sites() {
		$this->verify_request();
		$gsc   = new SEOCP_GSC_API();
		$sites = $gsc->list_sites();
		if ( is_wp_error( $sites ) ) {
			wp_send_json_error( array( 'message' => $sites->get_error_message() ) );
		}
		wp_send_json_success( array( 'sites' => $sites ) );
	}

	public function ajax_gsc_save_site() {
		$this->verify_request();
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( ! $site_url ) {
			wp_send_json_error( array( 'message' => 'サイトURLが不正です。' ) );
		}
		$settings = get_option( 'seocp_settings', array() );
		$settings['gsc_site_url'] = $site_url;
		update_option( 'seocp_settings', $settings );
		wp_send_json_success();
	}

	public function ajax_gsc_fetch_data() {
		$this->verify_request();

		$settings = get_option( 'seocp_settings', array() );
		$site_url = isset( $settings['gsc_site_url'] ) ? $settings['gsc_site_url'] : '';
		if ( ! $site_url ) {
			wp_send_json_error( array( 'message' => '対象のSearch ConsoleプロパティURLが選択されていません。' ) );
		}

		$days       = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 28;
		$days       = $days > 0 ? min( $days, 90 ) : 28;
		$end_date   = gmdate( 'Y-m-d', strtotime( '-3 days' ) ); // GSCデータは数日遅延するため直近3日を除外
		$start_date = gmdate( 'Y-m-d', strtotime( '-' . ( $days + 3 ) . ' days' ) );

		$gsc  = new SEOCP_GSC_API();
		$rows = $gsc->fetch_search_analytics( $site_url, $start_date, $end_date );

		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
		}

		$saved = $gsc->save_stats( $rows );

		wp_send_json_success( array(
			'saved_count' => $saved,
			'start_date'  => $start_date,
			'end_date'    => $end_date,
			'pages'       => $gsc->get_priority_pages(),
		) );
	}

	/* ----------------- AI提案 AJAX ----------------- */

	public function ajax_generate_title() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$engine = new SEOCP_Suggestion_Engine();
		$result = $engine->generate_title_suggestions( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * 生成されたタイトル案のうち選択された1件を、記事のタイトルへ直接反映する
	 */
	public function ajax_apply_title() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		if ( ! $post_id || '' === $title ) {
			wp_send_json_error( array( 'message' => 'post_idまたはtitleが不正です。' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => '投稿が見つかりません。' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'この投稿を編集する権限がありません。' ), 403 );
		}

		$updated = wp_update_post( array(
			'ID'         => $post_id,
			'post_title' => $title,
		), true );

		if ( is_wp_error( $updated ) ) {
			wp_send_json_error( array( 'message' => $updated->get_error_message() ) );
		}

		// v0.9.7: プレビュー/編集パネルが開いている場合にその場で更新できるよう、
		// タイトルだけでなく本文・プレビューHTMLも合わせて返す。
		$applier = new SEOCP_Content_Applier();
		$preview = $applier->get_preview( $post_id );
		if ( is_wp_error( $preview ) ) {
			wp_send_json_success( array(
				'post_id' => $post_id,
				'title'   => $title,
			) );
		}

		wp_send_json_success( $preview );
	}

	public function ajax_generate_meta_description() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$engine = new SEOCP_Suggestion_Engine();
		$result = $engine->generate_meta_description_suggestions( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * 生成されたメタディスクリプション案のうち選択された1件を反映する。
	 * Yoast SEO / Rank Math / All in One SEO / SEOPress が有効な場合はそのメタキーに、
	 * どれも無い場合は本プラグイン独自のメタキー(wp_headで出力)に保存する。
	 */
	public function ajax_apply_meta_description() {
		$this->verify_request();
		$post_id     = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( ! $post_id || '' === $description ) {
			wp_send_json_error( array( 'message' => 'post_idまたはdescriptionが不正です。' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => '投稿が見つかりません。' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'この投稿を編集する権限がありません。' ), 403 );
		}

		$meta_manager = new SEOCP_Meta_Description();
		$result       = $meta_manager->apply( $post_id, $description );

		wp_send_json_success( array(
			'post_id'      => $post_id,
			'description'  => $description,
			'length'       => SEOCP_Meta_Description::count_length( $description ),
			'source_label' => $result['source_label'],
		) );
	}

	public function ajax_generate_faq() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$engine = new SEOCP_Suggestion_Engine();
		$result = $engine->generate_faq_suggestions( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_generate_internal_links() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$engine = new SEOCP_Suggestion_Engine();
		$result = $engine->suggest_internal_links( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	public function ajax_generate_draft() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$use_title = isset( $_POST['use_title'] ) && '1' === $_POST['use_title'];
		$add_faq   = isset( $_POST['add_faq'] ) && '1' === $_POST['add_faq'];
		$add_links = isset( $_POST['add_links'] ) && '1' === $_POST['add_links'];

		$generator = new SEOCP_Draft_Generator();
		$result    = $generator->generate_draft( $post_id, array(
			'use_title' => $use_title,
			'add_faq'   => $add_faq,
			'add_links' => $add_links,
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * 生成済みのFAQ案のうち選択されたものを、記事本文にその場で追記反映する。
	 * (クライアントから送られるのは選択したインデックスのみで、実際の文面はDBに保存済みの
	 * 最新のAI提案から取得する — クライアント側で文面を改ざんできないようにするため)
	 */
	public function ajax_apply_faq() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$indices = isset( $_POST['indices'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['indices'] ) ) : array();

		if ( ! $post_id || empty( $indices ) ) {
			wp_send_json_error( array( 'message' => 'post_idまたはindicesが不正です。' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'この投稿を編集する権限がありません。' ), 403 );
		}

		$engine = new SEOCP_Suggestion_Engine();
		$saved  = $engine->get_latest_suggestion( $post_id, SEOCP_Suggestion_Engine::TYPE_FAQ );
		if ( ! $saved || empty( $saved['content']['faqs'] ) ) {
			wp_send_json_error( array( 'message' => '先に「FAQ案」を生成してください。' ) );
		}

		$all_faqs = $saved['content']['faqs'];
		$selected = array();
		foreach ( $indices as $i ) {
			if ( isset( $all_faqs[ $i ] ) ) {
				$selected[] = $all_faqs[ $i ];
			}
		}
		if ( empty( $selected ) ) {
			wp_send_json_error( array( 'message' => '反映するFAQを選択してください。' ) );
		}

		$applier = new SEOCP_Content_Applier();
		$result  = $applier->apply_faq( $post_id, $selected );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$result['applied_count'] = count( $selected );
		wp_send_json_success( $result );
	}

	/**
	 * 生成済みの内部リンク候補のうち選択されたものを、記事本文にその場で追記反映する。
	 */
	public function ajax_apply_links() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$indices = isset( $_POST['indices'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['indices'] ) ) : array();

		if ( ! $post_id || empty( $indices ) ) {
			wp_send_json_error( array( 'message' => 'post_idまたはindicesが不正です。' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'この投稿を編集する権限がありません。' ), 403 );
		}

		$engine = new SEOCP_Suggestion_Engine();
		$saved  = $engine->get_latest_suggestion( $post_id, SEOCP_Suggestion_Engine::TYPE_INTERNAL );
		if ( ! $saved || empty( $saved['content']['links'] ) ) {
			wp_send_json_error( array( 'message' => '先に「内部リンク候補」を生成してください。' ) );
		}

		$all_links = $saved['content']['links'];
		$selected  = array();
		foreach ( $indices as $i ) {
			if ( isset( $all_links[ $i ] ) ) {
				$selected[] = $all_links[ $i ];
			}
		}
		if ( empty( $selected ) ) {
			wp_send_json_error( array( 'message' => '反映する内部リンクを選択してください。' ) );
		}

		$applier = new SEOCP_Content_Applier();
		$result  = $applier->apply_links( $post_id, $selected );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$result['applied_count'] = count( $selected );
		wp_send_json_success( $result );
	}

	/**
	 * プレビュー/編集パネルを開いたとき、現在の記事のタイトル・本文・プレビューHTMLを返す。
	 */
	public function ajax_get_post_preview() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$applier = new SEOCP_Content_Applier();
		$result  = $applier->get_preview( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * プレビュー/編集パネルでの編集内容(タイトル・本文)を記事に保存する。
	 */
	public function ajax_save_post_content() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'post_idが不正、または編集権限がありません。' ) );
		}

		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : null;

		$applier = new SEOCP_Content_Applier();
		$result  = $applier->save_content( $post_id, $content, $title );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/* ----------------- インデックス状況 / 履歴 AJAX ----------------- */

	public function ajax_check_index_status() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$checker = new SEOCP_Index_Checker();
		$result  = $checker->check_post( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}


	/**
	 * 投稿が新規公開された際、設定で有効化されていれば自動でインデックス登録をリクエストする。
	 * リクエスト処理はレスポンスを遅らせないよう shutdown フックまで遅延させる。
	 */


	public function ajax_get_history_data() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$gsc     = new SEOCP_GSC_API();
		$history = $gsc->get_history_for_post( $post_id );

		// コンテンツ更新履歴(リビジョン)の日時も合わせて返す
		$revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 10, 'orderby' => 'date', 'order' => 'ASC' ) );
		$revision_dates = array();
		foreach ( $revisions as $rev ) {
			$revision_dates[] = mysql2date( 'Y-m-d', $rev->post_modified );
		}
		$revision_dates = array_values( array_unique( $revision_dates ) );

		wp_send_json_success( array(
			'history'        => $history,
			'revision_dates' => $revision_dates,
			'title'          => get_the_title( $post_id ),
		) );
	}

	/* ----------------- v0.7.0: 一括処理キュー AJAX ----------------- */




	/* ----------------- v0.7.0: 通知テスト送信 AJAX ----------------- */


	/* ----------------- v0.7.0: 効果測定 AJAX ----------------- */

	public function ajax_start_effect_tracking() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$note    = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}

		$tracker = new SEOCP_Effect_Tracker();
		$id      = $tracker->start_tracking( $post_id, $note ?: '手動で記録' );

		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}
		wp_send_json_success( array( 'tracking_id' => $id, 'records' => $tracker->get_tracking_records( $post_id ) ) );
	}

	public function ajax_get_effect_records() {
		$this->verify_request();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_idが不正です。' ) );
		}
		$tracker = new SEOCP_Effect_Tracker();
		wp_send_json_success( array( 'records' => $tracker->get_tracking_records( $post_id ) ) );
	}

	public function ajax_get_effect_comparison() {
		$this->verify_request();
		$tracking_id = isset( $_POST['tracking_id'] ) ? absint( $_POST['tracking_id'] ) : 0;
		if ( ! $tracking_id ) {
			wp_send_json_error( array( 'message' => 'tracking_idが不正です。' ) );
		}
		$tracker = new SEOCP_Effect_Tracker();
		$result  = $tracker->get_comparison( $tracking_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/* ----------------- v0.7.0: 競合比較 AJAX ----------------- */


	/* ----------------- v0.7.0: GA4 AJAX ----------------- */


	/* ----------------- v0.9.3: 被リンク獲得コンテンツ提案 AJAX ----------------- */




	/**
	 * 生成済み(または再取得したい)下書きのタイトル・本文・プレビューHTMLを返す。
	 */


	/**
	 * プレビュー/編集パネルでの編集内容を下書き記事に保存する。
	 */

}
