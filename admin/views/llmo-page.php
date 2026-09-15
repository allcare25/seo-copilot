<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$checker = new SEOCP_LLMO_Checker();

$posts = get_posts( array(
	'post_type'      => array( 'post', 'page' ),
	'post_status'    => 'publish',
	'posts_per_page' => 50,
	'orderby'        => 'modified',
	'order'          => 'DESC',
) );

$all_published_count = wp_count_posts( 'post' )->publish + wp_count_posts( 'page' )->publish;
?>
<div class="wrap seocp-wrap">
	<h1>AI検索(LLMO/AEO)チェック</h1>
	<p>ChatGPT検索・Perplexity・Google AI Overviewsなど、AI検索エンジンに引用されやすい記事構造になっているかをチェックします。</p>

	<h2>サイト全体チェック</h2>
	<div id="seocp-site-wide-result">
		<button type="button" class="button" id="seocp-check-site-wide">robots.txt / llms.txt をチェック</button>
		<div id="seocp-site-wide-output" style="margin-top:10px;"></div>
	</div>

	<h2 style="margin-top:30px;">サイト全記事の一括スコアリング</h2>
	<p class="description">下の表に並んでいる記事(直近更新50件)をまとめてチェックし、スコアが低い記事から確認できるようにします。1件ずつ順番に実行するため、記事数が多いと時間がかかります。</p>
	<p>
		<button type="button" class="button button-primary" id="seocp-bulk-check-all">表示中の記事を一括チェック</button>
		<button type="button" class="button" id="seocp-refresh-low-score">スコアが低い記事を更新</button>
	</p>
	<div id="seocp-bulk-check-progress" style="display:none; margin:10px 0;">
		<div class="seocp-progress-bar"><div class="seocp-progress-bar-fill" id="seocp-bulk-progress-fill"></div></div>
		<span id="seocp-bulk-progress-text"></span>
	</div>
	<div id="seocp-low-score-panel" class="seocp-card" style="max-width:700px;">
		<h3 style="margin-top:0;">スコアが低い記事 TOP10</h3>
		<div id="seocp-low-score-list"><em>読み込み中...</em></div>
	</div>

	<h2 style="margin-top:30px;">記事単位チェック(直近更新50件 / 全<?php echo (int) $all_published_count; ?>件中)</h2>
	<table class="widefat seocp-suggestion-table">
		<thead>
			<tr>
				<th style="width:28%;">タイトル</th>
				<th style="width:64px;">種別</th>
				<th style="width:100px;">最終チェック</th>
				<th style="width:64px;">スコア</th>
				<th style="width:210px;">操作</th>
			</tr>
		</thead>
		<?php $i = 0; foreach ( $posts as $p ) : $i++;
			$latest = $checker->get_latest_result( $p->ID );
			?>
			<tbody class="seocp-suggestion-group<?php echo ( $i % 2 === 0 ) ? ' seocp-alt' : ''; ?>" data-post-id="<?php echo esc_attr( $p->ID ); ?>">
				<tr>
					<td>
						<strong><?php echo esc_html( get_the_title( $p ) ); ?></strong><br>
						<a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" rel="noopener" class="seocp-permalink" title="<?php echo esc_attr( get_permalink( $p ) ); ?>"><?php echo esc_html( get_permalink( $p ) ); ?></a>
					</td>
					<td><?php echo esc_html( $p->post_type === 'page' ? '固定ページ' : '投稿' ); ?></td>
					<td class="seocp-checked-at-cell">
						<?php echo $latest ? esc_html( mysql2date( 'Y-m-d H:i', $latest['checked_at'] ) ) : '-'; ?>
					</td>
					<td class="seocp-score-cell">
						<?php echo $latest ? esc_html( $latest['score'] ) . ' 点' : '未チェック'; ?>
					</td>
					<td class="seocp-action-cell">
						<button type="button" class="button seocp-run-check">チェック実行</button>
						<button type="button" class="button button-primary seocp-generate-suggestion">AI改善提案を生成</button>
					</td>
				</tr>
				<tr class="seocp-suggestion-result-row">
					<td colspan="5">
						<?php if ( ! empty( $latest['ai_suggestion'] ) ) :
							$suggestion_items = $checker->parse_suggestion_items( $latest['ai_suggestion'] );
							?>
							<div class="seocp-detail-panel">
								<strong>AI改善提案(反映する項目にチェック):</strong>
								<ul class="seocp-suggestion-items" style="margin-top:6px;">
									<?php foreach ( $suggestion_items as $idx => $item ) : ?>
										<li>
											<label>
												<input type="checkbox" class="seocp-suggestion-item-checkbox" value="<?php echo esc_attr( $idx ); ?>" checked>
												<?php echo esc_html( $item ); ?>
											</label>
										</li>
									<?php endforeach; ?>
								</ul>
								<div class="seocp-apply-suggestion-row" style="margin-top:10px;">
									<button type="button" class="button button-primary seocp-preview-llmo-suggestion">選択した項目を記事に反映（差分を確認）</button>
								</div>
								<div class="seocp-diff-preview-panel" style="display:none; margin-top:10px;"></div>
								<p style="color:#666;font-size:12px;margin-top:6px;">※ チェックを入れた項目だけを反映します。一部だけ選んで反映すると、AIの処理量が減りトークン上限エラーになりにくくなります。まとめて何度かに分けて反映することもできます。<br>※ 前回生成した提案です。反映に失敗した場合も、この画面から再生成せずやり直せます。反映前に必ず差分プレビューが表示され、確認してから確定できます。</p>
							</div>
						<?php else : ?>
							<div class="seocp-detail-panel" style="display:none;"></div>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		<?php endforeach; ?>
	</table>
</div>
