<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings  = get_option( 'seocp_settings', array() );
$gsc       = new SEOCP_GSC_API();
$connected = $gsc->is_connected();

if ( isset( $_GET['seocp_gsc_connected'] ) ) {
	echo '<div class="notice notice-success is-dismissible"><p>Google Search Consoleへの接続が完了しました。下記でプロパティを選択してください。</p></div>';
}
if ( isset( $_GET['seocp_gsc_error'] ) ) {
	echo '<div class="notice notice-error is-dismissible"><p>接続エラー: ' . esc_html( wp_unslash( $_GET['seocp_gsc_error'] ) ) . '</p></div>';
}

$sensitive_placeholder = function ( $key ) {
	return SEOCP_Settings::is_set( $key ) ? '設定済み(変更する場合のみ入力)' : '未設定';
};
?>
<div class="wrap seocp-wrap">
	<h1>SEO Copilot 設定</h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'seocp_settings_group' ); ?>

		<h2>AI設定</h2>
		<p class="description">GeminiまたはGroqを選択して利用します。両方のAPIキーを登録しておくと、メイン側が利用できない場合にもう一方へフォールバックできます。APIキーは可能な環境では暗号化して保存します。</p>
		<table class="form-table">
			<tr>
				<th>メインで使うプロバイダー</th>
				<td>
					<label><input type="radio" name="seocp_settings[ai_provider]" value="gemini" <?php checked( ( $settings['ai_provider'] ?? 'gemini' ), 'gemini' ); ?>> Gemini</label>
					&nbsp;&nbsp;
					<label><input type="radio" name="seocp_settings[ai_provider]" value="groq" <?php checked( ( $settings['ai_provider'] ?? 'gemini' ), 'groq' ); ?>> Groq</label>
				</td>
			</tr>
		</table>

		<h3>Google Gemini API</h3>
		<p><a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>でAPIキーを取得して入力してください。</p>
		<table class="form-table">
			<tr><th><label for="gemini_api_key">Gemini APIキー</label></th><td><input type="password" id="gemini_api_key" name="seocp_settings[gemini_api_key]" value="" placeholder="<?php echo esc_attr( $sensitive_placeholder( 'gemini_api_key' ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
			<tr><th><label for="gemini_model">使用モデル</label></th><td><input type="text" id="gemini_model" name="seocp_settings[gemini_model]" value="<?php echo esc_attr( $settings['gemini_model'] ?? 'gemini-flash-latest' ); ?>" class="regular-text"><p class="description">既定値: <code>gemini-flash-latest</code>。利用可能なモデルはGoogleの公式ドキュメントで確認してください。</p></td></tr>
		</table>

		<h3>Groq API</h3>
		<p><a href="https://console.groq.com/keys" target="_blank" rel="noopener">Groq Console</a>でAPIキーを取得して入力してください。</p>
		<table class="form-table">
			<tr><th><label for="groq_api_key">Groq APIキー</label></th><td><input type="password" id="groq_api_key" name="seocp_settings[groq_api_key]" value="" placeholder="<?php echo esc_attr( $sensitive_placeholder( 'groq_api_key' ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
			<tr><th><label for="groq_model">使用モデル</label></th><td><input type="text" id="groq_model" name="seocp_settings[groq_model]" value="<?php echo esc_attr( $settings['groq_model'] ?? 'openai/gpt-oss-20b' ); ?>" class="regular-text"><p class="description">既定値: <code>openai/gpt-oss-20b</code>。利用可能なモデルはGroqの公式ドキュメントで確認してください。</p></td></tr>
		</table>

		<h2>Google Search Console連携</h2>
		<p class="description">Google Cloud ConsoleでOAuthクライアント(種類: ウェブアプリケーション)を作成し、「承認済みのリダイレクトURI」に以下のURLを登録してください。</p>
		<p><code><?php echo esc_html( $gsc->get_redirect_uri() ); ?></code></p>
		<table class="form-table">
			<tr><th><label for="gsc_client_id">クライアントID</label></th><td><input type="text" id="gsc_client_id" name="seocp_settings[gsc_client_id]" value="<?php echo esc_attr( $settings['gsc_client_id'] ?? '' ); ?>" class="regular-text"></td></tr>
			<tr><th><label for="gsc_client_secret">クライアントシークレット</label></th><td><input type="password" id="gsc_client_secret" name="seocp_settings[gsc_client_secret]" value="" placeholder="<?php echo esc_attr( $sensitive_placeholder( 'gsc_client_secret' ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
		</table>

		<?php submit_button( '設定を保存' ); ?>
	</form>

	<div class="seocp-card" style="max-width:700px; margin-top:10px;">
		<h2>Search Console接続状態</h2>
		<p><?php echo $connected ? '<span class="seocp-badge-pass">● 接続済み</span>' : '<span class="seocp-badge-fail">● 未接続</span>'; ?></p>
		<?php if ( $connected ) : ?>
			<p class="description">v1.0ではSearch Consoleの読み取り権限のみを使用します。</p>
			<button type="button" class="button" id="seocp-gsc-disconnect">接続を解除</button>
		<?php else : ?>
			<button type="button" class="button button-primary" id="seocp-gsc-connect" <?php disabled( empty( $settings['gsc_client_id'] ) ); ?>>Googleに接続</button>
			<?php if ( empty( $settings['gsc_client_id'] ) ) : ?><p class="description">先にクライアントID/シークレットを保存してください。</p><?php endif; ?>
		<?php endif; ?>

		<div id="seocp-gsc-site-picker" style="margin-top:14px; <?php echo $connected ? '' : 'display:none;'; ?>">
			<h3>対象プロパティ</h3>
			<p>
				<select id="seocp-gsc-site-select" style="min-width:320px;"></select>
				<button type="button" class="button" id="seocp-gsc-load-sites">プロパティ一覧を取得</button>
				<button type="button" class="button button-primary" id="seocp-gsc-save-site">このプロパティを使用</button>
			</p>
			<p class="description">現在の選択: <strong><?php echo esc_html( $settings['gsc_site_url'] ?? '未選択' ); ?></strong></p>
		</div>
	</div>
</div>
