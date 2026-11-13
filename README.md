# About

This is a [MediaWiki](https://www.mediawiki.org/) extension that allows for markdown syntax to be used on wiki pages.  More information can be found on the [extension page](https://www.mediawiki.org/wiki/Extension:WikiMarkdown).

# Requirements

This branch (`REL1_47`) is a port of WikiMarkdown 1.1.3 to MediaWiki 1.44 and later. It was tested on MediaWiki 1.47 with PHP 8.3, with both the Parsoid and the legacy parser, together with the NativeMarkdown extension.

Changes compared with upstream 1.1.3:

* Fixed fatal errors on current MediaWiki (removed global class names, `Linker::makeHeadline()`, `Parser::enableOOUI()`, static `SyntaxHighlight::parserHook()`, `ResourceLoaderFileModule`).
* Removed the `.md` page content model, which clashed with NativeMarkdown's `markdown` model. Use NativeMarkdown for whole Markdown pages.
* Registered `<markdown>` with Parsoid as a block tag, so blocks inserted with VisualEditor are saved on their own lines.
* Links: link titles, formatted link text and footnote links work. Only real URLs become external links.
* Headings: unique anchors across all blocks of a page, wiki-style heading markup, Parsedown Extra `{#id .class}` support.
* Maths is no longer applied inside code, and task-list checkboxes no longer throw an OOUI exception.
* A missing optional Parsedown library logs a warning instead of causing a fatal error.

# Installation

Add this line to your LocalSettings.php:

```php
wfLoadExtension( 'WikiMarkdown' );
```

Install Parsedown 1.8 and, optionally, one of the plugin sets with Composer from the MediaWiki root directory:

```bash
# Recommended: Markdown Extra (tables, footnotes, definition lists, abbreviations, heading ids)
COMPOSER=composer.local.json composer require --no-update "erusev/parsedown:^1.8.0" "erusev/parsedown-extra:^0.9.0"

# Alternative: Parsedown Extended (task lists, maths, highlight, sub/sup, emoji), which needs Extra 0.8.1.
# Extra 0.8.1 does not work with Parsedown 1.8: footnotes, definition lists and abbreviations break.
COMPOSER=composer.local.json composer require --no-update "erusev/parsedown:^1.8.0" "erusev/parsedown-extra:^0.8.1" "benjaminhoegh/parsedown-extended:1.1.2"

composer update erusev/parsedown erusev/parsedown-extra benjaminhoegh/parsedown-extended --no-dev -o
```

# Usage

On wiki pages, you can now use `<markdown>` elements:

```html
<markdown>
## Emphasis

**This is bold text**

__This is bold text__

*This is italic text*

_This is italic text_

~~Strikethrough~~
</markdown>
```

# Parameters

* `inline`:   Indicates that inline-style markdown should be used instead of block-style.

# Configuration

* `$wgAllowMarkdownExtra` (optional): Set to `true` in order to specify that [Parsedown Extra](https://github.com/erusev/parsedown-extra) should be used.
* `$wgAllowMarkdownExtended` (optional): Set to `true` in order to specify that [Parsedown Extended](https://github.com/BenjaminHoegh/ParsedownExtended) should be used.
* `$wgParsedownExtendedParameters` (optional): Allows for specifying the options that are passed to Parsedown Extended.  See the [documentation](https://benjaminhoegh.github.io/ParsedownExtended/) for which options you want to enable or disable.

# Other Features
* When specifying code blocks in markdown, this extension will automatically apply the [SyntaxHighlight](https://www.mediawiki.org/wiki/Extension:SyntaxHighlight) extension if it is installed.  All languages supported by the SyntaxHighlight extension will work.
* When using Parsedown Extended, this extension will automatically apply the [Math](https://www.mediawiki.org/wiki/Extension:Math) extension if it is installed to any math blocks by default.  This can be turned off by either not using Parsedown Extended, or modifying `$wgParsedownExtendedParameters`.  The following syntaxes are valid: `\[ ... \]`/`$$ ... $$` (for block mode) and `\( ... \)`/`$ ... $` (for inline mode)
* When using the [VisualEditor](https://www.mediawiki.org/wiki/Extension:VisualEditor) extension with the CodeEditor extension, markdown blocks will be editable using a markdown editor.
* When the ContentDroplets extension (part of BlueSpice) is installed, a "Markdown" droplet is available in the VisualEditor droplets dialog. It opens the markdown editor for a new `<markdown>` block. It is registered through the `ContentDroplets` attribute in `extension.json`, so it does nothing without ContentDroplets.

# Credits

* This extension was inspired by the [Markdown Content Handler](https://github.com/brightbyte/MWExtension-Markdown) by Daniel Kinzler and the [Markdown Extension](https://www.mediawiki.org/wiki/Extension:Markdown) by Blake Harley.
* Parts of this code are based on the [SyntaxHighlight](https://www.mediawiki.org/wiki/Extension:SyntaxHighlight) and [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto) extensions.
