# SEO Copilot

WordPressのSEO改善を、Google Search ConsoleとAIでサポートする無料プラグインです。

> **SEOの「次に何をすればいい？」を、AIと一緒に。**

## v1.0.0の主な機能

- Google Search Console OAuth連携
- Search Analyticsデータの取得
- クリック数・表示回数・CTR・平均掲載順位の確認
- 4〜20位付近の改善候補ページの確認
- AIによるタイトル改善案
- AIによるメタディスクリプション改善案
- AIによるFAQ提案
- AIによる内部リンク提案
- AI改善下書きの作成
- 変更内容の確認・差分表示
- LLMO / AEOチェック
- LLMO / AEO改善提案
- 長文記事の分割型改善処理
- インデックス状況の確認（URL Inspection）
- 更新履歴・検索パフォーマンスの確認
- SEO改善後の効果測定
- Gemini / Groq対応

## v1.0.0で含まれない機能

以下は無料版v1.0では提供しません。

- GA4連携
- 競合サイト分析
- 被リンク分析・獲得支援
- AI画像生成
- 通知機能
- 自動処理キュー
- Indexing APIによる登録リクエスト
- 自動インデックス登録

## 必要環境

- WordPress 6.0以上を目安
- PHP 7.4以上を目安
- HTTPS推奨
- Google Search Consoleを利用できるサイト
- AI機能を使う場合はGeminiまたはGroqのAPIキー

> 動作要件は今後の検証結果により変更される場合があります。

## インストール

1. GitHubの **Releases** から `seo-copilot-v1.0.0.zip` をダウンロードします。
2. WordPress管理画面の **プラグイン → 新規プラグインを追加 → プラグインのアップロード** を開きます。
3. ZIPを選択してインストールします。
4. **プラグインを有効化**します。
5. **SEO Copilot → 設定** を開き、AIとSearch Consoleを設定します。

## Google Search Consoleの設定

1. Google Cloud ConsoleでOAuthクライアントを作成します。
2. 種類はWebアプリケーションを選択します。
3. SEO Copilotの設定画面に表示されるリダイレクトURIを、Google Cloud Consoleの「承認済みのリダイレクトURI」に登録します。
4. クライアントIDとクライアントシークレットをSEO Copilotへ保存します。
5. 「Googleに接続」を押します。
6. Search Consoleの対象プロパティを選択します。

v1.0.0ではSearch Consoleの読み取り権限のみを使用します。

## AIの設定

### Gemini

Google AI StudioでAPIキーを作成し、設定画面に入力します。

### Groq

Groq ConsoleでAPIキーを作成し、設定画面に入力します。

APIキーは設定画面から保存し、可能な環境では暗号化してWordPressデータベースへ保存します。

## 基本的な使い方

### 1. Search Consoleデータを取得

Search Consoleを接続し、検索パフォーマンスデータを取得します。

### 2. 改善候補を探す

「4〜20位付近」のページを確認し、改善する記事を選びます。

### 3. AI提案を確認

タイトル、メタディスクリプション、FAQ、内部リンクなどの提案を生成します。

### 4. 下書き・差分を確認

AIの提案を確認し、必要に応じて下書きを作成します。公開済み記事をAIに無条件で上書きすることを前提としていません。

### 5. LLMO / AEOを確認

AI検索を意識したコンテンツチェックを行い、必要な改善を検討します。

### 6. 効果を確認

改善前後のSearch Consoleデータを確認し、順位・クリック・CTRなどの変化を追います。

## 注意事項

- SEO Copilotは検索順位やアクセス数の向上を保証するものではありません。
- AIが生成した内容には誤りが含まれる可能性があります。公開前に必ず内容を確認してください。
- APIサービスの料金、無料枠、モデル、利用規約は各サービス側で変更される場合があります。
- Google Search ConsoleやGoogle Cloudの設定・権限は利用者自身で管理してください。
- 本番サイトで利用する前に、WordPressとデータベースのバックアップを推奨します。
- 外部AIサービスへ送信される情報については、各サービスの最新の利用規約・プライバシーポリシーを確認してください。
- v1.0.0ではGoogle Indexing APIを使用した登録リクエストは行いません。

## プライバシー・外部サービス

SEO Copilotは、ユーザーが設定したGoogle Search Console、Gemini、Groqなどの外部サービスと通信します。APIキーやOAuth認証情報の取り扱いについては、各サービスの最新規約とプラグインの実装を確認してください。

SEO Copilotの開発者がユーザーのAPIキーを共有利用する方式ではなく、原則として各WordPressサイトの管理者が自身の認証情報/APIキーを設定して利用する設計です。

## ライセンス

SEO Copilotは **GPL-2.0-or-later** で公開します。

詳細は `LICENSE` を確認してください。

## 開発について

SEO Copilotは、WordPressサイトのSEO改善をより分かりやすく、実践しやすくすることを目的に開発しています。

フィードバックや不具合報告はGitHub Issuesへお願いします。

## Changelog

### 1.0.0

- 無料版として初回公開
- Search Console連携
- SEO改善候補分析
- AI提案
- LLMO/AEOチェック
- インデックス状況確認
- 効果測定
- v1.0無料版向けにGA4、競合分析、被リンク、画像生成、通知、自動キュー、Indexing API登録リクエストを除外
