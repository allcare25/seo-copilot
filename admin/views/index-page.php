<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_option( 'seocp_settings', array() );
$site_url = isset( $settings['gsc_site_url'] ) ? $settings['gsc_site_url'] : '';
$checker  = new SEOCP_Index_Checker();

$all_posts = get_posts( array(
	'post_type'      => array( 'post', 'page' ),
	'post_status'    => 'publish',
	'posts_per_page' => 50,
	'orderby'        => 'date',
	'order'          => 'DESC',
) );
$latest_map = $checker->get_latest_map( wp_list_pluck( $all_posts, 'ID' ) );
?>
<div class="wrap seocp-wrap">
	<h1>インデックス状況</h1>
	<p>Google Search ConsoleのURL検査を使って、公開済みページのインデックス状況を確認します。</p>

	<?php if ( ! $site_url ) : ?>
		<div class="notice notice-warning">
			<p>Search Consoleのプロパティが未選択です。<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-settings' ) ); ?>">設定画面</a>で接続・選択してください。</p>
		</div>
	<?php else : ?>
		<p class="description">
			この機能は「確認」に対応しています。インデックス登録の可否や順位を保証するものではありません。
			登録リクエストが必要な場合は、URL検査結果からGoogle Search Consoleを開いて確認してください。
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th style="width:30%;">タイトル</th>
					<th style="width:150px;">ステータス</th>
					<th>詳細</th>
					<th style="width:220px;">操作</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $all_posts as $p ) :
				$latest = isset( $latest_map[ $p->ID ] ) ? $latest_map[ $p->ID ] : null;
			?>
				<tr data-post-id="<?php echo esc_attr( $p->ID ); ?>">
					<td>
						<strong><?php echo esc_html( get_the_title( $p ) ); ?></strong><br>
						<a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $p ) ); ?></a>
					</td>
					<td class="seocp-index-status-cell">
						<?php if ( $latest ) : ?>
							<?php echo 'PASS' === $latest['verdict'] ? '<span class="seocp-badge-pass">インデックス済み</span>' : '<span class="seocp-badge-fail">未インデックス/要確認</span>'; ?>
						<?php else : ?>
							未チェック
						<?php endif; ?>
					</td>
					<td class="seocp-index-detail-cell" style="font-size:12px;">
						<?php if ( $latest ) : ?>
							verdict: <?php echo esc_html( $latest['verdict'] ); ?><br>
							coverageState: <?php echo esc_html( $latest['coverage_state'] ); ?><br>
							最終確認: <?php echo esc_html( mysql2date( 'Y-m-d H:i', $latest['checked_at'] ) ); ?>
						<?php else : ?>
							-
						<?php endif; ?>
					</td>
					<td>
						<button type="button" class="button seocp-check-index">インデックス状況を確認</button>
						<?php if ( $latest && ! empty( $latest['inspect_url'] ) ) : ?>
							<br><a href="<?php echo esc_url( $latest['inspect_url'] ); ?>" target="_blank" rel="noopener" style="font-size:12px;">Search Consoleで確認</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
