<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Application\UI;

use Nette;


/**
 * Component is the base class for all Presenter components.
 *
 * Components are persistent objects located on a presenter. They have ability to own
 * other child components, and interact with user. Components have properties
 * for storing their status, and responds to user command.
 *
 * @property-deprecated Presenter $presenter
 * @property-deprecated bool $linkCurrent
 */
abstract class Component extends Nette\ComponentModel\Container implements SignalReceiver, StatePersistent, \ArrayAccess
{
	use Nette\ComponentModel\ArrayAccess;

	/** @var array<callable(self): void>  Occurs when component is attached to presenter */
	public iterable $onAnchor = [];

	protected array $params = [];


	/**
	 * Returns the presenter where this component belongs to.
	 */
	public function getPresenter(): Presenter
	{
		return $this->lookup(Presenter::class, throw: true);
	}


	/**
	 * Returns the presenter where this component belongs to.
	 */
	public function getPresenterIfExists(): ?Presenter
	{
		return $this->lookup(Presenter::class, throw: false);
	}


	public function hasPresenter(): bool
	{
		return (bool) $this->lookup(Presenter::class, throw: false);
	}


	/**
	 * Returns a fully-qualified name that uniquely identifies the component
	 * within the presenter hierarchy.
	 */
	public function getUniqueId(): string
	{
		return $this->lookupPath(Presenter::class, true);
	}


	public function addComponent(
		Nette\ComponentModel\IComponent $component,
		?string $name,
		?string $insertBefore = null,
	): static {
		if (!$component instanceof SignalReceiver && !$component instanceof StatePersistent) {
			throw new Nette\InvalidStateException("Component '$name' of type " . gettype($component) . ' is not intended to be used in the Presenter.');
		}

		return parent::addComponent($component, $name, $insertBefore = null);
	}


	protected function validateParent(Nette\ComponentModel\IContainer $parent): void
	{
		parent::validateParent($parent);
		$this->monitor(Presenter::class, function (Presenter $presenter): void {
			$this->loadState($presenter->popGlobalParameters($this->getUniqueId()));
			Nette\Utils\Arrays::invoke($this->onAnchor, $this);
		});
	}


	/**
	 * Calls public method if exists.
	 */
	protected function tryCall(string $method, array $params): bool
	{
		$rc = $this->getReflection();
		if ($rc->hasMethod($method)) {
			$rm = $rc->getMethod($method);
			if ($rm->isPrivate()) {
				throw new Nette\InvalidStateException('Method ' . $rm->getName() . '() can not be called because it is private.');
			}

			if (!$rm->isAbstract() && !$rm->isStatic()) {
				$this->checkRequirements($rm);
				try {
					$args = $rc->combineArgs($rm, $params);
				} catch (Nette\InvalidArgumentException $e) {
					throw new Nette\Application\BadRequestException($e->getMessage());
				}

				$rm->invokeArgs($this, $args);
				return true;
			}
		}

		return false;
	}


	/**
	 * Checks for requirements such as authorization.
	 */
	public function checkRequirements($element): void
	{
		if (
			$element instanceof \ReflectionMethod
			&& str_starts_with($element->getName(), 'handle')
			&& !ComponentReflection::parseAnnotation($element, 'crossOrigin')
			&& !$element->getAttributes(Nette\Application\Attributes\CrossOrigin::class)
			&& !$this->getPresenter()->getHttpRequest()->isSameSite()
		) {
			$this->getPresenter()->detectedCsrf();
		}

		if ($attrs = $element->getAttributes(Nette\Application\Attributes\AllowedFor::class)) {
			$method = strtolower($this->getPresenter()->getRequest()->getMethod());
			if (empty($attrs[0]->newInstance()->$method)) {
				throw new Nette\Application\BadRequestException("Method '$method' is not allowed.");
			}
		}
	}


	/**
	 * Access to reflection.
	 */
	public static function getReflection(): ComponentReflection
	{
		return new ComponentReflection(static::class);
	}


	/********************* interface StatePersistent ****************d*g**/


	/**
	 * Loads state informations.
	 */
	public function loadState(array $params): void
	{
		$reflection = $this->getReflection();
		foreach ($reflection->getPersistentParams() as $name => $meta) {
			if (isset($params[$name])) { // nulls are ignored
				if (!$reflection->convertType($params[$name], $meta['type'])) {
					throw new Nette\Application\BadRequestException(sprintf(
						"Value passed to persistent parameter '%s' in %s must be %s, %s given.",
						$name,
						$this instanceof Presenter ? 'presenter ' . $this->getName() : "component '{$this->getUniqueId()}'",
						$meta['type'],
						is_object($params[$name]) ? get_class($params[$name]) : gettype($params[$name]),
					));
				}

				$this->$name = $params[$name];
			} else {
				$params[$name] = $this->$name ?? null;
			}
		}

		$this->params = $params;
	}


