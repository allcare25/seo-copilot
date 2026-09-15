<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gsc          = new SEOCP_GSC_API();
$tracked_ids  = $gsc->get_tracked_post_ids();
?>
<div class="wrap seocp-wrap">
	<h1>更新履歴・順位変化</h1>
	<p>Search Consoleデータを取得するたびに履歴が蓄積されます。記事を更新した日と順位の変化を重ねて確認できます。</p>

	<?php if ( empty( $tracked_ids ) ) : ?>
		<div class="notice notice-warning">
			<p>まだ履歴データがありません。「Search Consoleデータ」画面で一度データを取得してください。複数回(できれば数日〜数週間おきに)取得するほど、ここでの可視化が有効になります。</p>
		</div>
	<?php else : ?>
		<p>
			<label for="seocp-history-post-select">対象ページ:</label>
			<select id="seocp-history-post-select" style="min-width:400px;">
				<option value="">選択してください</option>
				<?php foreach ( $tracked_ids as $pid ) :
					$title = get_the_title( $pid );
					if ( ! $title ) continue;
					?>
					<option value="<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button button-primary" id="seocp-history-load">表示</button>
		</p>

		<div id="seocp-history-chart-container" style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:16px; margin-top:10px;">
			<p><em>ページを選んで「表示」を押してください。</em></p>
		</div>

		<div id="seocp-history-table-container" style="margin-top:16px;"></div>

		<div class="seocp-card" id="seocp-effect-tracking-card" style="max-width:700px; margin-top:20px;">
			<h2 style="margin-top:0;">効果測定(タイトル変更・下書き反映などの前後比較)</h2>
			<p class="description">
				「今の時点」を基準値として記録しておき、後日その基準値と比べてクリック数・表示回数・CTR・平均順位がどう変化したかを確認できます。
				タイトルを反映した直後や、下書きを公開に差し替えた直後に「基準値を記録」を押しておくのがおすすめです。
			</p>
			<p>
				<input type="text" id="seocp-effect-note" placeholder="メモ(例: タイトルをAI提案に変更)" style="min-width:320px;">
				<button type="button" class="button button-primary" id="seocp-effect-start">この時点を基準値として記録</button>
			</p>
			<div id="seocp-effect-records"></div>
		</div>
	<?php endif; ?>
</div>
