<?php

use Wikimedia\Parsoid\Ext\ExtensionTagHandler;

/**
 * The Parsoid module schema requires a handler for every tag.
 * This one keeps the inherited sourceToDom(), which returns false, so Parsoid
 * still renders <markdown> through the legacy parser hook.
 */
class WikiMarkdownParsoidTag extends ExtensionTagHandler {
}
