<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 依存ライブラリなしの簡易HTML差分表示ユーティリティ。
 * 「差分プレビュー」機能(下書き生成・LLMO提案の本文上書き)で、
 * 変更前後のテキストのどこが変わったかを一目で確認できるようにする。
 *
 * 単語単位のLCS(最長共通部分列)ベースの差分アルゴリズムを実装しており、
 * 挿入箇所は<ins>、削除箇所は<del>で囲んだHTML断片を返す。
 * 入力が大きすぎる場合(比較コストが高すぎる場合)は、段落単位の粗い差分にフォールバックする。
 */
class SEOCP_Diff {

	// v0.7.4: LCSベースの差分は (旧トークン数+1)×(新トークン数+1) の2次元配列を
	// メモリ上に確保するため、見た目の閾値(400万)よりもずっと少ないトークン数でも
	// PHPの配列オーバーヘッドにより実際のメモリ使用量が共用サーバーのmemory_limit
	// (128MB〜256MB程度)を超え、Fatal error(500エラー)になることがある。
	// そのため閾値をかなり保守的な値まで下げ、通常の記事量では安全側の
	// 段落単位の簡易差分(paragraph_diff)にフォールバックするようにする。
	const MAX_TOKENS_FOR_WORD_DIFF = 1200;
	const MAX_TOKEN_PRODUCT_FOR_WORD_DIFF = 600000;

	/**
	 * 2つのテキスト(プレーンテキスト、またはHTMLタグ除去後を推奨)を比較し、
	 * 差分をハイライトしたHTML文字列を返す。
	 *
	 * @param string $old_text
	 * @param string $new_text
	 * @param bool   $strip_tags true の場合、比較前にHTMLタグを除去してテキストのみ比較する
	 * @return string
	 */
	public static function html_diff( $old_text, $new_text, $strip_tags = true ) {
		if ( $strip_tags ) {
			$old_text = wp_strip_all_tags( (string) $old_text );
			$new_text = wp_strip_all_tags( (string) $new_text );
		}

		if ( trim( $old_text ) === trim( $new_text ) ) {
			return '<p class="seocp-diff-nochange">(変更なし)</p>';
		}

		$old_tokens = self::tokenize( $old_text );
		$new_tokens = self::tokenize( $new_text );

		if ( count( $old_tokens ) * count( $new_tokens ) > self::MAX_TOKEN_PRODUCT_FOR_WORD_DIFF
			|| count( $old_tokens ) > self::MAX_TOKENS_FOR_WORD_DIFF
			|| count( $new_tokens ) > self::MAX_TOKENS_FOR_WORD_DIFF ) {
			return self::paragraph_diff( $old_text, $new_text );
		}

		$ops = self::diff_tokens( $old_tokens, $new_tokens );

		$html = '<div class="seocp-diff">';
		foreach ( $ops as $op ) {
			$text = esc_html( implode( '', $op['tokens'] ) );
			if ( 'equal' === $op['type'] ) {
				$html .= $text;
			} elseif ( 'insert' === $op['type'] ) {
				$html .= '<ins>' . $text . '</ins>';
			} elseif ( 'delete' === $op['type'] ) {
				$html .= '<del>' . $text . '</del>';
			}
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * 単語+区切り文字単位でトークン化(日本語には厳密な形態素解析は行わず、
	 * 文字種の切れ目・句読点・空白でおおまかに区切る簡易版)
	 */
	private static function tokenize( $text ) {
		$text = (string) $text;
		// 文字コードのブロック範囲を直接指定して分割する。
		// \p{Hiragana}等のスクリプトプロパティはScript_Extensionsの都合で句読点(。など)まで
		// マッチしてしまうことがあるため、あえてUnicodeブロック範囲を明示して除外する。
		preg_match_all( '/[\x{4E00}-\x{9FFF}]+|[\x{3040}-\x{309F}]+|[\x{30A0}-\x{30FF}]+|[A-Za-z0-9]+|[[:punct:]]|\s+|./u', $text, $matches );
		return $matches[0];
	}

	/**
	 * トークン列同士のLCSベース差分を計算し、equal/insert/deleteのまとまりを返す
	 */
	private static function diff_tokens( $a, $b ) {
		$m = count( $a );
		$n = count( $b );

		// LCS長テーブル(メモリ節約のため通常配列、上限は呼び出し元でガード済み)
		$lcs = array_fill( 0, $m + 1, null );
		for ( $i = 0; $i <= $m; $i++ ) {
			$lcs[ $i ] = array_fill( 0, $n + 1, 0 );
		}
		for ( $i = $m - 1; $i >= 0; $i-- ) {
			for ( $j = $n - 1; $j >= 0; $j-- ) {
				if ( $a[ $i ] === $b[ $j ] ) {
					$lcs[ $i ][ $j ] = $lcs[ $i + 1 ][ $j + 1 ] + 1;
				} else {
					$lcs[ $i ][ $j ] = max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
				}
			}
		}

		$ops = array();
		$i   = 0;
		$j   = 0;
		$push = function ( $type, $token ) use ( &$ops ) {
			$last = count( $ops ) - 1;
			if ( $last >= 0 && $ops[ $last ]['type'] === $type ) {
				$ops[ $last ]['tokens'][] = $token;
			} else {
				$ops[] = array( 'type' => $type, 'tokens' => array( $token ) );
			}
		};

		while ( $i < $m && $j < $n ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$push( 'equal', $a[ $i ] );
				$i++;
				$j++;
			} elseif ( $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) {
				$push( 'delete', $a[ $i ] );
				$i++;
			} else {
				$push( 'insert', $b[ $j ] );
				$j++;
			}
		}
		while ( $i < $m ) {
			$push( 'delete', $a[ $i ] );
			$i++;
		}
		while ( $j < $n ) {
			$push( 'insert', $b[ $j ] );
			$j++;
		}

		return $ops;
	}

	/**
	 * 文章量が大きすぎる場合のフォールバック: 段落単位で「一致/変更あり」を判定する粗い差分。
	 */
	private static function paragraph_diff( $old_text, $new_text ) {
		$old_paras = array_values( array_filter( array_map( 'trim', preg_split( '/\n{1,}/', $old_text ) ) ) );
		$new_paras = array_values( array_filter( array_map( 'trim', preg_split( '/\n{1,}/', $new_text ) ) ) );

		$old_set = array_flip( $old_paras );

		$html = '<div class="seocp-diff seocp-diff-paragraph"><p class="description">本文が長いため、段落単位の簡易差分で表示しています。</p>';
		foreach ( $new_paras as $p ) {
			if ( isset( $old_set[ $p ] ) ) {
				$html .= '<p>' . esc_html( mb_substr( $p, 0, 200 ) ) . ( mb_strlen( $p ) > 200 ? '…' : '' ) . '</p>';
			} else {
				$html .= '<p><ins>' . esc_html( mb_substr( $p, 0, 400 ) ) . ( mb_strlen( $p ) > 400 ? '…' : '' ) . '</ins></p>';
			}
		}
		$new_set = array_flip( $new_paras );
		$removed = array();
		foreach ( $old_paras as $p ) {
			if ( ! isset( $new_set[ $p ] ) ) {
				$removed[] = $p;
			}
		}
		if ( ! empty( $removed ) ) {
			$html .= '<p><strong>削除された段落(' . count( $removed ) . '件):</strong></p>';
			foreach ( $removed as $p ) {
				$html .= '<p><del>' . esc_html( mb_substr( $p, 0, 200 ) ) . ( mb_strlen( $p ) > 200 ? '…' : '' ) . '</del></p>';
			}
		}
		$html .= '</div>';
		return $html;
	}
}
