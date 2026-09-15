=== SEO Copilot ===
Contributors: athenyan25
Tags: seo, wordpress seo, google search console, ai, llmo, aeo
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Google Search ConsoleとAIを活用して、WordPressサイトのSEO改善を支援する無料プラグインです。

== Description ==

SEO Copilotは、Google Search Consoleの検索パフォーマンスデータをもとに、SEO改善の優先候補を見つけ、AIによる改善案を確認し、改善後の効果を追跡するためのWordPressプラグインです。

主なコンセプトは「SEOの『次に何をすればいい？』を、AIと一緒に。」です。

== Features ==

* Google Search Console OAuth連携
* Search Analyticsデータ取得
* クリック数、表示回数、CTR、平均掲載順位の確認
* 4〜20位付近の改善候補ページ確認
* AIタイトル提案
* AIメタディスクリプション提案
* AI FAQ提案
* AI内部リンク提案
* AI改善下書き作成
* 差分・プレビュー確認
* LLMO / AEOチェック
* LLMO / AEO改善提案
* 長文記事の分割型改善処理
* URL Inspectionによるインデックス状況確認
* 更新履歴・効果測定
* Gemini / Groq対応

== Not included in v1.0.0 ==

The following features are intentionally excluded from the free v1.0.0 release:

* GA4 integration
* Competitor analysis
* Backlink monitoring/acquisition support
* AI image generation
* Notifications
* Background processing queue
* Google Indexing API requests
* Automatic indexing requests

== Installation ==

1. Download the latest release ZIP from GitHub Releases.
2. In WordPress, go to Plugins > Add New Plugin > Upload Plugin.
3. Select the SEO Copilot ZIP file.
4. Install and activate the plugin.
5. Open SEO Copilot > Settings.
6. Configure Gemini or Groq if you want to use AI features.
7. Configure Google Search Console OAuth and select the target property.

== Google Search Console Setup ==

Create a Google OAuth client for a web application in Google Cloud Console.

Add the redirect URI displayed on the SEO Copilot settings screen to the OAuth client's authorized redirect URIs.

Version 1.0.0 requests Search Console read-only access only.

== AI Setup ==

Gemini and Groq require API keys supplied by the site administrator. API availability, quotas, model names, pricing, and terms are controlled by the respective service providers and may change.

== Frequently Asked Questions ==

= Does SEO Copilot guarantee higher rankings? =

No. SEO Copilot provides data and suggestions to support SEO work. Search rankings and traffic are not guaranteed.

= Does it automatically rewrite published articles? =

The recommended workflow is to review AI suggestions and create or review changes before publishing.

= Does v1.0 automatically request indexing? =

No. v1.0 only checks indexing status using URL Inspection.

= Can I use my own API keys? =

Yes. AI API keys are configured by the site administrator.

== Privacy and External Services ==

SEO Copilot communicates with Google Search Console and, when AI features are used, the configured AI provider. Review the latest privacy policies and terms of the external services before use.

== Changelog ==

= 1.0.0 =

* Initial free release.
* Search Console integration.
* SEO improvement opportunity analysis.
* AI SEO suggestions.
* LLMO/AEO checks.
* Index status checking.
* Effect measurement.
