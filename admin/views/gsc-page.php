<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gsc       = new SEOCP_GSC_API();
$connected = $gsc->is_connected();
$settings  = get_option( 'seocp_settings', array() );
$site_url  = isset( $settings['gsc_site_url'] ) ? $settings['gsc_site_url'] : '';
$latest_date = $gsc->get_latest_data_date();
$pages     = $latest_date ? $gsc->get_priority_pages() : array();


$decay_pages       = $latest_date ? $gsc->get_decay_pages() : array();
$decay_data_dates  = $latest_date ? $gsc->get_recent_data_dates( 2 ) : array();

$meta_manager = new SEOCP_Meta_Description();
?>
<div class="wrap seocp-wrap">
	<h1>Search Consoleデータ</h1>

	<?php if ( ! $connected ) : ?>
		<div class="notice notice-warning">
			<p>Search Consoleに未接続です。<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-settings' ) ); ?>">設定画面</a>から接続してください。</p>
		</div>
	<?php elseif ( ! $site_url ) : ?>
		<div class="notice notice-warning">
			<p>対象プロパティが未選択です。<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-settings' ) ); ?>">設定画面</a>でプロパティを選択してください。</p>
		</div>
	<?php else : ?>
		<p>対象プロパティ: <strong><?php echo esc_html( $site_url ); ?></strong>
			<?php if ( $latest_date ) : ?>
				／ 最終取得日: <strong><?php echo esc_html( $latest_date ); ?></strong>
			<?php endif; ?>
		</p>
		<p>
			<label for="seocp-gsc-days">取得期間:</label>
			<select id="seocp-gsc-days">
				<option value="7">直近7日</option>
				<option value="28" selected>直近28日</option>
				<option value="90">直近90日</option>
			</select>
			<button type="button" class="button button-primary" id="seocp-gsc-fetch">最新データを取得</button>
		</p>
		<div id="seocp-gsc-fetch-result"></div>
	<?php endif; ?>

	<h2 style="margin-top:20px;">CTR改善優先ページ(直近28日・表示回数10未満は除外)</h2>
	<p class="description">
		掲載順位帯ごとの業界一般的な目安CTRと比べて実際のCTRが低いページを、そのギャップ(%pt)が大きい順にすべて表示しています。
		目安CTRを既に上回っているページは表示されません。「タイトル案」「説明文案」ボタンでAIによる改善案を生成し、その場で反映できます。
	</p>
	<table class="widefat striped" id="seocp-gsc-table">
		<thead>
			<tr>
				<th style="width:80px; text-align:right;">CTRギャップ</th>
				<th>記事タイトル / URL</th>
				<th style="width:90px; text-align:right;">クリック</th>
				<th style="width:90px; text-align:right;">表示回数</th>
				<th style="width:80px; text-align:right;">実CTR</th>
				<th style="width:80px; text-align:right;">目安CTR</th>
				<th style="width:90px; text-align:right;">平均順位</th>
				<th style="width:130px;">AI提案</th>
			</tr>
		</thead>
		<?php if ( empty( $pages ) ) : ?>
			<tbody>
				<tr><td colspan="9">CTR改善候補のページはありません。データ未取得の場合は「最新データを取得」を実行してください。</td></tr>
			</tbody>
		<?php else : ?>
			<?php foreach ( $pages as $row ) : ?>
				<?php
				$benchmark_ctr = SEOCP_GSC_API::get_benchmark_ctr( $row['position'] );
				$post_title    = $row['post_id'] ? get_the_title( $row['post_id'] ) : '';
				$current_meta  = $row['post_id'] ? $meta_manager->get_current( $row['post_id'] ) : null;
				?>
				<tbody class="seocp-suggestion-group" data-post-id="<?php echo esc_attr( $row['post_id'] ); ?>">
					<tr>
						<td style="text-align:right; color:#C9962C; font-weight:bold;"><?php echo esc_html( $row['priority_score'] ); ?>pt</td>
						<td>
							<?php if ( $post_title ) : ?>
								<strong class="seocp-current-title seocp-post-title-<?php echo esc_attr( $row['post_id'] ); ?>"><?php echo esc_html( $post_title ); ?></strong><br>
							<?php endif; ?>
							<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener" class="seocp-permalink" title="<?php echo esc_attr( $row['url'] ); ?>"><?php echo esc_html( $row['url'] ); ?></a>
							<?php if ( $row['post_id'] ) : ?>
								<br><a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>">編集画面へ</a>
							<?php endif; ?>
							<?php if ( $current_meta ) : ?>
								<div class="seocp-current-meta-desc">
									<span class="seocp-meta-desc-label">現在の説明文(<?php echo esc_html( $current_meta['source_label'] ); ?>):</span>
									<?php if ( '' !== $current_meta['text'] ) : ?>
										<span class="seocp-current-meta-desc-text seocp-current-meta-desc-<?php echo esc_attr( $row['post_id'] ); ?>"><?php echo esc_html( $current_meta['text'] ); ?></span>
										<span class="seocp-meta-desc-length">(<?php echo esc_html( SEOCP_Meta_Description::count_length( $current_meta['text'] ) ); ?>文字)</span>
									<?php else : ?>
										<span class="seocp-meta-desc-empty">未設定</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</td>
						<td style="text-align:right;"><?php echo esc_html( $row['clicks'] ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( $row['impressions'] ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( round( $row['ctr'] * 100, 2 ) ); ?>%</td>
						<td style="text-align:right;"><?php echo esc_html( round( $benchmark_ctr * 100, 2 ) ); ?>%</td>
						<td style="text-align:right;"><?php echo esc_html( round( $row['position'], 1 ) ); ?></td>
						<td>
							<?php if ( $row['post_id'] ) : ?>
								<button type="button" class="button button-small seocp-gen-title">タイトル案</button>
								<button type="button" class="button button-small seocp-gen-meta-desc">説明文案</button>
							<?php else : ?>
								-
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $row['post_id'] ) : ?>
						<tr class="seocp-suggestion-result-row">
							<td colspan="8" class="seocp-suggestion-result">
								<div class="seocp-result-title"></div>
								<div class="seocp-result-meta-desc"></div>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			<?php endforeach; ?>
		<?php endif; ?>
	</table>

	<h2 style="margin-top:32px;">コンテンツ劣化アラート<?php if ( ! empty( $decay_pages ) ) : ?> <span class="seocp-decay-count">(<?php echo count( $decay_pages ); ?>件)</span><?php endif; ?></h2>
	<p class="description">
		前回のデータ取得時と比べてクリック数が大きく減少しているページを表示しています(前回クリック数が少なすぎるページはノイズのため除外)。
		順位や検索意図とのズレが生じている可能性があるため、内容の見直し(リライト)を検討してください。
		「AI提案で確認」から、その記事のタイトル・FAQ・内部リンクの改善案を生成できます。
	</p>
	<?php if ( ! $connected || ! $site_url ) : ?>
		<p class="description">Search Consoleに接続すると表示されます。</p>
	<?php elseif ( count( $decay_data_dates ) < 2 ) : ?>
		<p class="description">データ取得が1回のみのため、まだ比較できません。日を改めてもう一度「最新データを取得」を実行すると、前回との比較が可能になります。</p>
	<?php elseif ( empty( $decay_pages ) ) : ?>
		<p class="description">現時点で劣化が疑われるページはありません。</p>
	<?php else : ?>
		<table class="widefat striped" id="seocp-decay-table">
			<thead>
				<tr>
					<th style="width:90px; text-align:right;">減少率</th>
					<th>記事タイトル / URL</th>
					<th style="width:150px; text-align:right;">クリック数(前回→今回)</th>
					<th style="width:150px; text-align:right;">表示回数(前回→今回)</th>
					<th style="width:150px; text-align:right;">平均順位(前回→今回)</th>
					<th style="width:150px;">操作</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $decay_pages as $row ) : ?>
					<?php $post_title = $row['post_id'] ? get_the_title( $row['post_id'] ) : ''; ?>
					<tr>
						<td style="text-align:right; color:#b32d2e; font-weight:bold;">-<?php echo esc_html( $row['drop_pct'] ); ?>%</td>
						<td>
							<?php if ( $post_title ) : ?>
								<strong><?php echo esc_html( $post_title ); ?></strong><br>
							<?php endif; ?>
							<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener" class="seocp-permalink" title="<?php echo esc_attr( $row['url'] ); ?>"><?php echo esc_html( $row['url'] ); ?></a>
						</td>
						<td style="text-align:right;"><?php echo esc_html( $row['prev_clicks'] ); ?> → <?php echo esc_html( $row['latest_clicks'] ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( $row['prev_impressions'] ); ?> → <?php echo esc_html( $row['latest_impressions'] ); ?></td>
						<td style="text-align:right;"><?php echo esc_html( round( $row['prev_position'], 1 ) ); ?> → <?php echo esc_html( round( $row['latest_position'], 1 ) ); ?></td>
						<td>
							<?php if ( $row['post_id'] ) : ?>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-suggestions&focus_post_id=' . $row['post_id'] ) ); ?>">AI提案で確認</a>
							<?php else : ?>
								-
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