	/**
	 * Saves state informations for next request.
	 */
	public function saveState(array &$params): void
	{
		$this->getReflection()->saveState($this, $params);
	}


	/**
	 * Returns component param.
	 */
	final public function getParameter(string $name): mixed
	{
		if (func_num_args() > 1) {
			trigger_error(__METHOD__ . '() parameter $default is deprecated, use operator ??', E_USER_DEPRECATED);
			$default = func_get_arg(1);
		}
		return $this->params[$name] ?? $default ?? null;
	}


	/**
	 * Returns component parameters.
	 */
	final public function getParameters(): array
	{
		return $this->params;
	}


	/**
	 * Returns a fully-qualified name that uniquely identifies the parameter.
	 */
	final public function getParameterId(string $name): string
	{
		$uid = $this->getUniqueId();
		return $uid === '' ? $name : $uid . self::NameSeparator . $name;
	}


	/********************* interface SignalReceiver ****************d*g**/


	/**
	 * Calls signal handler method.
	 * @throws BadSignalException if there is not handler method
	 */
	public function signalReceived(string $signal): void
	{
		if (!$this->tryCall($this->formatSignalMethod($signal), $this->params)) {
			$class = static::class;
			throw new BadSignalException("There is no handler for signal '$signal' in class $class.");
		}
	}


	/**
	 * Formats signal handler method name -> case sensitivity doesn't matter.
	 */
	public static function formatSignalMethod(string $signal): string
	{
		return 'handle' . $signal;
	}


	/********************* navigation ****************d*g**/


	/**
	 * Generates URL to presenter, action or signal.
	 * @param  string   $destination in format "[//] [[[module:]presenter:]action | signal! | this] [#fragment]"
	 * @param  mixed  ...$args
	 * @throws InvalidLinkException
	 */
	public function link(string $destination, ...$args): string
	{
		try {
			$args = count($args) === 1 && is_array($args[0] ?? null)
				? $args[0]
				: $args;
			return $this->getPresenter()->createRequest($this, $destination, $args, 'link');

		} catch (InvalidLinkException $e) {
			return $this->getPresenter()->handleInvalidLink($e);
		}
	}


	/**
	 * Returns destination as Link object.
	 * @param  string   $destination in format "[//] [[[module:]presenter:]action | signal! | this] [#fragment]"
	 * @param  mixed  ...$args
	 */
	public function lazyLink(string $destination, ...$args): Link
	{
		$args = count($args) === 1 && is_array($args[0] ?? null)
			? $args[0]
			: $args;
		return new Link($this, $destination, $args);
	}


	/**
	 * Determines whether it links to the current page.
	 * @param  string   $destination in format "[//] [[[module:]presenter:]action | signal! | this] [#fragment]"
	 * @param  mixed  ...$args
	 * @throws InvalidLinkException
	 */
	public function isLinkCurrent(?string $destination = null, ...$args): bool
	{
		if ($destination !== null) {
			$args = count($args) === 1 && is_array($args[0] ?? null)
				? $args[0]
				: $args;
			$this->getPresenter()->createRequest($this, $destination, $args, 'test');
		}

		return $this->getPresenter()->getLastCreatedRequestFlag('current');
	}


	/**
	 * Redirect to another presenter, action or signal.
	 * @param  string   $destination in format "[//] [[[module:]presenter:]action | signal! | this] [#fragment]"
	 * @param  mixed  ...$args
	 * @throws Nette\Application\AbortException
	 */
	public function redirect(string $destination, ...$args): void
	{
		$args = count($args) === 1 && is_array($args[0] ?? null)
			? $args[0]
			: $args;
		$presenter = $this->getPresenter();
		$presenter->redirectUrl($presenter->createRequest($this, $destination, $args, 'redirect'));
	}


	/**
	 * Permanently redirects to presenter, action or signal.
	 * @param  string   $destination in format "[//] [[[module:]presenter:]action | signal! | this] [#fragment]"
	 * @param  mixed  ...$args
	 * @throws Nette\Application\AbortException
	 */
	public function redirectPermanent(string $destination, ...$args): void
	{
		$args = count($args) === 1 && is_array($args[0] ?? null)
			? $args[0]
			: $args;
		$presenter = $this->getPresenter();
		$presenter->redirectUrl(
			$presenter->createRequest($this, $destination, $args, 'redirect'),
			Nette\Http\IResponse::S301_MOVED_PERMANENTLY,
		);
	}


	/**
	 * Throws HTTP error.
	 * @throws Nette\Application\BadRequestException
	 */
	public function error(string $message = '', int $httpCode = Nette\Http\IResponse::S404_NOT_FOUND): void
	{
		throw new Nette\Application\BadRequestException($message, $httpCode);
	}
}


class_exists(PresenterComponent::class);
