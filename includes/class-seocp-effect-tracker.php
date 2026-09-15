<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 提案(タイトル変更・下書き反映など)を適用した前後で、Search Consoleの実績
 * (クリック数・表示回数・CTR・平均順位)がどう変化したかを追跡する。
 *
 * 使い方:
 * 1. 変更を加えた時点(またはこれから加える直前)で start_tracking() を呼ぶと、
 *    直近28日分の wp_seocp_page_stats を「基準値(baseline)」として保存する。
 * 2. 一定期間後、get_comparison() で基準値と「開始日以降に取得された最新データ」を比較する。
 *
 * ページ単位の日次データは既存のSearch Console取得機能(wp_seocp_page_stats)に依存するため、
 * 「Search Consoleデータ」画面で定期的にデータを取得しているほど精度が上がる。
 */
class SEOCP_Effect_Tracker {

	private function table() {
		global $wpdb;
		return $wpdb->prefix . SEOCP_TABLE_EFFECT;
	}

	private function pages_table() {
		global $wpdb;
		return $wpdb->prefix . SEOCP_TABLE_PAGES;
	}

	/**
	 * 現時点を基準としてトラッキングを開始する。
	 *
	 * @param int    $post_id
	 * @param string $note 何の変更か(例: 「タイトルをAI提案に変更」)
	 * @return int|WP_Error トラッキングレコードID
	 */
	public function start_tracking( $post_id, $note = '' ) {
		global $wpdb;

		$baseline = $this->aggregate_stats( $post_id, gmdate( 'Y-m-d', strtotime( '-28 days' ) ), gmdate( 'Y-m-d' ) );

		if ( 0 === $baseline['days_with_data'] ) {
			return new WP_Error( 'seocp_no_baseline_data', 'Search Consoleデータがまだありません。先に「Search Consoleデータ」画面でデータを取得してください。' );
		}

		$table = $this->table();
		$wpdb->insert( $table, array(
			'post_id'              => $post_id,
			'note'                 => sanitize_text_field( $note ),
			'baseline_start'       => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
			'baseline_end'         => gmdate( 'Y-m-d' ),
			'baseline_clicks'      => $baseline['clicks'],
			'baseline_impressions' => $baseline['impressions'],
			'baseline_ctr'         => $baseline['ctr'],
			'baseline_position'    => $baseline['position'],
			'started_at'           => current_time( 'mysql' ),
			'status'               => 'tracking',
		), array( '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%s', '%s' ) );

		return $wpdb->insert_id;
	}

	/**
	 * 指定期間の合計クリック数・表示回数・加重平均CTR・加重平均順位を算出する
	 */
	private function aggregate_stats( $post_id, $start_date, $end_date ) {
		global $wpdb;
		$table = $this->pages_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT clicks, impressions, position FROM {$table} WHERE post_id = %d AND data_date BETWEEN %s AND %s",
			$post_id, $start_date, $end_date
		), ARRAY_A );

		$clicks = 0;
		$impressions = 0;
		$weighted_position_sum = 0;

		foreach ( $rows as $r ) {
			$clicks += (int) $r['clicks'];
			$impressions += (int) $r['impressions'];
			$weighted_position_sum += (float) $r['position'] * (int) $r['impressions'];
		}

		$ctr      = $impressions > 0 ? round( $clicks / $impressions, 4 ) : 0;
		$position = $impressions > 0 ? round( $weighted_position_sum / $impressions, 2 ) : 0;

		return array(
			'clicks'         => $clicks,
			'impressions'    => $impressions,
			'ctr'            => $ctr,
			'position'       => $position,
			'days_with_data' => count( $rows ),
		);
	}

	public function get_tracking_records( $post_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE post_id = %d ORDER BY started_at DESC", $post_id
		), ARRAY_A );
	}

	/**
	 * 基準値と、トラッキング開始日以降〜現在までの実績を比較する
	 */
	public function get_comparison( $tracking_id ) {
		global $wpdb;
		$table = $this->table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $tracking_id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'seocp_not_found', 'トラッキング情報が見つかりません。' );
		}

		$start_date = gmdate( 'Y-m-d', strtotime( $row['started_at'] ) );
		$followup   = $this->aggregate_stats( (int) $row['post_id'], $start_date, gmdate( 'Y-m-d' ) );

		$compare = array(
			'baseline' => array(
				'clicks'      => (int) $row['baseline_clicks'],
				'impressions' => (int) $row['baseline_impressions'],
				'ctr'         => (float) $row['baseline_ctr'],
				'position'    => (float) $row['baseline_position'],
				'period'      => $row['baseline_start'] . ' 〜 ' . $row['baseline_end'],
			),
			'followup' => array(
				'clicks'      => $followup['clicks'],
				'impressions' => $followup['impressions'],
				'ctr'         => $followup['ctr'],
				'position'    => $followup['position'],
				'days_with_data' => $followup['days_with_data'],
				'period'      => $start_date . ' 〜 ' . gmdate( 'Y-m-d' ),
			),
			'note'       => $row['note'],
			'started_at' => $row['started_at'],
		);

		if ( $followup['position'] > 0 && $row['baseline_position'] > 0 ) {
			$compare['position_change'] = round( $row['baseline_position'] - $followup['position'], 2 ); // 正の値=順位改善(数字が小さくなった)
		}
		if ( $row['baseline_ctr'] > 0 ) {
			$compare['ctr_change_pct'] = round( ( $followup['ctr'] - $row['baseline_ctr'] ) / $row['baseline_ctr'] * 100, 1 );
		}

		return $compare;
	}

	public function delete_tracking( $tracking_id ) {
		global $wpdb;
		return $wpdb->delete( $this->table(), array( 'id' => $tracking_id ), array( '%d' ) );
	}
}
