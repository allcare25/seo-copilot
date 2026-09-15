<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$engine = new SEOCP_Suggestion_Engine();

$posts = get_posts( array(
	'post_type'      => array( 'post', 'page' ),
	'post_status'    => 'publish',
	'posts_per_page' => 50,
	'orderby'        => 'modified',
	'order'          => 'DESC',
) );

// CTR改善ページ等、他画面からリンクで指定された記事が「直近更新50件」に
// 含まれない場合(更新日が古い記事など)でも、必ず一覧の先頭に表示されるようにする
$focus_post_id = isset( $_GET['focus_post_id'] ) ? absint( $_GET['focus_post_id'] ) : 0;
if ( $focus_post_id && ! wp_list_filter( $posts, array( 'ID' => $focus_post_id ) ) ) {
	$focus_post = get_post( $focus_post_id );
	if ( $focus_post && 'publish' === $focus_post->post_status ) {
		array_unshift( $posts, $focus_post );
	}
}
?>
<div class="wrap seocp-wrap">
	<h1>AI提案(タイトル改善・FAQ・内部リンク)</h1>
	<p>記事ごとにAIがタイトル改善案・FAQ追加案・内部リンク候補を提案します。Search Consoleデータが取得済みの記事は、
		クリック数やCTRも考慮したタイトル案になります。各案はチェックを付けて「反映」を押すとその場で記事に追記・上書きされ、
		「プレビュー/編集」からは反映後の記事をその場で確認・修正して保存できます。</p>

	<table class="widefat seocp-suggestion-table">
		<thead>
			<tr>
				<th style="width:40%;">タイトル</th>
				<th>操作</th>
			</tr>
		</thead>
		<?php $i = 0; foreach ( $posts as $p ) : $i++; ?>
			<tbody id="seocp-post-<?php echo esc_attr( $p->ID ); ?>" class="seocp-suggestion-group<?php echo ( $i % 2 === 0 ) ? ' seocp-alt' : ''; ?>" data-post-id="<?php echo esc_attr( $p->ID ); ?>">
				<tr>
					<td>
						<strong><?php echo esc_html( get_the_title( $p ) ); ?></strong><br>
						<a href="<?php echo esc_url( get_permalink( $p ) ); ?>" target="_blank" rel="noopener" class="seocp-permalink" title="<?php echo esc_attr( get_permalink( $p ) ); ?>"><?php echo esc_html( get_permalink( $p ) ); ?></a>
					</td>
					<td>
						<button type="button" class="button seocp-gen-title">タイトル案</button>
						<button type="button" class="button seocp-gen-faq">FAQ案</button>
						<button type="button" class="button seocp-gen-links">内部リンク候補</button>
						<span style="display:inline-block; margin:0 10px;">|</span>
						<label style="display:inline-block; font-size:12px; margin-right:8px;"><input type="checkbox" class="seocp-opt-title" checked> タイトルを反映</label>
						<label style="display:inline-block; font-size:12px; margin-right:8px;"><input type="checkbox" class="seocp-opt-faq" checked> FAQを追加</label>
						<label style="display:inline-block; font-size:12px; margin-right:8px;"><input type="checkbox" class="seocp-opt-links" checked> 内部リンクを追加</label>
						<button type="button" class="button button-primary seocp-gen-draft">「改善」下書きを生成</button>
						<span style="display:inline-block; margin:0 10px;">|</span>
						<button type="button" class="button seocp-toggle-preview">プレビュー/編集</button>
					</td>
				</tr>
				<tr class="seocp-suggestion-result-row">
					<td colspan="2" class="seocp-suggestion-result">
						<div class="seocp-result-draft"></div>
						<div class="seocp-result-title"></div>
						<div class="seocp-result-faq"></div>
						<div class="seocp-result-links"></div>
						<em class="seocp-result-empty">ボタンを押すと結果がここに表示されます。</em>
					</td>
				</tr>
				<tr class="seocp-preview-row" style="display:none;">
					<td colspan="2">
						<div class="seocp-preview-panel">
							<p class="seocp-preview-msg"></p>
							<p>
								<label><strong>タイトル</strong></label><br>
								<input type="text" class="seocp-preview-title regular-text" style="width:100%;">
							</p>
							<p><strong>プレビュー(現在この記事に反映済みの内容)</strong></p>
							<div class="seocp-preview-rendered" style="border:1px solid #ccd0d4; padding:16px; background:#fff; max-height:420px; overflow:auto;"></div>
							<p style="margin-top:12px;"><label><strong>本文(編集可・HTML)</strong></label></p>
							<textarea class="seocp-preview-editor" rows="14" style="width:100%; font-family:monospace; font-size:12px;"></textarea>
							<p>
								<button type="button" class="button button-primary seocp-save-preview">この内容を記事に保存</button>
								<a class="button seocp-open-editor" href="#" target="_blank" rel="noopener">WordPressエディタで開く</a>
								<button type="button" class="button seocp-close-preview">閉じる</button>
								<span class="seocp-preview-save-status" style="margin-left:8px;"></span>
							</p>
						</div>
					</td>
				</tr>
			</tbody>
		<?php endforeach; ?>
	</table>
</div>
