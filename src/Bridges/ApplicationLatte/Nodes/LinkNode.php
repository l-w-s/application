<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Bridges\ApplicationLatte\Nodes;

use Latte\Compiler\Nodes\Php\Expression\ArrayNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\ModifierNode;
use Latte\Compiler\Nodes\StatementNode;
use Latte\Compiler\PrintContext;
use Latte\Compiler\Tag;


/**
 * {link destination [,] [params]}
 * {plink destination [,] [params]}
 * n:href="destination [,] [params]"
 */
class LinkNode extends StatementNode
{
	public ExpressionNode $destination;
	public ArrayNode $args;
	public ModifierNode $modifier;
	public string $mode;


	public static function create(Tag $tag): static
	{
		$tag->outputMode = $tag::OutputKeepIndentation;
		$tag->expectArguments();
		$node = new static;
		$node->destination = $tag->parser->parseUnquotedStringOrExpression();
		$tag->parser->stream->tryConsume(',');
		$node->args = $tag->parser->parseArguments();
		$node->modifier = $tag->parser->parseModifier();
		$node->modifier->escape = true;
		$node->mode = $tag->name;
		return $node;
	}


	public function print(PrintContext $context): string
	{
		return $context->format(
			($this->mode === 'href' ? "echo ' href=';" : '')
			. 'echo %modify('
			. ($this->mode === 'plink' ? '$this->global->uiPresenter' : '$this->global->uiControl')
			. '->link(%raw, %raw?)) %line;',
			$this->modifier,
			$this->destination,
			$this->args,
			$this->position,
		);
	}


	public function &getIterator(): \Generator
	{
		yield $this->destination;
		yield $this->args;
		yield $this->modifier;
	}
}
