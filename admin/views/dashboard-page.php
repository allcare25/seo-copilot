<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gemini_configured = (bool) SEOCP_Settings::get( 'gemini_api_key' );
$gsc_connected     = ( new SEOCP_GSC_API() )->is_connected();
$gsc_site          = SEOCP_Settings::get( 'gsc_site_url' );
?>
<div class="wrap seocp-wrap">
	<h1>SEO運用支援プラグイン</h1>
	<p>Search Console連携・改善優先ページ判定・AI提案・AI検索(LLMO/AEO)チェックを一元管理します。</p>

	<div class="seocp-status-cards">
		<div class="seocp-card">
			<h2>AI検索(LLMO/AEO)チェック</h2>
			<p>投稿・固定ページがAI検索エンジンに引用されやすいかをチェックします。</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-llmo' ) ); ?>" class="button button-primary">チェックを開始</a>
		</div>

		<div class="seocp-card">
			<h2>AI提案(タイトル・FAQ・内部リンク)</h2>
			<p>ページごとにAIがタイトル改善案・FAQ追加案・内部リンク候補を提示します。</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-suggestions' ) ); ?>" class="button button-primary">提案を見る</a>
		</div>

		<div class="seocp-card">
			<h2>Search Consoleデータ・改善優先ページ</h2>
			<p>順位・クリック数・CTRを取得し、改善優先度スコアでページをランキングします。</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-gsc' ) ); ?>" class="button button-primary">データを見る</a>
		</div>

		<div class="seocp-card">
			<h2>「改善」ボタンで下書き自動生成</h2>
			<p>AI提案(タイトル・FAQ・内部リンク)を反映した下書きをワンクリックで作成します。元記事は変更されません。</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-suggestions' ) ); ?>" class="button button-primary">下書きを作成</a>
		</div>


		<div class="seocp-card">
			<h2>インデックス登録対象ページ</h2>
			<p>URL Inspection APIで各ページのGoogleインデックス状況を確認します。</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-index' ) ); ?>" class="button button-primary">確認する</a>
		</div>

		<div class="seocp-card">
			<h2>更新履歴・順位変化・効果測定</h2>
			<p>記事更新日と順位・クリック数の推移を重ねて可視化。タイトル変更などの前後比較もできます。</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-history' ) ); ?>" class="button button-primary">見る</a>
		</div>
	</div>

	<h2>セットアップ状況</h2>
	<table class="widefat striped" style="max-width:600px;">
		<tbody>
			<tr>
				<td>Gemini APIキー</td>
				<td><?php echo $gemini_configured ? '<span style="color:green;">設定済み</span>' : '<span style="color:#b32d2e;">未設定</span>'; ?></td>
			</tr>
			<tr>
				<td>Google Search Console連携</td>
				<td>
					<?php if ( $gsc_connected && $gsc_site ) : ?>
						<span style="color:green;">接続済み(<?php echo esc_html( $gsc_site ); ?>)</span>
					<?php elseif ( $gsc_connected ) : ?>
						<span style="color:#b32d2e;">接続済み・プロパティ未選択</span>
					<?php else : ?>
						<span style="color:#b32d2e;">未接続</span>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=seocp-settings' ) ); ?>">設定画面へ</a></p>
</div>
