<?php

declare(strict_types=1);

namespace Tudorsync\Core\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use UnitEnum;

/**
 * Guard: no constructor in core/src may have an object as a parameter's default value
 * (`Foo $foo = new Foo()`). Magento's setup:di:compile writes that default into
 * generated/metadata/global.php as `Foo::__set_state()`, which doesn't exist, and then every
 * request and every bin/magento command fails. Use `?Foo $foo = null` and
 * `$this->foo = $foo ?? new Foo()` instead. Enum cases are fine: they compile as `Enum::Case`.
 */
final class ConstructorDefaultsTest extends TestCase
{
    public function testNoCoreConstructorHasAnObjectAsADefaultValue(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->coreClasses() as $class) {
            $offenders = [...$offenders, ...$this->objectDefaults(new ReflectionClass($class))];
            $checked++;
        }

        self::assertGreaterThan(0, $checked, 'no classes found under core/src');
        self::assertSame([], $offenders, "Constructor parameters with an object as default value (breaks Magento's di:compile):\n" . implode("\n", $offenders));
    }

    public function testTheGuardDetectsAnObjectDefault(): void
    {
        $bad = new class () {
            public function __construct(public readonly \stdClass $value = new \stdClass())
            {
            }
        };

        self::assertCount(1, $this->objectDefaults(new ReflectionClass($bad)));
    }

    /**
     * @return list<string>
     */
    private function objectDefaults(ReflectionClass $class): array
    {
        $constructor = $class->getConstructor();
        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $class->getName()) {
            return [];
        }

        $offenders = [];
        foreach ($constructor->getParameters() as $parameter) {
            if (!$parameter->isDefaultValueAvailable()) {
                continue;
            }

            $default = $parameter->getDefaultValue();
            if (is_object($default) && !$default instanceof UnitEnum) {
                $offenders[] = sprintf('%s::__construct() $%s', $class->getName(), $parameter->getName());
            }
        }

        return $offenders;
    }

    /**
     * Every class, interface, trait and enum under core/src, by PSR-4 (Tudorsync\Core\ => src/).
     *
     * @return list<class-string>
     */
    private function coreClasses(): array
    {
        $srcDir = dirname(__DIR__) . '/src';
        $classes = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($srcDir) + 1, -4);
            $class = 'Tudorsync\\Core\\' . str_replace('/', '\\', $relative);

            self::assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class),
                $class . ' not autoloadable from ' . $file->getPathname(),
            );
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
