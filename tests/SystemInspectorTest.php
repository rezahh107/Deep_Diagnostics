<?php
declare(strict_types=1);

namespace WDDTF\Collectors {
    function get_bloginfo(string $show = ''): string {
        return '6.5-test';
    }

    function wp_load_alloptions(): array {
        return $GLOBALS['wddtf_system_inspector_options'] ?? [];
    }

    function maybe_serialize(mixed $value): mixed {
        if ( is_array($value) || is_object($value) ) {
            return serialize($value);
        }

        return $value;
    }
}

namespace WDDTF\Tests {
    use PHPUnit\Framework\TestCase;
    use WDDTF\Collectors\SystemInspector;

    final class SystemInspectorTest extends TestCase {
        protected function tearDown(): void {
            unset($GLOBALS['wddtf_system_inspector_options'], $GLOBALS['wpdb']);
        }

        public function testAutoloadSizeAcceptsMaybeUnserializedScalarOptions(): void {
            $GLOBALS['wddtf_system_inspector_options'] = [
                'integer_option' => 1789485370,
                'array_option'   => ['key' => 'value'],
                'false_option'   => false,
            ];

            $snapshot = (new SystemInspector())->snapshot();

            self::assertSame(3, $snapshot['autoload_count']);
            self::assertSame(
                strlen('1789485370') + strlen(serialize(['key' => 'value'])),
                $snapshot['autoload_size']
            );
        }
    }
}
