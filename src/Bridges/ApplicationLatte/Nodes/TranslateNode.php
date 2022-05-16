<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Bridges\ApplicationLatte\Nodes;

use Latte\Compiler\NodeEvaluator;
use Latte\Compiler\Nodes\AreaNode;
use Latte\Compiler\Nodes\NopNode;
use Latte\Compiler\Nodes\Php;
use Latte\Compiler\Nodes\Php\ModifierNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\Nodes\TextNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;
use Nette\Localization\Translator;


/**
 * {translate} ... {/translate}
 */
class TranslateNode extends StatementNode
{
	public AreaNode $content;
	public ModifierNode $modifier;


	/** @return \Generator<int, ?array, array{AreaNode, ?Tag}, static|NopNode> */
	public static function create(Tag $tag, ?Translator $translator): \Generator
	{
		$tag->outputMode = $tag::OutputKeepIndentation;

		$node = new static;
		$args = $tag->parser->parseArguments();
		$node->modifier = $tag->parser->parseModifier();
		$node->modifier->escape = true;
		if ($tag->void) {
			return new NopNode;
		}

		[$node->content] = yield;

		$evaluator = new NodeEvaluator;
		if ($text = $evaluator->toText($node->content)) {
			$node->content = new TextNode($text);
			if ($translator) {
				try {
					$translation = $translator->translate($text, ...$evaluator->toValue($args));
					if (is_string($translation)) {
						$node->content = new TextNode($translation);
						return $node;
					}
				} catch (\InvalidArgumentException) {
				}
			}
		}

		array_unshift($node->modifier->filters, new Php\FilterNode(new Php\IdentifierNode('translate'), $args->toArguments()));

		return $node;
	}


	public function print(PrintContext $context): string
	{
		if ($this->content instanceof TextNode) {
			return $context->format(
				<<<'XX'
					$ʟ_fi = new LR\FilterInfo(%dump);
					echo %modifyContent(%dump) %line;
					XX,
				$context->getEscaper()->export(),
				$this->modifier,
				$this->content->content,
				$this->position,
			);

		} else {
			return $context->format(
				<<<'XX'
					ob_start(fn() => ''); try {
						%node
					} finally {
						$ʟ_tmp = ob_get_clean();
					}
					$ʟ_fi = new LR\FilterInfo(%dump);
					echo %modifyContent($ʟ_tmp) %line;
					XX,
				$this->content,
				$context->getEscaper()->export(),
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
