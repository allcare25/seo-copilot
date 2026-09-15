<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEOCP_Activator {

	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$table_llmo = $wpdb->prefix . SEOCP_TABLE_LLMO;
		dbDelta( "CREATE TABLE {$table_llmo} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_id BIGINT UNSIGNED NOT NULL, score SMALLINT NOT NULL DEFAULT 0, details LONGTEXT NULL, ai_suggestion LONGTEXT NULL, checked_at DATETIME NOT NULL, PRIMARY KEY (id), KEY post_id (post_id)) {$charset_collate};" );

		$table_pages = $wpdb->prefix . SEOCP_TABLE_PAGES;
		dbDelta( "CREATE TABLE {$table_pages} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_id BIGINT UNSIGNED NOT NULL, url VARCHAR(500) NOT NULL, clicks INT NOT NULL DEFAULT 0, impressions INT NOT NULL DEFAULT 0, ctr FLOAT NOT NULL DEFAULT 0, position FLOAT NOT NULL DEFAULT 0, priority_score FLOAT NOT NULL DEFAULT 0, data_date DATE NOT NULL, PRIMARY KEY (id), KEY post_id (post_id), KEY data_date (data_date)) {$charset_collate};" );

		$table_suggestions = $wpdb->prefix . SEOCP_TABLE_SUGGESTIONS;
		dbDelta( "CREATE TABLE {$table_suggestions} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_id BIGINT UNSIGNED NOT NULL, type VARCHAR(30) NOT NULL, content LONGTEXT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY post_id (post_id), KEY type (type)) {$charset_collate};" );

		$table_index = $wpdb->prefix . SEOCP_TABLE_INDEX;
		dbDelta( "CREATE TABLE {$table_index} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_id BIGINT UNSIGNED NOT NULL, url VARCHAR(500) NOT NULL, verdict VARCHAR(30) NULL, coverage_state VARCHAR(255) NULL, indexing_state VARCHAR(100) NULL, last_crawl_time VARCHAR(50) NULL, inspect_url VARCHAR(500) NULL, checked_at DATETIME NOT NULL, PRIMARY KEY (id), KEY post_id (post_id)) {$charset_collate};" );

		$table_effect = $wpdb->prefix . SEOCP_TABLE_EFFECT;
		dbDelta( "CREATE TABLE {$table_effect} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, post_id BIGINT UNSIGNED NOT NULL, note VARCHAR(255) NULL, baseline_start DATE NULL, baseline_end DATE NULL, baseline_clicks INT NOT NULL DEFAULT 0, baseline_impressions INT NOT NULL DEFAULT 0, baseline_ctr FLOAT NOT NULL DEFAULT 0, baseline_position FLOAT NOT NULL DEFAULT 0, started_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'tracking', PRIMARY KEY (id), KEY post_id (post_id)) {$charset_collate};" );

		if ( false === get_option( 'seocp_settings' ) ) {
			add_option( 'seocp_settings', array(
				'ai_provider'       => 'gemini',
				'gemini_api_key'    => '',
				'gemini_model'      => 'gemini-flash-latest',
				'groq_api_key'      => '',
				'groq_model'        => 'openai/gpt-oss-20b',
				'gsc_client_id'     => '',
				'gsc_client_secret' => '',
				'gsc_site_url'      => '',
			) );
		}

		// v1.0では自動キュー/自動インデックス登録を使用しないため、旧フックが残っている場合は解除する。
		$timestamp = wp_next_scheduled( 'seocp_process_index_queue' );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'seocp_process_index_queue' );
			$timestamp = wp_next_scheduled( 'seocp_process_index_queue' );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'seocp_process_index_queue' );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'seocp_process_index_queue' );
			$timestamp = wp_next_scheduled( 'seocp_process_index_queue' );
		}
	}
}
