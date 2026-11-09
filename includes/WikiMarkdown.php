<?php

use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Status\Status;

class WikiMarkdown {

	/** @var string CSS class for markdown code. */
	const MARKDOWN_CSS_CLASS = 'mw-markdown';

	/** @var int Cache version. Increment whenever the HTML changes. */
	const CACHE_VERSION = 2;

	/** @var WeakMap|null Heading anchors already used, per ParserOutput (shared by all blocks on a page) */
	private static ?WeakMap $usedAnchors = null;

	/**
	 * Register parser hook
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'markdown', [ self::class, 'parserHook' ] );
	}

	/**
	 * Parser hook for <markdown> logic
	 *
	 * @param string|null $text
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public static function parserHook( $text, $args, Parser $parser, PPFrame $frame ) {
		global $wgAllowMarkdownExtended;

		// Replace strip markers (For e.g. {{#tag:markdown|<nowiki>...}})
		$out = $parser->getStripState()->unstripNoWiki( $text ?? '' );

		// Don't trim leading spaces away, just the linefeeds
		$out = preg_replace( '/^\n+/', '', rtrim( $out ) );

		$result = self::parseMarkdown( $out, $args );
		if ( !$result->isGood() ) {
			$parser->addTrackingCategory( 'markdown-error-category' );
		}
		$out = (string)$result->getValue();

		// Make it so that tables have borders
		$out = str_replace( '<table>', '<table class="wikitable">', $out );

		// Format links. Only plain Parsedown links are matched (href plus optional title), so footnote
		// links, which carry a class attribute, are left alone. So are links without a URL protocol
		// (#fragments, relative targets, and unsafe schemes that safe mode has neutralised).
		$protocols = MediaWikiServices::getInstance()->getUrlUtils()->validProtocols();
		$out = preg_replace_callback(
			'/<a href="([^"]*)"(?: title="([^"]*)")?>(.*?)<\/a>/s',
			static function ( $matches ) use ( $parser, $protocols ) {
				$url = html_entity_decode( $matches[1] );
				if ( !preg_match( '/^(?:' . $protocols . ')/i', $url ) ) {
					return $matches[0];
				}
				$html = $matches[3];
				$linkType = $url === html_entity_decode( strip_tags( $html ) ) ? 'free' : 'text';
				$cleanUrl = Sanitizer::cleanUrl( $url );
				$attribs = $parser->getExternalLinkAttribs( $cleanUrl );
				if ( isset( $matches[2] ) && $matches[2] !== '' ) {
					$attribs['title'] = html_entity_decode( $matches[2] );
				}
				// Register link in the output object
				$parser->getOutput()->addExternalLink( $cleanUrl );
				// The link text is already safe HTML produced by Parsedown in safe mode, so it is not escaped again
				return Linker::makeExternalLink( $cleanUrl, $html, false, $linkType, $attribs, $parser->getTitle() );
			},
			$out
		);

		// Make html headings into wiki-style headings (same wrapper markup as core headings, no edit links)
		$output = $parser->getOutput();
		self::$usedAnchors ??= new WeakMap();
		$used = self::$usedAnchors[$output] ?? [];
		$out = preg_replace_callback(
			'/<h([1-6])((?:\s+[a-z-]+="[^"]*")*)>(.*?)<\/h\1>/s',
			static function ( $matches ) use ( &$used ) {
				$level = $matches[1];
				// Parsedown Extra's {#id .class} and Parsedown Extended's automatic anchors give an id
				preg_match_all( '/([a-z-]+)="([^"]*)"/', $matches[2], $attrMatches, PREG_SET_ORDER );
				$attrs = [];
				foreach ( $attrMatches as [ , $name, $value ] ) {
					$attrs[$name] = html_entity_decode( $value );
				}
				$idSource = ( $attrs['id'] ?? '' ) !== '' ? $attrs['id'] : html_entity_decode( strip_tags( $matches[3] ) );
				$anchor = Sanitizer::escapeIdForAttribute( 'markdown_' . $idSource );
				// Ensure that anchors are unique across all <markdown> blocks of the page
				if ( isset( $used[$anchor] ) ) {
					for ( $i = 2; isset( $used["{$anchor}_$i"] ); ++$i );
					$anchor .= "_$i";
				}
				$used[$anchor] = true;
				return Html::rawElement( 'div', [ 'class' => "mw-heading mw-heading$level" ],
					Html::rawElement( "h$level", [ 'id' => $anchor, 'class' => $attrs['class'] ?? null ], $matches[3] )
				);
			},
			$out
		);
		self::$usedAnchors[$output] = $used;

		// Take code out of the way, so that later regular expressions (math) do not touch it
		$protected = [];
		$protect = static function ( string $html ) use ( &$protected ): string {
			$key = "\x7fWIKIMARKDOWN-" . count( $protected ) . "-" . bin2hex( random_bytes( 4 ) ) . "\x7f";
			$protected[$key] = $html;
			return $key;
		};
		$highlight = ExtensionRegistry::getInstance()->isLoaded( 'SyntaxHighlight' );
		$out = preg_replace_callback(
			'/<pre><code(?: class="language-([^"]*)")?>(.*?)<\/code><\/pre>/s',
			static function ( $matches ) use ( $parser, $protect, $highlight ) {
				$lang = html_entity_decode( $matches[1] ?? '' );
				// If there's no language (or no highlighter), just remove the nested <code> tag
				if ( $lang === '' || !$highlight ) {
					return $protect( '<pre>' . $matches[2] . '</pre>' );
				}
				// If a language is specified, let SyntaxHighlight handle it
				$syntaxHighlight = MediaWikiServices::getInstance()->getService( 'SyntaxHighlight.SyntaxHighlight' );
				return $protect( $syntaxHighlight->parserHook( html_entity_decode( $matches[2] ), [ 'lang' => $lang ], $parser ) );
			},
			$out
		);
		$out = preg_replace_callback( '/<code>.*?<\/code>/s', static fn ( $m ) => $protect( $m[0] ), $out );

		// If Parsedown Extended is available with tasks turned on, then convert them to OOUI checkboxes
		$options = $wgAllowMarkdownExtended ? ( self::getParsedown()->options ?? [] ) : [];
		if ( $wgAllowMarkdownExtended && ( $options['lists']['tasks'] ?? true ) !== false
			&& str_contains( $out, 'type="checkbox"' )
		) {
			OutputPage::setupOOUI();
			$output->setEnableOOUI( true );
			$out = preg_replace_callback(
				'/<input\s+type="checkbox"(.*?)>/is',
				static function ( $matches ) {
					$check = new \OOUI\CheckboxInputWidget( [
						'disabled' => true,
						'selected' => preg_match( '/\bchecked\b/', $matches[1] ) === 1,
					] );
					return $check->toString();
				},
				$out
			);
		}

		// If Parsedown Extended is available with math turned on and the Math extension is loaded,
		// then hand the formulas to the Math extension's <math> tag
		if ( $wgAllowMarkdownExtended && ( $options['math'] ?? false ) !== false
			&& ExtensionRegistry::getInstance()->isLoaded( 'Math' )
		) {
			$math = static function ( string $tex, string $display ) use ( $parser, $frame, $protect ): string {
				$wikitext = Html::rawElement( 'math', [ 'display' => $display ], html_entity_decode( $tex ) );
				return $protect( $parser->getStripState()->unstripBoth( $parser->recursiveTagParse( $wikitext, $frame ) ) );
			};
			$patterns = [
				'/(?<!\\\\)\\\\\[(.*?)(?<!\\\\)\\\\\]/s' => 'block',
				'/(?<!\\\\)\$\$(.*?)(?<!\\\\)\$\$/s' => 'block',
				'/(?<!\\\\)\\\\\((.*?)(?<!\\\\)\\\\\)/s' => 'inline',
			];
			if ( $options['math']['single_dollar'] ?? false ) {
				$patterns['/(?<!\\\\)\$(.+?)(?<!\\\\)\$/s'] = 'inline';
			}
			foreach ( $patterns as $pattern => $display ) {
				$out = preg_replace_callback( $pattern, static fn ( $m ) => $math( $m[1], $display ), $out );
			}
		}

		// Put code (and math) back
		$out = strtr( $out, $protected );

		// Allow certain HTML attributes
		$htmlAttribs = Sanitizer::validateAttributes(
			$args, array_flip( [ 'style', 'class', 'id', 'dir' ] )
		);
		if ( !isset( $htmlAttribs['class'] ) ) {
			$htmlAttribs['class'] = self::MARKDOWN_CSS_CLASS;
		} else {
			$htmlAttribs['class'] .= ' ' . self::MARKDOWN_CSS_CLASS;
		}
		if ( isset( $args['inline'] ) ) {
			// Enforce inlineness. Stray newlines may result in unexpected list and paragraph processing
			// (also known as doBlockLevels()).
			$out = str_replace( "\n", ' ', $out );
			$out = Html::rawElement( 'span', $htmlAttribs, $out );

		} else {
			// Use 'nowiki' strip marker to prevent list processing (also known as doBlockLevels()).
			// However, leave the wrapping <div/> outside to prevent <p/>-wrapping.
			$marker = $parser::MARKER_PREFIX . '-markdowninner-' .
				sprintf( '%08X', $parser->mMarkerIndex++ ) . $parser::MARKER_SUFFIX;
			$parser->getStripState()->addNoWiki( $marker, $out );
			$out = $marker;

			$out = Html::openElement( 'div', $htmlAttribs ) .
				$out .
				Html::closeElement( 'div' );
		}

		return $out;
	}

	/**
	 * Parse markdown syntax.
	 *
	 * This produces raw HTML (wrapped by Status).
	 *
	 * @param string $markdown Markdown syntax to parse.
	 * @param array $args Associative array of additional arguments.
	 *  If it contains a 'inline' key, the output will not be wrapped in `<div></div>`.
	 * @return Status Status object, with HTML representing the parsed
	 *  markdown as its value.
	 */
	public static function parseMarkdown( $markdown, $args = [] ) {
		$status = new Status;

		// For empty tag, output nothing instead of empty <div>.
		if ( $markdown === '' ) {
			$status->value = '';
			return $status;
		}

		$inline = isset( $args['inline'] );

		$parsedown = static::getParsedown();
		$parsedown->setSafeMode( true );

		if ( $inline ) {
			$markdown = trim( $markdown );
			$output = $parsedown->line( $markdown );
			$output = trim( $output );
		} else {
			$output = $parsedown->text( $markdown );
		}

		if ( $output === null ) {
			$status->warning( 'markdown-error-parse-failure' );
			wfWarn( 'Parsing markdown returned blank output' );
		}

		$status->value = $output;
		return $status;
	}

