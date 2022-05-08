<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Bridges\ApplicationLatte\Nodes;

use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\FragmentNode;
use Latte\Compiler\Nodes\NopNode;
use Latte\Compiler\Nodes\Php\ModifierNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\Nodes\TextNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;


/**
 * {translate} ... {/translate}
 */
class TranslateNode extends StatementNode
{
	public AreaNode $content;
	public ModifierNode $modifier;


	/** @return \Generator<int, ?array, array{AreaNode, ?Tag}, static|NopNode> */
	public static function create(Tag $tag): \Generator
	{
		$tag->outputMode = $tag::OutputKeepIndentation;

		$node = new static;
		$node->modifier = $tag->parser->parseModifier();
		$node->modifier->escape = true;
		if ($tag->void) {
			return new NopNode;
		}

		[$node->content] = yield;
		return $node;
	}


	public function print(PrintContext $context): string
	{
		if (
			$this->content instanceof FragmentNode
			&& count($this->content->children) === 1
			&& $this->content->children[0] instanceof TextNode
		) {
			return $context->format(
				<<<'XX'
					$ʟ_fi = new LR\FilterInfo(%dump);
					echo %modifyContent($this->filters->filterContent('translate', $ʟ_fi, %dump)) %line;
					XX,
				implode('', $context->getEscapingContext()),
				$this->modifier,
				$this->content->children[0]->content,
				$this->position,
			);

		} else {
			return $context->format(
				<<<'XX'
					ob_start(fn() => ''); try {
						%raw
					} finally {
						$ʟ_tmp = ob_get_clean();
					}
					$ʟ_fi = new LR\FilterInfo(%dump);
					echo %modifyContent($this->filters->filterContent('translate', $ʟ_fi, $ʟ_tmp)) %line;
					XX,
				$this->content,
				implode('', $context->getEscapingContext()),
				$this->modifier,
				$this->position,
			);
		}
	}


	public function &getIterator(): \Generator
	{
		yield $this->content;
		yield $this->modifier;
	}
}
