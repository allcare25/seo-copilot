<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Search Console URL Inspection APIを使ってインデックス状況を確認する。
 * v1.0ではインデックス登録リクエストは行わない。
 */
class SEOCP_Index_Checker {

	public function check_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seocp_invalid_post', '投稿が見つかりません。' );
		}

		$settings = get_option( 'seocp_settings', array() );
		$site_url = isset( $settings['gsc_site_url'] ) ? $settings['gsc_site_url'] : '';
		if ( ! $site_url ) {
			return new WP_Error( 'seocp_no_site', 'Search Consoleのプロパティが未選択です。設定画面から選択してください。' );
		}

		$url = get_permalink( $post_id );
		$gsc = new SEOCP_GSC_API();
		$result = $gsc->inspect_url( $site_url, $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$idx = isset( $result['indexStatusResult'] ) ? $result['indexStatusResult'] : array();
		$inspect_url = isset( $result['inspectionResultLink'] )
			? $result['inspectionResultLink']
			: $this->build_inspect_tool_url( $site_url, $url );

		$payload = array(
			'post_id'         => $post_id,
			'url'             => $url,
			'verdict'         => isset( $idx['verdict'] ) ? $idx['verdict'] : 'UNKNOWN',
			'coverage_state'  => isset( $idx['coverageState'] ) ? $idx['coverageState'] : '',
			'indexing_state'  => isset( $idx['indexingState'] ) ? $idx['indexingState'] : '',
			'last_crawl_time' => isset( $idx['lastCrawlTime'] ) ? $idx['lastCrawlTime'] : '',
			'inspect_url'     => $inspect_url,
		);

		$this->save( $payload );
		return $payload;
	}

	private function build_inspect_tool_url( $site_url, $url ) {
		return 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode( $site_url ) . '&id=' . rawurlencode( $url );
	}

	private function save( $payload ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_INDEX;
		$wpdb->insert(
			$table,
			array(
				'post_id'         => $payload['post_id'],
				'url'             => $payload['url'],
				'verdict'         => $payload['verdict'],
				'coverage_state'  => $payload['coverage_state'],
				'indexing_state'  => $payload['indexing_state'],
				'last_crawl_time' => $payload['last_crawl_time'],
				'inspect_url'     => $payload['inspect_url'],
				'checked_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function get_latest( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . SEOCP_TABLE_INDEX;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d ORDER BY checked_at DESC LIMIT 1",
				$post_id
			),
			ARRAY_A
		);
	}

	public function get_latest_map( array $post_ids ) {
		$map = array_fill_keys( $post_ids, null );
		if ( empty( $post_ids ) ) {
			return $map;
		}
		global $wpdb;
		$table        = $wpdb->prefix . SEOCP_TABLE_INDEX;
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id IN ({$placeholders}) ORDER BY checked_at ASC",
				$post_ids
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			$map[ (int) $row['post_id'] ] = $row;
		}
		return $map;
	}
}
