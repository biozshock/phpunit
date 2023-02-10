<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Framework\MockObject;

use function assert;
use function implode;
use function sprintf;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\MockObject\Rule\AnyInvokedCount;
use PHPUnit\Framework\MockObject\Rule\AnyParameters;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\MockObject\Rule\InvokedAtMostCount;
use PHPUnit\Framework\MockObject\Rule\InvokedCount;
use PHPUnit\Framework\MockObject\Rule\MethodName;
use PHPUnit\Framework\MockObject\Rule\ParametersRule;
use PHPUnit\Framework\MockObject\Stub\Stub;
use PHPUnit\Util\ThrowableToStringMapper;

/**
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */
final class Matcher
{
    private readonly InvocationOrder $invocationRule;
    private ?string $afterMatchBuilderId    = null;
    private ?MethodName $methodNameRule     = null;
    private ?ParametersRule $parametersRule = null;
    private ?Stub $stub                     = null;
    private ?int $consecutiveCall           = null;
    private bool $checkOrder                = false;

    public function __construct(InvocationOrder $rule)
    {
        $this->invocationRule = $rule;

        $match = $rule->getMatcher();

        if (null !== $match) {
            $this->setMethodNameRule($match->methodNameRule());
            $this->setConsecutiveCall($match->getConsecutiveCall() + 1);

            if ($match->isCheckOrder()) {
                $this->setAfterMatchBuilderId($match->getConsecutiveId());
            }
        }
    }

    public function hasMatchers(): bool
    {
        return !$this->invocationRule instanceof AnyInvokedCount;
    }

    public function hasMethodNameRule(): bool
    {
        return $this->methodNameRule !== null;
    }

    public function methodNameRule(): MethodName
    {
        return $this->methodNameRule;
    }

    public function setMethodNameRule(MethodName $rule): void
    {
        $this->methodNameRule = $rule;
    }

    public function hasParametersRule(): bool
    {
        return $this->parametersRule !== null;
    }

    public function setParametersRule(ParametersRule $rule): void
    {
        $this->parametersRule = $rule;
    }

    public function setStub(Stub $stub): void
    {
        $this->stub = $stub;
    }

    public function setAfterMatchBuilderId(string $id): void
    {
        $this->afterMatchBuilderId = $id;
    }

    public function setConsecutiveCall(int $consecutiveCall): void
    {
        $this->consecutiveCall = $consecutiveCall;
    }

    public function getConsecutiveCall(): int
    {
        assert(null !== $this->consecutiveCall);

        return $this->consecutiveCall;
    }

    public function isConsecutiveCall(): bool
    {
        return null !== $this->consecutiveCall;
    }

    public function getConsecutiveId(): string
    {
        assert(null !== $this->methodNameRule);

        return $this->methodNameRule->toString() . ' call #' . $this->getConsecutiveCall();
    }

    public function setCheckOrder(bool $checkOrder): void
    {
        $this->checkOrder = $checkOrder;
    }

    public function isCheckOrder(): bool
    {
        return $this->checkOrder;
    }

    /**
     * @throws Exception
     * @throws ExpectationFailedException
     * @throws MatchBuilderNotFoundException
     * @throws MethodNameNotConfiguredException
     * @throws RuntimeException
     */
    public function invoked(Invocation $invocation): mixed
    {
        if ($this->methodNameRule === null) {
            throw new MethodNameNotConfiguredException;
        }

        if ($this->afterMatchBuilderId !== null) {
            $matcher = $invocation->object()
                                  ->__phpunit_getInvocationHandler()
                                  ->lookupMatcher($this->afterMatchBuilderId);

            if (!$matcher) {
                throw new MatchBuilderNotFoundException($this->afterMatchBuilderId);
            }
        }

        $this->invocationRule->invoked($invocation);

        try {
            $this->parametersRule?->apply($invocation);
        } catch (ExpectationFailedException $e) {
            throw new ExpectationFailedException(
                sprintf(
                    "Expectation failed for %s when %s\n%s",
                    $this->methodNameRule->toString(),
                    $this->invocationRule->toString(),
                    $e->getMessage()
                ),
                $e->getComparisonFailure()
            );
        }

        if ($this->stub) {
            return $this->stub->invoke($invocation);
        }

        return $invocation->generateReturnValue();
    }

    /**
     * @throws ExpectationFailedException
     * @throws MatchBuilderNotFoundException
     * @throws MethodNameNotConfiguredException
     * @throws RuntimeException
     */
    public function matches(Invocation $invocation): bool
    {
        if ($this->afterMatchBuilderId !== null) {
            $matcher = $invocation->object()
                                  ->__phpunit_getInvocationHandler()
                                  ->lookupMatcher($this->afterMatchBuilderId);

            if (!$matcher) {
                throw new MatchBuilderNotFoundException($this->afterMatchBuilderId);
            }

            assert($matcher instanceof self);

            if (!$matcher->invocationRule->hasBeenInvoked()) {
                return false;
            }
        }

        if ($this->methodNameRule === null) {
            throw new MethodNameNotConfiguredException;
        }

        if (!$this->invocationRule->matches($invocation)) {
            return false;
        }

        try {
            if (!$this->methodNameRule->matches($invocation)) {
                return false;
            }
        } catch (ExpectationFailedException $e) {
            throw new ExpectationFailedException(
                sprintf(
                    "Expectation failed for %s when %s\n%s",
                    $this->methodNameRule->toString(),
                    $this->invocationRule->toString(),
                    $e->getMessage()
                ),
                $e->getComparisonFailure()
            );
        }

        if ($this->parametersRule !== null && $this->consecutiveCall !== null && $this->parametersRule->match($invocation) === false) {
            return false;
        }

        return true;
    }

    /**
     * @throws ExpectationFailedException
     * @throws MethodNameNotConfiguredException
     */
    public function verify(): void
    {
        if ($this->methodNameRule === null) {
            throw new MethodNameNotConfiguredException;
        }

        try {
            $this->invocationRule->verify();

            if ($this->parametersRule === null) {
                $this->parametersRule = new AnyParameters;
            }

            $invocationIsAny    = $this->invocationRule instanceof AnyInvokedCount;
            $invocationIsNever  = $this->invocationRule instanceof InvokedCount && $this->invocationRule->isNever();
            $invocationIsAtMost = $this->invocationRule instanceof InvokedAtMostCount;

            if (!$invocationIsAny && !$invocationIsNever && !$invocationIsAtMost) {
                $this->parametersRule->verify();
            }
        } catch (ExpectationFailedException $e) {
            throw new ExpectationFailedException(
                sprintf(
                    "Expectation failed for %s when %s.\n%s",
                    $this->methodNameRule->toString(),
                    $this->invocationRule->toString(),
                    ThrowableToStringMapper::map($e)
                )
            );
        }
    }

    public function toString(): string
    {
        $list = [$this->invocationRule->toString()];

        if ($this->methodNameRule !== null) {
            $list[] = 'where ' . $this->methodNameRule->toString();
        }

        if ($this->parametersRule !== null) {
            $list[] = 'and ' . $this->parametersRule->toString();
        }

        if ($this->afterMatchBuilderId !== null) {
            $list[] = 'after ' . $this->afterMatchBuilderId;
        }

        if ($this->stub !== null) {
            $list[] = 'will ' . $this->stub->toString();
        }

        return implode(' ', $list);
    }
}
