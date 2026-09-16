<?php
declare(strict_types=1);

if ( ! class_exists('wpdb') ) {
    class wpdb {
        public string $options = 'wp_options';
        public int $num_queries = 0;

        public function update(
            string $table,
            array $data,
            array $where,
            array|string|null $format = null,
            array|string|null $whereFormat = null
        ): int|false {
            if ( $table !== $this->options ) {
                return false;
            }

            $name = $where['option_name'] ?? null;
            $expectedValue = $where['option_value'] ?? null;
            $nextValue = $data['option_value'] ?? null;
            if (
                ! is_string($name) ||
                ! is_string($expectedValue) ||
                ! is_string($nextValue) ||
                ! array_key_exists($name, $GLOBALS['wddtf_test_options'])
            ) {
                return 0;
            }

            $actualValue = maybe_serialize($GLOBALS['wddtf_test_options'][$name]['value']);
            if ( ! hash_equals($expectedValue, $actualValue) ) {
                return 0;
            }

            $decoded = @unserialize($nextValue, ['allowed_classes' => false]);
            $GLOBALS['wddtf_test_options'][$name]['value'] = false === $decoded && 'b:0;' !== $nextValue
                ? $nextValue
                : $decoded;
            return 1;
        }

        public function delete(string $table, array $where, array|string|null $whereFormat = null): int|false {
            if ( $table !== $this->options ) {
                return false;
            }

            $name = $where['option_name'] ?? null;
            $expectedValue = $where['option_value'] ?? null;
            if ( ! is_string($name) || ! array_key_exists($name, $GLOBALS['wddtf_test_options']) ) {
                return 0;
            }

            $actualValue = maybe_serialize($GLOBALS['wddtf_test_options'][$name]['value']);
            if ( ! is_string($expectedValue) || ! hash_equals($expectedValue, $actualValue) ) {
                return 0;
            }

            unset($GLOBALS['wddtf_test_options'][$name]);
            return 1;
        }
    }
}

require_once __DIR__ . '/bootstrap.php';