	/**
	 * Conditionally register resource loader modules that depends on the
	 * VisualEditor MediaWiki extension.
	 *
	 * @param ResourceLoader $resourceLoader
	 */
	public static function onResourceLoaderRegisterModules( ResourceLoader $resourceLoader ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'VisualEditor' ) ) {
			return;
		}

		$resourceLoader->register( 'ext.wikimarkdown.visualEditor', [
			'localBasePath' => __DIR__ . '/../modules',
			'remoteExtPath' => 'WikiMarkdown/modules',
			'scripts' => [
				've-markdown/ve.dm.MWMarkdownNode.js',
				've-markdown/ve.dm.MWBlockMarkdownNode.js',
				've-markdown/ve.dm.MWInlineMarkdownNode.js',
				've-markdown/ve.ce.MWMarkdownNode.js',
				've-markdown/ve.ce.MWBlockMarkdownNode.js',
				've-markdown/ve.ce.MWInlineMarkdownNode.js',
				've-markdown/ve.ui.MWMarkdownWindow.js',
				've-markdown/ve.ui.MWMarkdownDialog.js',
				've-markdown/ve.ui.MWMarkdownDialogTool.js',
				've-markdown/ve.ui.MWMarkdownInspector.js',
				've-markdown/ve.ui.MWMarkdownInspectorTool.js',
			],
			'styles' => [
				've-markdown/ve.ce.MWMarkdownNode.css',
				've-markdown/ve.ui.MWMarkdownDialog.css',
				've-markdown/ve.ui.MWMarkdownInspector.css',
			],
			'dependencies' => [
				'ext.visualEditor.mwcore',
				'oojs-ui.styles.icons-editing-advanced'
			],
			'messages' => [
				'markdown-visualeditor-mwmarkdowninspector-code',
				'markdown-visualeditor-mwmarkdowninspector-title',
			],
		] );
	}

	/**
	 * @return Parsedown
	 */
	protected static function getParsedown() {
		static $parsedown;
		global $wgAllowMarkdownExtra;
		global $wgAllowMarkdownExtended;
		global $wgParsedownExtendedParameters;

		if ( !$parsedown ) {
			// Fall back to a simpler parser (instead of a fatal error) when a library is not installed
			if ( $wgAllowMarkdownExtended && !class_exists( \ParsedownExtended::class ) ) {
				wfLogWarning( 'WikiMarkdown: $wgAllowMarkdownExtended is set, but ParsedownExtended is not installed' );
				$wgAllowMarkdownExtended = false;
			}
			if ( $wgAllowMarkdownExtra && !class_exists( \ParsedownExtra::class ) ) {
				wfLogWarning( 'WikiMarkdown: $wgAllowMarkdownExtra is set, but ParsedownExtra is not installed' );
				$wgAllowMarkdownExtra = false;
			}
			$parsedown = $wgAllowMarkdownExtended
				? new \ParsedownExtended( $wgParsedownExtendedParameters )
				: ( $wgAllowMarkdownExtra
					? new \ParsedownExtra()
					: new \Parsedown() );
		}

		return $parsedown;
	}
}
