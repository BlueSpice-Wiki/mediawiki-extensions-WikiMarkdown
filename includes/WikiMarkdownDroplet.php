<?php

use MediaWiki\Extension\ContentDroplets\Droplet\GenericDroplet;
use MediaWiki\Message\Message;

/**
 * Droplet for the ContentDroplets extension, which lets users insert a <markdown> block in VisualEditor.
 *
 * Registered through the "ContentDroplets" attribute in extension.json, so this class is only loaded
 * (and ContentDroplets\Droplet\GenericDroplet is only needed) when ContentDroplets is installed.
 */
class WikiMarkdownDroplet extends GenericDroplet {

	public function __construct() {
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): Message {
		return Message::newFromKey( 'wikimarkdown-droplet-name' );
	}

	/**
	 * @inheritDoc
	 */
	public function getDescription(): Message {
		return Message::newFromKey( 'wikimarkdown-droplet-desc' );
	}

	/**
	 * @inheritDoc
	 */
	public function getIcon(): string {
		return 'droplet-markdown';
	}

	/**
	 * The VisualEditor module provides the "markdownDialog" command, the other one the icon.
	 *
	 * @inheritDoc
	 */
	public function getRLModules(): array {
		return [ 'ext.wikimarkdown.visualEditor', 'ext.wikimarkdown.droplet' ];
	}

	/**
	 * @inheritDoc
	 */
	public function getCategories(): array {
		return [ 'content' ];
	}

	/**
	 * Sample content. Insertion goes through the VisualEditor command, see getVeCommand().
	 *
	 * @inheritDoc
	 */
	public function getContent(): string {
		return '<markdown>**Markdown**</markdown>';
	}

	/**
	 * @inheritDoc
	 */
	public function getVeCommand(): ?string {
		return 'markdownDialog';
	}
}
